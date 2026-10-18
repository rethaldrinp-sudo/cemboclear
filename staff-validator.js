

const StaffValidator = {
    validate(payload) {
        const errors = [];

        const position = (payload.position || '').trim();
        if (!position) {
            errors.push('Position is required for staff accounts.');
        }

        const firstName = (payload.first_name || '').trim();
        if (!firstName) {
            errors.push('First name is required.');
        } else if (firstName.length < ValidationRules.NAME_MIN || firstName.length > ValidationRules.NAME_MAX) {
            errors.push('First name must be between ' + ValidationRules.NAME_MIN + ' and ' + ValidationRules.NAME_MAX + ' characters.');
        } else if (!ValidationRules.NAME_PATTERN.test(firstName)) {
            errors.push('First name contains invalid characters.');
        }

        const lastName = (payload.last_name || '').trim();
        if (!lastName) {
            errors.push('Last name is required.');
        } else if (lastName.length < ValidationRules.NAME_MIN || lastName.length > ValidationRules.NAME_MAX) {
            errors.push('Last name must be between ' + ValidationRules.NAME_MIN + ' and ' + ValidationRules.NAME_MAX + ' characters.');
        } else if (!ValidationRules.NAME_PATTERN.test(lastName)) {
            errors.push('Last name contains invalid characters.');
        }

        const middleName = (payload.middle_name || '').trim();
        if (middleName !== '' && !/^[a-zA-Z]\.?$/.test(middleName)) {
            errors.push('Middle initial must be a single letter (e.g. M or M.).');
        }

        const email = (payload.email || '').trim().toLowerCase();
        if (!email) {
            errors.push('Email address is required.');
        } else if (email.length > 255) {
            errors.push('Email address must not exceed 255 characters.');
        } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
            errors.push('Please enter a valid email address.');
        }

        const phone = (payload.phone || '').trim();
        if (phone !== '' && phMobileBase(phone) === null) {
            errors.push('Please enter a valid Philippine mobile number (e.g. 09171234567 or +639171234567).');
        }

        const birthdate = (payload.birthdate || '').trim();
        if (birthdate !== '') {
            if (!/^\d{4}-\d{2}-\d{2}$/.test(birthdate)) {
                errors.push('Birthdate must be in YYYY-MM-DD format.');
            } else {
                const parts = birthdate.split('-').map(Number);
                const dateObj = new Date(parts[0], parts[1] - 1, parts[2]);
                const isRealDate = dateObj.getFullYear() === parts[0] &&
                    dateObj.getMonth() === parts[1] - 1 &&
                    dateObj.getDate() === parts[2];
                const today = new Date();
                today.setHours(0, 0, 0, 0);

                if (!isRealDate) {
                    errors.push('Birthdate must be a valid date.');
                } else if (dateObj > today) {
                    errors.push('Birthdate cannot be in the future.');
                } else if (parts[0] < ValidationRules.MIN_BIRTH_YEAR) {
                    errors.push('Birthdate looks unreasonably old (must be on or after ' + ValidationRules.MIN_BIRTH_YEAR + ').');
                } else if (!ValidationRules.meetsMinimumAge(dateObj)) {
                    errors.push(ValidationRules.minimumAgeMessage('Staff'));
                }
            }
        }

        const gender = (payload.gender || '').trim().toLowerCase();
        if (gender !== '' && !['male', 'female', 'other'].includes(gender)) {
            errors.push('Gender must be "male", "female", or "other".');
        }

        const registryStatus = (payload.registry_status || '').trim().toLowerCase();
        if (registryStatus !== '' && !['pending', 'verified', 'outdated'].includes(registryStatus)) {
            errors.push('Registry status must be "pending", "verified", or "outdated".');
        }

        const password = payload.password || '';
        if (!password) {
            errors.push('Password is required.');
        } else if (new TextEncoder().encode(password).length > 72) {
            errors.push('Password must not exceed 72 characters.');
        } else {
            if (password.length < 8) {
                errors.push('Password must be at least 8 characters long.');
            }
            if (!/[A-Z]/.test(password)) {
                errors.push('Password must contain at least one uppercase letter.');
            }
            if (!/[a-z]/.test(password)) {
                errors.push('Password must contain at least one lowercase letter.');
            }
            if (!/[0-9]/.test(password)) {
                errors.push('Password must contain at least one number.');
            }
            if (!/[\W_]/.test(password)) {
                errors.push('Password must contain at least one special character.');
            }
        }

        return {
            isValid: errors.length === 0,
            errors: errors
        };
    }
};

function phMobileBase(phone) {
    const digits = String(phone).replace(/\D/g, '');
    if (!digits) return null;

    if (digits.indexOf('639') === 0 && digits.length === 12) {
        return digits.slice(2);
    }
    if (digits.indexOf('09') === 0 && digits.length === 11) {
        return digits.slice(1);
    }
    if (digits.indexOf('9') === 0 && digits.length === 10) {
        return digits;
    }
    return null;
}

if (typeof module !== 'undefined' && module.exports) {
    module.exports = StaffValidator;
}
