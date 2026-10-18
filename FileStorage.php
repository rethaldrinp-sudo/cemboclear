<?php

declare(strict_types=1);

namespace App\Core;

class FileStorage
{
    public static function store(array $file): array
    {
        if (empty($file) || !isset($file['tmp_name'])) {
            throw new \RuntimeException('No file uploaded.');
        }

        $maxSize = config('upload.max_size', 5 * 1024 * 1024);
        if ((int)($file['size'] ?? 0) > $maxSize) {
            throw new \RuntimeException('File too large. Max: 5MB.');
        }

        $allowedTypes = config(
            'upload.allowed_types',
            ['image/jpeg', 'image/png', 'image/webp', 'application/pdf']
        );
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($file['tmp_name']);
        if (!in_array($mimeType, $allowedTypes, true)) {
            throw new \RuntimeException('File type not allowed: ' . $mimeType);
        }

        $rawOriginalName = basename((string)($file['name'] ?? 'upload'));
        $safeOriginalName = preg_replace('/[^\w\.\-]/', '_', $rawOriginalName);
        if ($safeOriginalName === '' || $safeOriginalName === null) {
            $safeOriginalName = 'upload';
        }

        $extMap = [
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp',
            'application/pdf' => 'pdf',
        ];
        $safeExt = $extMap[$mimeType] ?? 'bin';

        $safeName = bin2hex(random_bytes(16)) . '.' . $safeExt;
        $uploadDir = storage_path('uploads');

        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $dest = $uploadDir . '/' . $safeName;
        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            throw new \RuntimeException('Failed to save file.');
        }

        return [
            'file_name' => $safeOriginalName,
            'file_path' => $safeName,
        ];
    }
}
