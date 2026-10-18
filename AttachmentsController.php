<?php

declare(strict_types=1);

namespace App\Features\Attachments;

use App\Core\Database;
use App\Core\Response;
use App\Core\Auth;

class AttachmentsController
{
    private Database $db;

    public function __construct()
    {
        $this->db = new Database();
    }

    public function download(string $id): void
    {
        Auth::requireRole();

        $attachmentId = (int)$id;
        $attachment = $this->findAccessible($attachmentId);

        if (!$attachment) {
            Response::notFound('Attachment not found');
        }

        $fullPath = storage_path('uploads/' . $attachment['file_path']);
        if (!file_exists($fullPath)) {
            Response::notFound('File not found on disk');
        }

        $this->stream($fullPath, $attachment['file_name']);
    }

    private function findAccessible(int $attachmentId): ?array
    {
        $row = $this->db->query(
            'SELECT file_name, file_path, kind, request_id, resident_id, mail_id
             FROM attachments WHERE id = ?',
            [$attachmentId]
        )->fetch();

        if (!$row) {
            return null;
        }

        if ($row['mail_id'] !== null) {
            if (!$this->canAccessMailAttachment((int)$row['mail_id'])) {
                return null;
            }
            return $row;
        }

        if (Auth::type() === 'staff') {
            return $row;
        }

        $userId = Auth::id();
        $allowed = $this->db->query(
            'SELECT a.id
             FROM attachments a
             LEFT JOIN requests r ON a.request_id = r.id
             WHERE a.id = ? AND (a.resident_id = ? OR r.resident_id = ?)',
            [$attachmentId, $userId, $userId]
        )->fetch();

        return $allowed ? $row : null;
    }

    private function canAccessMailAttachment(int $mailId): bool
    {
        $userId = Auth::id();
        if ($userId === null) {
            return false;
        }
        $isStaff = Auth::type() === 'staff';

        $senderColumn    = $isStaff ? 'sender_staff_id'    : 'sender_resident_id';
        $recipientColumn = $isStaff ? 'recipient_staff_id' : 'recipient_resident_id';

        $match = $this->db->query(
            "SELECT id FROM mail
             WHERE id = ? AND ({$senderColumn} = ? OR {$recipientColumn} = ?)",
            [$mailId, $userId, $userId]
        )->fetch();

        return (bool)$match;
    }

    private function stream(string $fullPath, string $originalName): void
    {
        $inline = !empty($_GET['inline']);
        if ($inline) {
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($fullPath);
            if ($mime && strpos($mime, 'image/') === 0) {
                header('Content-Type: ' . $mime);
                header('Content-Disposition: inline; filename="' . $this->cleanName($originalName) . '"');
                header('Content-Length: ' . filesize($fullPath));
                header('Cache-Control: private, max-age=3600');
                readfile($fullPath);
                exit;
            }
        }

        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $this->cleanName($originalName) . '"');
        header('Content-Length: ' . filesize($fullPath));
        readfile($fullPath);
        exit;
    }

    private function cleanName(string $name): string
    {
        $clean = preg_replace('/[\r\n"\\\\]/', '', basename($name));
        return ($clean === '' || $clean === null) ? 'download' : $clean;
    }
}
