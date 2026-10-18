<?php
declare(strict_types=1);

namespace App\Features\ResidentRegistryWrite;

use App\Core\ValidationRules;

class ResidentProfileValidator
{
    private const CIVIL_STATUSES = ['single', 'married', 'widowed', 'separated'];

    public static function validate(array $input): array
    {
        $errors = [];

        self::validateName($input, 'first_name', 'First name', true, $errors);
        self::validateName($input, 'last_name', 'Last name', true, $errors);

        if (array_key_exists('middle_name', $input)) {
            $middle = trim((string)$input['middle_name']);
            if ($middle !== '' && !preg_match('/^[a-zA-Z]\.?$/u', $middle)) {
                $errors[] = 'Middle initial must be a single letter (e.g. M or M.).';
            }
        }

        if (array_key_exists('suffix', $input)) {
            $suffix = trim((string)$input['suffix']);
            if (mb_strlen($suffix) > 20) {
                $errors[] = 'Suffix must not exceed 20 characters.';
            }
        }

        if (array_key_exists('civil_status', $input)) {
            $civil = strtolower(trim((string)$input['civil_status']));
            if ($civil !== '' && !in_array($civil, self::CIVIL_STATUSES, true)) {
                $errors[] = 'Civil status must be Single, Married, Widowed, or Separated.';
            }
        }

        self::validateMaxLength($input, 'birth_place', 'Birth place', 150, $errors);
        self::validateMaxLength($input, 'address', 'Address', 255, $errors);
        self::validateMaxLength($input, 'citizenship', 'Citizenship', 100, $errors);

        return $errors;
    }

    private static function validateName(array $input, string $field, string $label, bool $required, array &$errors): void
    {
        if (!array_key_exists($field, $input)) {
            return;
        }

        $value = trim((string)$input[$field]);

        if ($value === '') {
            if ($required) {
                $errors[] = $label . ' is required.';
            }
            return;
        }

        if (mb_strlen($value) < ValidationRules::NAME_MIN || mb_strlen($value) > ValidationRules::NAME_MAX) {
            $errors[] = $label . ' must be between ' . ValidationRules::NAME_MIN . ' and ' . ValidationRules::NAME_MAX . ' characters.';
        } elseif (!preg_match(ValidationRules::NAME_PATTERN, $value)) {
            $errors[] = $label . ' contains invalid characters.';
        }
    }

    private static function validateMaxLength(array $input, string $field, string $label, int $max, array &$errors): void
    {
        if (!array_key_exists($field, $input)) {
            return;
        }
        $value = trim((string)$input[$field]);
        if (mb_strlen($value) > $max) {
            $errors[] = $label . ' must not exceed ' . $max . ' characters.';
        }
    }
}
