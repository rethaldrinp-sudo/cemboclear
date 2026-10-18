

const ValidationRules = {
    MIN_AGE: 15,

    MIN_BIRTH_YEAR: 1900,

    NAME_MIN: 2,
    NAME_MAX: 50,

    NAME_PATTERN: /^[a-zA-Z\s\-'\.]+$/,

    minimumBirthdate() {
        const now = new Date();
        return new Date(now.getFullYear() - this.MIN_AGE, now.getMonth(), now.getDate());
    },

    meetsMinimumAge(birthdate) {
        if (!(birthdate instanceof Date) || isNaN(birthdate.getTime())) {
            return true;
        }
        return birthdate <= this.minimumBirthdate();
    },

    minimumAgeMessage(subject) {
        if (!subject || subject === 'You') {
            return 'You must be at least ' + this.MIN_AGE + ' years old to register.';
        }
        return subject + ' must be at least ' + this.MIN_AGE + ' years old.';
    }
};

if (typeof module !== 'undefined' && module.exports) {
    module.exports = ValidationRules;
}
