<?php
 declare(strict_types=1);

namespace App\Core;

class ValidationRules
{
    public const MIN_AGE = 15;

    public const MIN_BIRTH_YEAR = 1900;

    public const NAME_MIN = 2;
    public const NAME_MAX = 50;

    public const NAME_PATTERN = "/^[a-zA-Z\s\-'.]+$/u";

    public static function minimumBirthdate(): \DateTimeImmutable
    {
        return (new \DateTimeImmutable('today'))->modify('-' . self::MIN_AGE . ' years');
    }

    public static function meetsMinimumAge(?\DateTimeInterface $birthdate): bool
    {
        if ($birthdate === null) {
            return true;
        }
        $birthdateOnly = (new \DateTimeImmutable($birthdate->format('Y-m-d')));
        return $birthdateOnly <= self::minimumBirthdate();
    }

    public static function minimumAgeMessage(string $subject = 'You'): string
    {
        if ($subject === 'You') {
            return 'You must be at least ' . self::MIN_AGE . ' years old to register.';
        }
        return $subject . ' must be at least ' . self::MIN_AGE . ' years old.';
    }
}
