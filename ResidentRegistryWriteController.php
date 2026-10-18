<?php
declare(strict_types=1);

namespace App\Features\ResidentRegistryWrite;

use App\Core\Database;
use App\Core\Response;
use App\Core\Auth;
use App\Core\Logger;
use App\Core\PhoneNormalizer;
use App\Core\NotificationService;
use App\Core\ValidationRules;

class ResidentRegistryWriteController
{
    private Database $db;

    public function __construct()
    {
        $this->db = new Database();
    }

    public function update(string $id): void
    {
        Auth::requireRole('any');

        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $residentId = (int)$id;

        if (Auth::type() === 'resident' && Auth::id() !== $residentId) {
            Response::forbidden();
        }

        $existing = $this->db->query('SELECT id FROM residents WHERE id = ?', [$residentId])->fetch();
        if (!$existing) {
            Response::notFound('Resident not found');
        }

        if (Auth::type() === 'resident') {
            unset($input['control_no'], $input['registry_status']);
        }

        if (array_key_exists('email', $input)) {
            $email = strtolower(trim((string)$input['email']));
            if ($email === '') {
                Response::error('Email cannot be empty.', 422);
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                Response::error('Invalid email format.', 422);
            }
            $dup = $this->db->query(
                'SELECT id FROM residents WHERE email = ? AND id <> ? UNION SELECT id FROM staff WHERE email = ?',
                [$email, $residentId, $email]
            )->fetch();
            if ($dup) {
                Response::error('Email is already registered.', 409);
            }
            $input['email'] = $email;
        }

        if (array_key_exists('phone', $input) && $input['phone'] !== null) {
            $phoneRaw = trim((string)$input['phone']);
            if ($phoneRaw === '') {
                Response::error('Phone number cannot be empty.', 422);
            }
            if (!PhoneNormalizer::isValid($phoneRaw)) {
                Response::error('Please enter a valid Philippine mobile number (e.g. 09171234567 or +639171234567).', 422);
            }
            $normalizedPhone = PhoneNormalizer::toNational($phoneRaw);
            $variations = PhoneNormalizer::getVariations($phoneRaw);
            $placeholders = implode(',', array_fill(0, count($variations), '?'));
            $params = array_merge($variations, [$residentId]);
            $dupPhone = $this->db->query(
                "SELECT id FROM residents WHERE phone IN ({$placeholders}) AND id <> ?",
                $params
            )->fetch();
            if ($dupPhone) {
                Response::error('Phone number is already registered.', 409);
            }
            $input['phone'] = $normalizedPhone;
        }

        if (array_key_exists('birthdate', $input) && $input['birthdate'] !== null) {
            $birthdate = trim((string)$input['birthdate']);
            $d = \DateTime::createFromFormat('Y-m-d', $birthdate);
            if (!($d && $d->format('Y-m-d') === $birthdate)) {
                Response::error('Birthdate must be a valid date in YYYY-MM-DD format.', 422);
            }
            $now = new \DateTime('today');
            if ($d > $now) {
                Response::error('Birthdate cannot be in the future.', 422);
            } elseif ($d < new \DateTime(ValidationRules::MIN_BIRTH_YEAR . '-01-01')) {
                Response::error('Birthdate looks unreasonably old (must be on or after ' . ValidationRules::MIN_BIRTH_YEAR . ').', 422);
            } elseif (!ValidationRules::meetsMinimumAge($d)) {
                Response::error(ValidationRules::minimumAgeMessage('Residents'), 422);
            }
            $input['birthdate'] = $birthdate;
        }

        if (array_key_exists('gender', $input) && $input['gender'] !== null) {
            $gender = strtolower(trim((string)$input['gender']));
            if (!in_array($gender, ['male', 'female', 'other'], true)) {
                Response::error('Gender must be "male", "female", or "other".', 422);
            }
            $input['gender'] = $gender;
        }

        $profileErrors = ResidentProfileValidator::validate($input);
        if (!empty($profileErrors)) {
            Response::error(implode(' ', $profileErrors), 422);
        }

        $allowed = [
            'first_name', 'middle_name', 'last_name', 'suffix', 'email', 'phone',
            'gender', 'birthdate', 'civil_status', 'birth_place', 'address',
            'citizenship', 'control_no', 'registry_status',
        ];

        $sets = [];
        $values = [];
        foreach ($allowed as $field) {
            if (array_key_exists($field, $input)) {
                $sets[] = "{$field} = ?";
                $values[] = is_string($input[$field]) ? trim((string)$input[$field]) : $input[$field];
            }
        }

        if (empty($sets)) {
            Response::error('No valid fields to update.', 422);
        }

        $values[] = $residentId;
        $this->db->execute(
            'UPDATE residents SET ' . implode(', ', $sets) . ' WHERE id = ?',
            $values
        );

        (new Logger($this->db))->activity(
            'Updated resident #' . $residentId,
            Auth::id()
        );

        Response::json(['message' => 'Resident updated']);
    }

    public function verify(string $id): void
    {
        Auth::requireRole('staff');

        $residentId = (int)$id;
        $existing = $this->db->query('SELECT id FROM residents WHERE id = ?', [$residentId])->fetch();
        if (!$existing) {
            Response::notFound('Resident not found');
        }

        $this->db->execute(
            "UPDATE residents SET is_verified = 1, registry_status = 'verified' WHERE id = ?",
            [$residentId]
        );

        (new Logger($this->db))->activity(
            'Verified resident #' . $residentId,
            Auth::id()
        );

        (new NotificationService($this->db))->residentVerified($residentId);

        Response::json(['message' => 'Resident verified']);
    }

    public function updateStatus(string $id): void
    {
        Auth::requireRole('staff');

        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $status = $input['status'] ?? '';

        if (!in_array($status, ['active', 'inactive'], true)) {
            Response::error('Status must be "active" or "inactive".', 422);
        }

        $residentId = (int)$id;
        $existing = $this->db->query('SELECT id FROM residents WHERE id = ?', [$residentId])->fetch();
        if (!$existing) {
            Response::notFound('Resident not found');
        }

        $this->db->execute(
            'UPDATE residents SET account_status = ? WHERE id = ?',
            [$status, $residentId]
        );

        (new Logger($this->db))->activity(
            'Set resident #' . $residentId . ' status to ' . $status,
            Auth::id()
        );

        Response::json(['message' => 'Status updated']);
    }
}
