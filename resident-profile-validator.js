
const ResidentProfileValidator = {
    CIVIL_STATUSES: ['single', 'married', 'widowed', 'separated'],

    validate(payload) {
        const errors = [];

        this._validateName(payload, 'first_name', 'First name', true, errors);
        this._validateName(payload, 'last_name', 'Last name', true, errors);

        if (Object.prototype.hasOwnProperty.call(payload, 'middle_name')) {
            const middle = (payload.middle_name || '').trim();
            if (middle !== '' && !/^[a-zA-Z]\.?$/.test(middle)) {
                errors.push('Middle initial must be a single letter (e.g. M or M.).');
            }
        }

        if (Object.prototype.hasOwnProperty.call(payload, 'suffix')) {
            const suffix = (payload.suffix || '').trim();
            if (suffix.length > 20) {
                errors.push('Suffix must not exceed 20 characters.');
            }
        }

        if (Object.prototype.hasOwnProperty.call(payload, 'civil_status')) {
            const civil = (payload.civil_status || '').trim().toLowerCase();
            if (civil !== '' && !this.CIVIL_STATUSES.includes(civil)) {
                errors.push('Civil status must be Single, Married, Widowed, or Separated.');
            }
        }

        if (Object.prototype.hasOwnProperty.call(payload, 'birthdate')) {
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
                        errors.push(ValidationRules.minimumAgeMessage('Residents'));
                    }
                }
            }
        }

        this._validateMaxLength(payload, 'birth_place', 'Birth place', 150, errors);
        this._validateMaxLength(payload, 'address', 'Address', 255, errors);
        this._validateMaxLength(payload, 'citizenship', 'Citizenship', 100, errors);

        return {
            isValid: errors.length === 0,
            errors: errors
        };
    },

    _validateName(payload, field, label, required, errors) {
        if (!Object.prototype.hasOwnProperty.call(payload, field)) {
            return;
        }

        const value = (payload[field] || '').trim();

        if (value === '') {
            if (required) {
                errors.push(label + ' is required.');
            }
            return;
        }

        if (value.length < ValidationRules.NAME_MIN || value.length > ValidationRules.NAME_MAX) {
            errors.push(label + ' must be between ' + ValidationRules.NAME_MIN + ' and ' + ValidationRules.NAME_MAX + ' characters.');
        } else if (!ValidationRules.NAME_PATTERN.test(value)) {
            errors.push(label + ' contains invalid characters.');
        }
    },

    _validateMaxLength(payload, field, label, max, errors) {
        if (!Object.prototype.hasOwnProperty.call(payload, field)) {
            return;
        }
        const value = (payload[field] || '').trim();
        if (value.length > max) {
            errors.push(label + ' must not exceed ' + max + ' characters.');
        }
    }
};

if (typeof module !== 'undefined' && module.exports) {
    module.exports = ResidentProfileValidator;
}
