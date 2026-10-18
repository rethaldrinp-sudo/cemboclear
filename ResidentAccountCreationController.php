<?php
declare(strict_types=1);

namespace App\Features\ResidentAccountCreation;

use App\Core\Database;
use App\Core\Response;
use App\Core\Auth;
use App\Core\RateLimiter;
use App\Core\PhoneNormalizer;
use App\Core\Logger;
use App\Core\NotificationService;
use App\Features\StaffAuthentication\StaffValidator;

class ResidentAccountCreationController
{
    private Database $db;

    public function __construct()
    {
        $this->db = new Database();
    }

    public function signup(): void
    {
        $input = json_decode(file_get_contents('php://input'), true) ?? [];

        if (isset($input['email']) && is_string($input['email'])) {
            $input['email'] = strtolower(trim($input['email']));
        }
        foreach (['first_name', 'middle_name', 'last_name'] as $nameField) {
            if (isset($input[$nameField]) && is_string($input[$nameField])) {
                $input[$nameField] = trim($input[$nameField]);
            }
        }

        $accountType = strtolower(trim((string)($input['account_type'] ?? 'resident')));

        if ($accountType === 'admin') {
            $staffErrors = StaffValidator::validate($input);
            if (!empty($staffErrors)) {
                Response::error(implode(' ', $staffErrors), 422);
            }
        } else {
            $errors = SignupValidator::validate($input);
            if (!empty($errors)) {
                Response::error(implode(' ', $errors), 422);
            }
        }

        // Count only attempts that pass validation, so typos don't burn the limit
        RateLimiter::check('signup', 10, 300);

        $email = $input['email'];
        $password = $input['password'];

        $existing = $this->db->query(
            'SELECT id FROM staff WHERE email = ? UNION SELECT id FROM residents WHERE email = ?',
            [$email, $email]
        )->fetch();
        if ($existing) {
            Response::error('Email is already registered.', 409);
        }

        $controlNo = null;
        for ($i = 0; $i < 10; $i++) {
            $candidate = generate_control_no();
            $cCheck = $this->db->query(
                'SELECT id FROM residents WHERE control_no = ? UNION SELECT id FROM staff WHERE control_no = ?',
                [$candidate, $candidate]
            )->fetch();
            if (!$cCheck) {
                $controlNo = $candidate;
                break;
            }
        }

        if ($accountType === 'admin') {
            $position = trim((string)($input['position'] ?? ''));
            $rawPhone = trim((string)($input['phone'] ?? ''));
            $normalizedPhone = $rawPhone !== '' ? PhoneNormalizer::toNational($rawPhone) : null;

            $this->db->execute(
                'INSERT INTO staff (
                    email, password_hash, first_name, middle_name, last_name, suffix,
                    gender, civil_status, birth_place, address, citizenship,
                    control_no, registry_status, position, branch, phone, birthdate, status
                 ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $email,
                    password_hash($password, PASSWORD_DEFAULT),
                    $input['first_name'],
                    $input['middle_name'] ?? null,
                    $input['last_name'],
                    $input['suffix'] ?? null,
                    $input['gender'] ?? null,
                    $input['civil_status'] ?? null,
                    $input['birth_place'] ?? null,
                    $input['address'] ?? null,
                    $input['citizenship'] ?? null,
                    $controlNo,
                    $input['registry_status'] ?? 'pending',
                    $position,
                    $input['branch'] ?? null,
                    $normalizedPhone,
                    ($input['birthdate'] ?? null) !== '' ? ($input['birthdate'] ?? null) : null,
                    'active',
                ]
            );

            $staffId = (int)$this->db->lastInsertId();
            (new Logger($this->db))->log('Self-signup staff account created: ' . $email, 'unauthorized', null);

            Response::json(['message' => 'Admin account created', 'id' => $staffId, 'control_no' => $controlNo], 201);
        }

        $rawPhone = trim((string)($input['phone'] ?? ''));
        $normalizedPhone = PhoneNormalizer::toNational($rawPhone);

        $phoneVariations = PhoneNormalizer::getVariations($rawPhone);
        if (!empty($phoneVariations)) {
            $phoneIn = implode(',', array_fill(0, count($phoneVariations), '?'));
            $phoneExists = $this->db->query(
                'SELECT id FROM residents WHERE phone IN (' . $phoneIn . ') LIMIT 1',
                $phoneVariations
            )->fetch();
            if ($phoneExists) {
                Response::error('This phone number is already registered.', 409);
            }
        }

        $this->db->execute(
            'INSERT INTO residents (email, password_hash, first_name, middle_name, last_name, suffix, phone, gender, birthdate, civil_status, birth_place, address, citizenship, control_no)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $email,
                password_hash($password, PASSWORD_DEFAULT),
                $input['first_name'],
                $input['middle_name'] ?? null,
                $input['last_name'],
                $input['suffix'] ?? null,
                $normalizedPhone,
                strtolower(trim($input['gender'] ?? '')),
                $input['birthdate'] ?? null,
                $input['civil_status'] ?? null,
                $input['birth_place'] ?? null,
                $input['address'] ?? null,
                $input['citizenship'] ?? null,
                $controlNo,
            ]
        );

        $newResidentId = (int)$this->db->lastInsertId();
        (new NotificationService($this->db))->residentSignedUp($newResidentId);

        Response::json(['message' => 'Account created', 'id' => $newResidentId, 'control_no' => $controlNo], 201);
    }

    public static function buildRequestsReadModel(Database $db, ?int $residentId = null): array
    {
        if ($residentId === null) {
            $requests = $db->query(
                'SELECT r.id, r.ticket_id, r.resident_id, r.subject, r.details, r.status, r.created_at,
                        CONCAT(res.first_name, " ", res.last_name) AS resident_name,
                        res.control_no,
                        a.name AS agency_name,
                        rt.name AS request_type
                 FROM requests r
                 JOIN residents res ON res.id = r.resident_id
                 JOIN agencies a ON a.id = r.agency_id
                 LEFT JOIN request_types rt ON rt.id = r.request_type_id
                 ORDER BY r.created_at DESC'
            )->fetchAll();
            foreach ($requests as &$req) {
                $req['id'] = (int)$req['id'];
                $req['resident_id'] = (int)$req['resident_id'];
            }
        } else {
            $requests = $db->query(
                'SELECT r.id, r.ticket_id, r.subject, r.details, r.status, r.created_at,
                        a.name as agency_name
                 FROM requests r
                 JOIN agencies a ON a.id = r.agency_id
                 WHERE r.resident_id = ?
                 ORDER BY r.created_at DESC',
                [$residentId]
            )->fetchAll();
            foreach ($requests as &$req) {
                $req['id'] = (int)$req['id'];
            }
        }

        if (!empty($requests)) {
            $reqIds = array_column($requests, 'id');
            $placeholders = implode(',', array_fill(0, count($reqIds), '?'));
            $attachments = $db->query(
                "SELECT id, request_id, kind, file_name
                 FROM attachments
                 WHERE request_id IN ({$placeholders})
                 ORDER BY created_at DESC",
                $reqIds
            )->fetchAll();

            $attachmentsByReq = [];
            foreach ($attachments as $att) {
                $rId = (int)$att['request_id'];
                if (!isset($attachmentsByReq[$rId])) {
                    $attachmentsByReq[$rId] = [];
                }
                $attachmentsByReq[$rId][] = [
                    'id' => (int)$att['id'],
                    'kind' => $att['kind'],
                    'file_name' => $att['file_name'],
                ];
            }

            foreach ($requests as &$req) {
                $req['attachments'] = $attachmentsByReq[$req['id']] ?? [];
            }
        } else {
            foreach ($requests as &$req) {
                $req['attachments'] = [];
            }
        }

        return ['data' => $requests];
    }

    public function myRequests(): void
    {
        Auth::requireRole();

        $residentId = Auth::type() === 'staff' ? null : Auth::id();
        $res = self::buildRequestsReadModel($this->db, $residentId);

        Response::json($res);
    }

    public function submitRequest(): void
    {
        Auth::requireRole('resident');
        RateLimiter::check('submit_request', 10, 60);

        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $agencyId = (int)($input['agency_id'] ?? 0);

        if ($agencyId <= 0) {
            Response::error('Agency is required.', 422);
        }

        $agency = $this->db->query('SELECT id FROM agencies WHERE id = ?', [$agencyId])->fetch();
        if (!$agency) {
            Response::error('Invalid agency.', 422);
        }

        $ticketId = generate_ticket_id();

        while ($this->db->query('SELECT id FROM requests WHERE ticket_id = ?', [$ticketId])->fetch()) {
            $ticketId = generate_ticket_id();
        }

        $this->db->execute(
            'INSERT INTO requests (ticket_id, resident_id, agency_id, request_type_id, subject, details)
             VALUES (?, ?, ?, ?, ?, ?)',
            [
                $ticketId,
                Auth::id(),
                $agencyId,
                $input['request_type_id'] ?? null,
                $input['subject'] ?? null,
                $input['details'] ?? null,
            ]
        );

        $newRequestId = (int)$this->db->lastInsertId();

        (new NotificationService($this->db))->requestSubmitted((int)Auth::id());

        Response::json([
            'message'   => 'Request submitted',
            'ticket_id' => $ticketId,
            'id'        => $newRequestId,
        ], 201);
    }

    public function updateStatus(string $id): void
    {
        Auth::requireRole('staff');

        $requestId = (int)$id;
        $existing = $this->db->query('SELECT id FROM requests WHERE id = ?', [$requestId])->fetch();
        if (!$existing) {
            Response::notFound('Request not found');
        }

        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $status = $input['status'] ?? '';
        $allowed = ['pending_review', 'reviewed', 'resolved', 'closed'];
        if (!in_array($status, $allowed, true)) {
            Response::error('Status must be one of: ' . implode(', ', $allowed), 422);
        }

        $this->db->execute(
            'UPDATE requests SET status = ? WHERE id = ?',
            [$status, $requestId]
        );

        (new Logger($this->db))->activity(
            'Set request #' . $requestId . ' status to ' . $status,
            Auth::id()
        );

        $reqOwner = $this->db->query(
            'SELECT resident_id FROM requests WHERE id = ?',
            [$requestId]
        )->fetch();
        if ($reqOwner) {
            (new NotificationService($this->db))->requestStatusChanged((int)$reqOwner['resident_id'], $status);
        }

        Response::json(['message' => 'Request status updated']);
    }

}
