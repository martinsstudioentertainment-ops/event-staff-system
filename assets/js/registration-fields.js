/**
 * Event Staff System — Registration Field Map
 * Single source of truth for form, database, and spreadsheet export.
 * Form name attributes MUST match the "name" column below.
 */

const REGISTRATION_FIELDS = [
    { name: 'surname',        label: 'Surname',                      spreadsheet: 'Surname' },
    { name: 'first_name',     label: 'First Name',                   spreadsheet: 'First Name' },
    { name: 'full_address',   label: 'Full address',                 spreadsheet: 'Full Address' },
    { name: 'eircode',        label: 'Eircode',                      spreadsheet: 'Postcode' },
    { name: 'email',          label: 'Email Address',                spreadsheet: 'Email' },
    { name: 'mobile',         label: 'Mobile Number',                spreadsheet: 'Mobile Number' },
    { name: 'date_of_birth',  label: 'Date of Birth',                spreadsheet: 'Date Of Birth' },
    { name: 'gender',         label: 'Gender',                       spreadsheet: 'Gender' },
    { name: 'pps_number',     label: 'NI / PPS Number',              spreadsheet: 'National Insurance/PPS' },
    { name: 'bank_iban',      label: 'Bank Account / IBAN',          spreadsheet: 'Bank Account/IBAN' },
    /* staff_role + event_ids[] — stored in DB; not in employee payroll spreadsheet */
];

/** Irish Eircode: 3-character routing key + 4-character unique id (space optional) */
const EIRCODE_PATTERN = /^[A-Z0-9]{3}\s?[A-Z0-9]{4}$/i;

function normalizeEircode(value) {
    return value.trim().toUpperCase().replace(/\s+/g, ' ');
}

function isValidEircode(value) {
    return EIRCODE_PATTERN.test(normalizeEircode(value));
}

function getRegistrationStaffRole() {
    var roleInput = document.getElementById('staff_role');
    if (roleInput && String(roleInput.value || '').trim() !== '') {
        return String(roleInput.value).trim().toLowerCase();
    }
    var picked = document.querySelector('input[name="form_slug"]:checked');
    if (picked && picked.dataset.role) {
        return String(picked.dataset.role).trim().toLowerCase();
    }
    var slug = document.getElementById('form_slug');
    if (slug && slug.tagName === 'SELECT' && slug.selectedOptions[0] && slug.selectedOptions[0].dataset.role) {
        return String(slug.selectedOptions[0].dataset.role).trim().toLowerCase();
    }
    if (slug && slug.tagName === 'SELECT' && slug.value) {
        return String(slug.value).trim().toLowerCase();
    }
    if (picked && picked.value) {
        return String(picked.value).trim().toLowerCase();
    }
    return '';
}

function registrationRoleRequiresPsa() {
    return getRegistrationStaffRole() !== 'steward';
}

function applyRegistrationPsaFieldRequirements() {
    var requiresPsa = registrationRoleRequiresPsa();
    ['psa_licence', 'psa_expiry_date', 'psa_front_image', 'psa_back_image'].forEach(function (id) {
        var el = document.getElementById(id);
        if (!el) {
            return;
        }
        el.required = requiresPsa;
        var label = document.querySelector('label[for="' + id + '"]');
        if (label) {
            label.classList.toggle('form-label--required', requiresPsa);
        }
    });
}

(function () {
    function bindPsaRequirements() {
        if (typeof applyRegistrationPsaFieldRequirements !== 'function') {
            return;
        }
        applyRegistrationPsaFieldRequirements();
        document.querySelectorAll('input[name="form_slug"]').forEach(function (el) {
            el.addEventListener('change', applyRegistrationPsaFieldRequirements);
        });
        var slug = document.getElementById('form_slug');
        if (slug) {
            slug.addEventListener('change', applyRegistrationPsaFieldRequirements);
        }
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bindPsaRequirements);
    } else {
        bindPsaRequirements();
    }
})();
