<?php
declare(strict_types=1);

namespace App\Features\StaffAuthentication;

use App\Core\PhoneNormalizer;
use App\Core\ValidationRules;

class StaffValidator
{
    public static function validate(array $input): array
    {
        $errors = [];

        $position = trim((string)($input['position'] ?? ''));
        if ($position === '') {
            $errors[] = 'Position is required for staff accounts.';
        }

        $firstName = trim((string)($input['first_name'] ?? ''));
        if ($firstName === '') {
            $errors[] = 'First name is required.';
        } elseif (mb_strlen($firstName) < ValidationRules::NAME_MIN || mb_strlen($firstName) > ValidationRules::NAME_MAX) {
            $errors[] = 'First name must be between ' . ValidationRules::NAME_MIN . ' and ' . ValidationRules::NAME_MAX . ' characters.';
        } elseif (!preg_match(ValidationRules::NAME_PATTERN, $firstName)) {
            $errors[] = 'First name contains invalid characters.';
        }

        $lastName = trim((string)($input['last_name'] ?? ''));
        if ($lastName === '') {
            $errors[] = 'Last name is required.';
        } elseif (mb_strlen($lastName) < ValidationRules::NAME_MIN || mb_strlen($lastName) > ValidationRules::NAME_MAX) {
            $errors[] = 'Last name must be between ' . ValidationRules::NAME_MIN . ' and ' . ValidationRules::NAME_MAX . ' characters.';
        } elseif (!preg_match(ValidationRules::NAME_PATTERN, $lastName)) {
            $errors[] = 'Last name contains invalid characters.';
        }

        $middleName = trim((string)($input['middle_name'] ?? ''));
        if ($middleName !== '') {
            if (!preg_match("/^[a-zA-Z]\.?$/u", $middleName)) {
                $errors[] = 'Middle initial must be a single letter (e.g. M or M.).';
            }
        }

        $email = strtolower(trim((string)($input['email'] ?? '')));
        if ($email === '') {
            $errors[] = 'Email address is required.';
        } elseif (mb_strlen($email) > 255) {
            $errors[] = 'Email address must not exceed 255 characters.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Please enter a valid email address.';
        }

        $phone = trim((string)($input['phone'] ?? ''));
        if ($phone !== '' && !PhoneNormalizer::isValid($phone)) {
            $errors[] = 'Please enter a valid Philippine mobile number (e.g. 09171234567 or +639171234567).';
        }

        $birthdate = trim((string)($input['birthdate'] ?? ''));
        if ($birthdate !== '') {
            $d = \DateTime::createFromFormat('Y-m-d', $birthdate);
            if (!($d && $d->format('Y-m-d') === $birthdate)) {
                $errors[] = 'Birthdate must be a valid date in YYYY-MM-DD format.';
            } else {
                $now = new \DateTime('today');
                if ($d > $now) {
                    $errors[] = 'Birthdate cannot be in the future.';
                } elseif ($d < new \DateTime(ValidationRules::MIN_BIRTH_YEAR . '-01-01')) {
                    $errors[] = 'Birthdate looks unreasonably old (must be on or after ' . ValidationRules::MIN_BIRTH_YEAR . ').';
                } elseif (!ValidationRules::meetsMinimumAge($d)) {
                    $errors[] = ValidationRules::minimumAgeMessage('Staff');
                }
            }
        }

        $gender = strtolower(trim((string)($input['gender'] ?? '')));
        if ($gender !== '' && !in_array($gender, ['male', 'female', 'other'], true)) {
            $errors[] = 'Gender must be "male", "female", or "other".';
        }

        $registryStatus = strtolower(trim((string)($input['registry_status'] ?? '')));
        if ($registryStatus !== '' && !in_array($registryStatus, ['pending', 'verified', 'outdated'], true)) {
            $errors[] = 'Registry status must be "pending", "verified", or "outdated".';
        }

        $password = (string)($input['password'] ?? '');
        if ($password !== '') {
            if (strlen($password) > 72) {
                $errors[] = 'Password must not exceed 72 characters.';
            } else {
                if (mb_strlen($password) < 8) {
                    $errors[] = 'Password must be at least 8 characters long.';
                }
                if (!preg_match('/[A-Z]/', $password)) {
                    $errors[] = 'Password must contain at least one uppercase letter.';
                }
                if (!preg_match('/[a-z]/', $password)) {
                    $errors[] = 'Password must contain at least one lowercase letter.';
                }
                if (!preg_match('/[0-9]/', $password)) {
                    $errors[] = 'Password must contain at least one number.';
                }
                if (!preg_match('/[\W_]/', $password)) {
                    $errors[] = 'Password must contain at least one special character.';
                }
            }
        }

        return $errors;
    }
}
