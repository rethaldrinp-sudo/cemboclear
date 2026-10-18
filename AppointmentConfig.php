<?php
declare(strict_types=1);

namespace App\Core;

class AppointmentConfig
{
    public const TIMEZONE       = 'Asia/Manila';
    public const BUFFER_MINUTES = 30;

    public const SLOTS = [
        '8:00 - 9:00'   => 8,
        '9:00 - 10:00'  => 9,
        '10:00 - 11:00' => 10,
        '11:00 - 12:00' => 11,
        '1:00 - 2:00'   => 13,
        '2:00 - 3:00'   => 14,
        '3:00 - 4:00'   => 15,
        '4:00 - 5:00'   => 16,
    ];

    public static function isValidSlot(string $slot): bool
    {
        return array_key_exists($slot, self::SLOTS);
    }

    public static function startHourFor(string $slot): ?int
    {
        return self::SLOTS[$slot] ?? null;
    }

    public static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone(self::TIMEZONE));
    }

    public static function isWeekend(string $date): bool
    {
        $dow = (new \DateTimeImmutable($date))->format('N');
        return $dow === '6' || $dow === '7';
    }

    public static function slotStart(string $date, int $startHour): \DateTimeImmutable
    {
        return new \DateTimeImmutable(
            $date . ' ' . str_pad((string)$startHour, 2, '0', STR_PAD_LEFT) . ':00:00',
            new \DateTimeZone(self::TIMEZONE)
        );
    }
}
