<?php
declare(strict_types=1);

namespace App\Features\AppointmentAvailability;

use App\Core\Database;
use App\Core\Response;
use App\Core\Auth;
use App\Core\Logger;
use App\Core\NotificationService;
use App\Core\AppointmentConfig;

class AppointmentAvailabilityController
{
    private Database $db;

    public function __construct()
    {
        $this->db = new Database();
    }

    public function index(): void
    {
        Auth::requireRole('any');

        $from = $_GET['from'] ?? '';
        $to   = $_GET['to'] ?? '';

        $nowDate = AppointmentConfig::now()->format('Y-m-d');

        if ($from === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
            $from = $nowDate;
        }

        if ($to === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            $to = AppointmentConfig::now()->modify('+30 days')->format('Y-m-d');
        }

        $rows = $this->db->query(
            "SELECT id, available_date, time_slot, capacity, is_open, created_by, created_at, updated_at
             FROM appointment_availability
             WHERE available_date >= ? AND available_date <= ?
             ORDER BY available_date ASC, id ASC",
            [$from, $to]
        )->fetchAll();

        Response::json(['data' => $rows]);
    }

    public function store(): void
    {
        Auth::requireRole('admin');

        $input    = json_decode(file_get_contents('php://input'), true) ?? [];
        $date     = $input['date'] ?? '';
        $slots    = $input['slots'] ?? [];
        $capacity = isset($input['capacity']) ? (int)$input['capacity'] : 1;

        if ($date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            Response::error('Valid date is required (YYYY-MM-DD).', 422);
        }

        $today = AppointmentConfig::now()->format('Y-m-d');
        if ($date < $today) {
            Response::error('Cannot manage availability for past dates.', 422);
        }

        if (!is_array($slots) || empty($slots)) {
            Response::error('At least one time slot is required.', 422);
        }

        foreach ($slots as $slot) {
            if (!AppointmentConfig::isValidSlot((string)$slot)) {
                Response::error('Invalid time slot: ' . $slot, 422);
            }
        }

        if ($capacity < 1) {
            Response::error('Capacity must be at least 1.', 422);
        }

        $staffId = Auth::id();

        foreach ($slots as $slot) {
            $this->db->execute(
                "INSERT INTO appointment_availability (available_date, time_slot, capacity, is_open, created_by)
                 VALUES (?, ?, ?, 1, ?)
                 ON DUPLICATE KEY UPDATE is_open = 1, capacity = VALUES(capacity)",
                [$date, $slot, $capacity, $staffId]
            );
        }

        (new Logger($this->db))->activity('Opened availability for ' . $date, Auth::id());

        Response::json(['message' => 'Availability saved for ' . $date]);
    }

    public function bulkWeek(): void
    {
        Auth::requireRole('admin');

        $input     = json_decode(file_get_contents('php://input'), true) ?? [];
        $weekStart = $input['week_start'] ?? '';

        if ($weekStart === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $weekStart)) {
            Response::error('Valid week_start date is required (YYYY-MM-DD).', 422);
        }

        $dt = new \DateTimeImmutable($weekStart, new \DateTimeZone(AppointmentConfig::TIMEZONE));
        if ($dt->format('N') !== '1') {
            Response::error('week_start must be a Monday.', 422);
        }

        $staffId   = Auth::id();
        $allSlots  = array_keys(AppointmentConfig::SLOTS);
        $datesOpened = [];

        for ($i = 0; $i < 5; $i++) {
            $currentDate = $dt->modify('+' . $i . ' days')->format('Y-m-d');
            $datesOpened[] = $currentDate;

            foreach ($allSlots as $slot) {
                $this->db->execute(
                    "INSERT INTO appointment_availability (available_date, time_slot, capacity, is_open, created_by)
                     VALUES (?, ?, 1, 1, ?)
                     ON DUPLICATE KEY UPDATE is_open = 1",
                    [$currentDate, $slot, $staffId]
                );
            }
        }

        (new Logger($this->db))->activity('Bulk opened availability for week starting ' . $weekStart, Auth::id());

        Response::json([
            'message'      => 'Bulk availability created for week starting ' . $weekStart,
            'dates_opened' => $datesOpened,
        ]);
    }

    public function update(string $id = '0'): void
    {
        Auth::requireRole('admin');

        $id = (int)$id;
        if ($id <= 0) {
            Response::error('Invalid availability ID.', 422);
        }

        $input = json_decode(file_get_contents('php://input'), true) ?? [];

        $existing = $this->db->query(
            "SELECT id, available_date, time_slot, capacity, is_open FROM appointment_availability WHERE id = ?",
            [$id]
        )->fetch();

        if (!$existing) {
            Response::notFound('Availability record not found.');
        }

        $isOpen   = isset($input['is_open']) ? (int)(bool)$input['is_open'] : (int)$existing['is_open'];
        $capacity = isset($input['capacity']) ? (int)$input['capacity'] : (int)$existing['capacity'];

        if ($capacity < 1) {
            Response::error('Capacity must be at least 1.', 422);
        }

        $this->db->execute(
            "UPDATE appointment_availability SET is_open = ?, capacity = ? WHERE id = ?",
            [$isOpen, $capacity, $id]
        );

        (new Logger($this->db))->activity('Updated availability ID ' . $id, Auth::id());

        Response::json(['message' => 'Availability updated']);
    }

    public function destroy(string $id = '0'): void
    {
        Auth::requireRole('admin');

        $id = (int)$id;
        if ($id <= 0) {
            Response::error('Invalid availability ID.', 422);
        }

        $input  = json_decode(file_get_contents('php://input'), true) ?? [];
        $reason = trim((string)($input['reason'] ?? ''));

        if (mb_strlen($reason) < 3) {
            Response::error('A cancellation reason is required.', 422);
        }

        $this->db->beginTransaction();

        try {
            $row = $this->db->query(
                "SELECT id, available_date, time_slot FROM appointment_availability WHERE id = ? FOR UPDATE",
                [$id]
            )->fetch();

            if (!$row) {
                $this->db->rollBack();
                Response::notFound('Availability record not found.');
            }

            $date = $row['available_date'];
            $slot = $row['time_slot'];

            $affectedBookings = $this->db->query(
                "SELECT id, resident_id FROM appointments
                 WHERE appt_date = ? AND time_slot = ? AND status = 'booked'
                 FOR UPDATE",
                [$date, $slot]
            )->fetchAll();

            $notifier = new NotificationService($this->db);

            foreach ($affectedBookings as $booking) {
                $this->db->execute(
                    "UPDATE appointments SET status = 'cancelled', cancel_reason = ?, cancelled_at = NOW() WHERE id = ?",
                    [$reason, (int)$booking['id']]
                );

                $notifier->appointmentCancelledByStaff((int)$booking['resident_id'], $date, $slot, $reason);
            }

            $this->db->execute("DELETE FROM appointment_availability WHERE id = ?", [$id]);

            $this->db->commit();

            (new Logger($this->db))->activity(
                'Removed availability ' . $date . ' ' . $slot . ' (reason: ' . $reason . ')',
                Auth::id()
            );

            Response::json([
                'message'           => 'Availability removed',
                'affected_bookings' => count($affectedBookings),
            ]);

        } catch (\Throwable $e) {
            $this->db->rollBack();
            Response::error('Failed to remove availability.', 500);
        }
    }
}
