

const SignupValidator = {
    validate(payload) {
        const errors = [];

        const accountType = (payload.account_type || 'resident').trim().toLowerCase();
        if (!['resident', 'admin'].includes(accountType)) {
            errors.push('Please select a valid account type.');
        }

        if (accountType === 'admin') {
            if (!(payload.position || '').trim()) {
                errors.push('Position is required for admin accounts.');
            }
        }

        if (payload.civil_status && payload.civil_status.trim() !== '') {
            const cs = payload.civil_status.trim().toLowerCase();
            if (!['single', 'married', 'widowed', 'separated'].includes(cs)) {
                errors.push('Civil status must be Single, Married, Widowed, or Separated.');
            }
        }
        if (payload.citizenship && payload.citizenship.trim().length > 100) {
            errors.push('Citizenship must not exceed 100 characters.');
        }
        if (payload.birth_place && payload.birth_place.trim().length > 150) {
            errors.push('Birth place must not exceed 150 characters.');
        }
        if (payload.address && payload.address.trim().length > 255) {
            errors.push('Address must not exceed 255 characters.');
        }
        if (payload.suffix && payload.suffix.trim().length > 20) {
            errors.push('Suffix must not exceed 20 characters.');
        }

        const firstName = (payload.first_name || '').trim();
        if (!firstName) {
            errors.push('First Name is required.');
        } else if (firstName.length < ValidationRules.NAME_MIN || firstName.length > ValidationRules.NAME_MAX) {
            errors.push('First Name must be between ' + ValidationRules.NAME_MIN + ' and ' + ValidationRules.NAME_MAX + ' characters.');
        } else if (!ValidationRules.NAME_PATTERN.test(firstName)) {
            errors.push('First Name contains invalid characters.');
        }

        const lastName = (payload.last_name || '').trim();
        if (!lastName) {
            errors.push('Last Name is required.');
        } else if (lastName.length < ValidationRules.NAME_MIN || lastName.length > ValidationRules.NAME_MAX) {
            errors.push('Last Name must be between ' + ValidationRules.NAME_MIN + ' and ' + ValidationRules.NAME_MAX + ' characters.');
        } else if (!ValidationRules.NAME_PATTERN.test(lastName)) {
            errors.push('Last Name contains invalid characters.');
        }

        if (payload.middle_name && payload.middle_name.trim() !== '') {
            const middleName = payload.middle_name.trim();
            if (!/^[a-zA-Z]\.?$/.test(middleName)) {
                errors.push('Middle initial must be a single letter (e.g. M or M.).');
            }
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
        if (!phone) {
            errors.push('Phone number is required.');
        } else if (phMobileBase(phone) === null) {
            errors.push('Please enter a valid Philippine mobile number (e.g. 0917 123 4567 or +63 917 123 4567).');
        }

        const birthdate = (payload.birthdate || '').trim();
        if (!birthdate) {
            if (accountType !== 'admin') {
                errors.push('Birthdate is required.');
            }
        } else if (!/^\d{4}-\d{2}-\d{2}$/.test(birthdate)) {
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
                errors.push(ValidationRules.minimumAgeMessage());
            }
        }

        const gender = (payload.gender || '').trim().toLowerCase();
        if (!gender) {
            if (accountType !== 'admin') {
                errors.push('Gender is required.');
            }
        } else if (!['male', 'female', 'other'].includes(gender)) {
            errors.push('Please select a valid gender option.');
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

        if (payload.password_confirm !== undefined) {
            if (payload.password_confirm !== password) {
                errors.push('Passwords do not match.');
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
    module.exports = SignupValidator;
}
