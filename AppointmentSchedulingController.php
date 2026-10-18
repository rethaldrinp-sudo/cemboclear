<?php
declare(strict_types=1);

namespace App\Features\AppointmentScheduling;

use App\Core\Database;
use App\Core\Response;
use App\Core\Auth;
use App\Core\NotificationService;
use App\Core\AppointmentConfig;

class AppointmentSchedulingController
{
    private Database $db;

    public function __construct()
    {
        $this->db = new Database();
    }

    public function availableSlots(): void
    {
        Auth::requireRole('any');

        $date = $_GET['date'] ?? '';
        if ($date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            Response::error('Valid date parameter is required (YYYY-MM-DD).', 422);
        }

        $isWeekend = AppointmentConfig::isWeekend($date);
        $now       = AppointmentConfig::now();
        $cutoff    = $now->modify('+' . AppointmentConfig::BUFFER_MINUTES . ' minutes');

        $rows = $this->db->query(
            "SELECT aa.time_slot, aa.capacity,
                    (SELECT COUNT(*) FROM appointments a
                      WHERE a.appt_date = aa.available_date
                        AND a.time_slot = aa.time_slot
                        AND a.status = 'booked') AS booked
             FROM appointment_availability aa
             WHERE aa.available_date = ? AND aa.is_open = 1
             ORDER BY aa.id",
            [$date]
        )->fetchAll();

        $available = [];
        foreach ($rows as $row) {
            $slot       = $row['time_slot'];
            $capacity   = (int)$row['capacity'];
            $booked     = (int)$row['booked'];
            $startHour  = AppointmentConfig::startHourFor($slot);

            $alreadyStarted = false;
            if ($startHour !== null) {
                $slotStart      = AppointmentConfig::slotStart($date, $startHour);
                $alreadyStarted = ($slotStart <= $cutoff);
            }

            $hasCapacity = ($booked < $capacity);

            $available[] = [
                'time_slot' => $slot,
                'available' => $hasCapacity && !$alreadyStarted,
                'expired'   => (bool)$alreadyStarted,
            ];
        }

        Response::json([
            'date'       => $date,
            'is_weekend' => $isWeekend,
            'slots'      => $available,
        ]);
    }

    public function book(): void
    {
        Auth::requireRole('resident');

        $input    = json_decode(file_get_contents('php://input'), true) ?? [];
        $date     = $input['date'] ?? '';
        $timeSlot = $input['time_slot'] ?? '';

        if ($date === '' || $timeSlot === '') {
            Response::error('Date and time_slot are required.', 422);
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            Response::error('Valid date is required (YYYY-MM-DD).', 422);
        }

        if (!AppointmentConfig::isValidSlot($timeSlot)) {
            Response::error('Invalid time slot.', 422);
        }

        $this->db->beginTransaction();

        try {
            $now       = AppointmentConfig::now();
            $cutoff    = $now->modify('+' . AppointmentConfig::BUFFER_MINUTES . ' minutes');
            $startHour = AppointmentConfig::startHourFor($timeSlot);

            if ($startHour !== null) {
                $slotStart = AppointmentConfig::slotStart($date, $startHour);
                if ($slotStart <= $cutoff) {
                    $this->db->rollBack();
                    Response::error('This time slot has already started and can no longer be booked.', 422);
                }
            }

            $avail = $this->db->query(
                "SELECT capacity, is_open FROM appointment_availability
                 WHERE available_date = ? AND time_slot = ?
                 FOR UPDATE",
                [$date, $timeSlot]
            )->fetch();

            if (!$avail || (int)$avail['is_open'] !== 1) {
                $this->db->rollBack();
                Response::error('This time slot is not available.', 422);
            }

            $capacity = (int)$avail['capacity'];

            $bookedRow = $this->db->query(
                "SELECT COUNT(*) AS cnt FROM appointments
                 WHERE appt_date = ? AND time_slot = ? AND status = 'booked'",
                [$date, $timeSlot]
            )->fetch();

            $currentBooked = (int)($bookedRow['cnt'] ?? 0);

            if ($currentBooked >= $capacity) {
                $this->db->rollBack();
                Response::error('This time slot is fully booked.', 409);
            }

            $this->db->execute(
                'INSERT INTO appointments (resident_id, appt_date, time_slot) VALUES (?, ?, ?)',
                [Auth::id(), $date, $timeSlot]
            );

            $id = (int)$this->db->lastInsertId();
            $this->db->commit();

            (new NotificationService($this->db))->appointmentBooked((int)Auth::id(), $date, $timeSlot);

            Response::json([
                'message'   => 'Appointment booked',
                'id'        => $id,
                'date'      => $date,
                'time_slot' => $timeSlot,
            ], 201);

        } catch (\Throwable $e) {
            $this->db->rollBack();
            Response::error('Failed to book appointment.', 500);
        }
    }
}

