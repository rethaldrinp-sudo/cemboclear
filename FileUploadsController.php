<?php
declare(strict_types=1);

namespace App\Features\FileUploads;

use App\Core\Database;
use App\Core\Response;
use App\Core\Auth;
use App\Core\FileStorage;

class FileUploadsController
{
    private Database $db;

    public function __construct()
    {
        $this->db = new Database();
    }

    public function upload(): void
    {
        Auth::requireRole();

        if (empty($_FILES['file'])) {
            Response::error('No file uploaded.', 422);
        }

        $file = $_FILES['file'];
        $kind = $_POST['kind'] ?? 'supporting_document';
        $requestId = !empty($_POST['request_id']) ? (int)$_POST['request_id'] : null;
        $residentId = !empty($_POST['resident_id']) ? (int)$_POST['resident_id'] : null;

        if ($requestId === null && $residentId === null && Auth::type() === 'resident') {
            $residentId = Auth::id();
        }

        if ($requestId === null && $residentId === null) {
            Response::error('Either request_id or resident_id must be provided.', 422);
        }

        $validKinds = ['signature', 'valid_id', 'supporting_document'];
        if (!in_array($kind, $validKinds, true)) {
            Response::error('Invalid attachment kind.', 422);
        }

        if ($requestId !== null) {
            if (Auth::type() === 'resident') {
                $owned = $this->db->query(
                    'SELECT id FROM requests WHERE id = ? AND resident_id = ?',
                    [$requestId, Auth::id()]
                )->fetch();
                if (!$owned) {
                    Response::error('The referenced request could not be found for your account.', 404);
                }

                if ($residentId === null) {
                    $residentId = Auth::id();
                }
            } else {
                $exists = $this->db->query('SELECT id FROM requests WHERE id = ?', [$requestId])->fetch();
                if (!$exists) {
                    Response::error('The referenced request could not be found.', 404);
                }
            }
        }

        if ((int)($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            $errCode = (int)$file['error'];
            if ($errCode === UPLOAD_ERR_INI_SIZE || $errCode === UPLOAD_ERR_FORM_SIZE) {
                Response::error('File too large for the server to accept. Max: 5MB.', 422);
            }
            Response::error('The upload did not complete successfully. Please try again.', 422);
        }

        $this->db->beginTransaction();
        $storedPath = null;
        try {
            $stored = FileStorage::store($file);
            $storedPath = $stored['file_path'];

            $this->db->execute(
                'INSERT INTO attachments (request_id, resident_id, kind, file_name, file_path)
                 VALUES (?, ?, ?, ?, ?)',
                [$requestId, $residentId, $kind, $stored['file_name'], $stored['file_path']]
            );
            $newId = (int)$this->db->lastInsertId();

            $this->db->commit();
        } catch (\RuntimeException $e) {
            $this->db->rollBack();
            if ($storedPath !== null) {
                $full = storage_path('uploads/' . $storedPath);
                if (is_file($full)) {
                    @unlink($full);
                }
            }
            $msg = $e->getMessage();
            $status = ($msg === 'Failed to save file.') ? 500 : 422;
            Response::error($msg, $status);
        } catch (\Throwable $e) {
            $this->db->rollBack();
            if ($storedPath !== null) {
                $full = storage_path('uploads/' . $storedPath);
                if (is_file($full)) {
                    @unlink($full);
                }
            }
            Response::error('The attachment could not be saved to the database. Please try again.', 500);
        }

        Response::json([
            'message'   => 'File uploaded',
            'id'        => $newId,
            'file_name' => $stored['file_name'],
            'kind'      => $kind,
        ], 201);
    }
}
