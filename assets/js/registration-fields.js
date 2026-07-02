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

/** Roles that skip PSA licence + card photos on registration (must match PHP getStaffRolesExemptFromPsa). */
var REGISTRATION_PSA_EXEMPT_ROLES = ['steward'];

function staffRoleRequiresPsa(role) {
    return REGISTRATION_PSA_EXEMPT_ROLES.indexOf(String(role || '').toLowerCase()) === -1;
}

function resolveRegistrationStaffRole() {
    var locked = String(document.body.dataset.lockedRole || '').trim();
    if (locked) {
        return locked;
    }
    var roleInput = document.getElementById('staff_role');
    if (roleInput && String(roleInput.value || '').trim() !== '') {
        return String(roleInput.value || '').trim();
    }
    var select = document.getElementById('form_slug');
    if (select && select.tagName === 'SELECT') {
        var opt = select.options[select.selectedIndex];
        if (opt && opt.getAttribute('data-role')) {
            return opt.getAttribute('data-role');
        }
        return select.value;
    }
    var hidden = document.querySelector('input[type="hidden"][name="form_slug"]');
    return hidden ? hidden.value : '';
}

function syncRegistrationPsaRequirement() {
    var required = staffRoleRequiresPsa(resolveRegistrationStaffRole());
    document.body.dataset.psaRequired = required ? '1' : '0';
    document.body.classList.toggle('registration-page--no-psa', !required);

    ['psa_licence', 'psa_expiry_date', 'psa_front_image', 'psa_back_image'].forEach(function (id) {
        var el = document.getElementById(id);
        if (!el) {
            return;
        }
        el.required = required;
        var group = el.closest('.form-group');
        if (!group) {
            return;
        }
        var label = group.querySelector('label[for="' + id + '"]');
        if (label) {
            label.classList.toggle('form-label--required', required);
        }
    });

    if (window.RegistrationWizard && typeof window.RegistrationWizard.refreshChrome === 'function') {
        window.RegistrationWizard.refreshChrome();
    }
}

window.syncRegistrationPsaRequirement = syncRegistrationPsaRequirement;
window.staffRoleRequiresPsa = staffRoleRequiresPsa;
