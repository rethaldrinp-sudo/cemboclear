<?php

declare(strict_types=1);

namespace App\Core;

class NotificationService
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function notifyStaff(int $staffId, string $message): void
    {
        $this->guard(function () use ($staffId, $message) {
            $this->insert($staffId, null, $this->fit($message));
        });
    }

    public function notifyResident(int $residentId, string $message): void
    {
        $this->guard(function () use ($residentId, $message) {
            $this->insert(null, $residentId, $this->fit($message));
        });
    }

    public function notifyAdmins(string $message): void
    {
        $this->guard(function () use ($message) {
            $admins = $this->db->query(
                "SELECT id FROM staff
                 WHERE status = 'active' AND LOWER(position) LIKE '%admin%'"
            )->fetchAll();

            foreach ($admins as $admin) {
                $this->insert((int)$admin['id'], null, $this->fit($message));
            }
        });
    }

    public function notifyMailReceived(array $sender, array $recipient, string $senderName): void
    {
        $this->guard(function () use ($sender, $recipient, $senderName) {
            if ($sender['staff_id'] !== null && $sender['staff_id'] === $recipient['staff_id']) {
                return;
            }
            if ($sender['resident_id'] !== null && $sender['resident_id'] === $recipient['resident_id']) {
                return;
            }

            $message = $this->fit('New message from ' . trim($senderName));

            if ($recipient['staff_id'] !== null) {
                $this->insert((int)$recipient['staff_id'], null, $message);
            } elseif ($recipient['resident_id'] !== null) {
                $this->insert(null, (int)$recipient['resident_id'], $message);
            }
        });
    }

    public function certificateApplied(int $residentId): void
    {
        $name = $this->resolveResidentName($residentId);
        $this->notifyAdmins('New certificate application from ' . $name);
    }

    public function certificateApproved(int $residentId, int $certId): void
    {
        $this->notifyResident($residentId, 'Your certificate (#CERT-' . $certId . ') has been approved');
    }

    public function certificateRejected(int $residentId, int $certId): void
    {
        $this->notifyResident($residentId, 'Your certificate (#CERT-' . $certId . ') was rejected');
    }

    public function residentVerified(int $residentId): void
    {
        $this->notifyResident($residentId, 'Your account has been verified');
    }

    public function residentSignedUp(int $residentId): void
    {
        $name = $this->resolveResidentName($residentId);
        $this->notifyAdmins('New resident account pending verification: ' . $name);
    }

    public function requestSubmitted(int $residentId): void
    {
        $name = $this->resolveResidentName($residentId);
        $this->notifyAdmins('New request submitted by ' . $name);
    }

    public function requestStatusChanged(int $residentId, string $status): void
    {
        $this->notifyResident($residentId, 'Your request status is now ' . $status);
    }

    public function appointmentBooked(int $residentId, string $date, string $slot): void
    {
        $this->notifyResident($residentId, 'Your appointment on ' . $date . ' at ' . $slot . ' is confirmed');
    }

    public function appointmentCancelled(int $residentId, string $date): void
    {
        $this->notifyResident($residentId, 'Your appointment on ' . $date . ' was cancelled');
    }

    public function appointmentCancelledByStaff(int $residentId, string $date, string $slot, string $reason): void
    {
        $this->notifyResident(
            $residentId,
            'Your appointment on ' . $date . ' at ' . $slot . ' was cancelled by the office. Reason: ' . $reason
        );
    }

    public function transactionRecorded(int $residentId): void
    {
        $this->notifyResident($residentId, 'A new transaction was recorded on your account');
    }

    private function insert(?int $staffId, ?int $residentId, string $message): void
    {
        if (($staffId === null) === ($residentId === null)) {
            return;
        }

        $this->db->execute(
            'INSERT INTO notifications (staff_id, resident_id, message) VALUES (?, ?, ?)',
            [$staffId, $residentId, $message]
        );
    }

    private function guard(callable $fn): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            error_log('NotificationService failure: ' . $e->getMessage());
        }
    }

    private function resolveResidentName(int $residentId): string
    {
        try {
            $row = $this->db->query(
                'SELECT first_name, last_name FROM residents WHERE id = ?',
                [$residentId]
            )->fetch();
            if ($row) {
                $name = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
                if ($name !== '') {
                    return $name;
                }
            }
        } catch (\Throwable $e) {
            error_log('NotificationService::resolveResidentName failed: ' . $e->getMessage());
        }
        return 'A resident';
    }

    private function fit(string $message): string
    {
        if (mb_strlen($message) <= 255) {
            return $message;
        }
        return mb_substr($message, 0, 252) . '...';
    }
}
