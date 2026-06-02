/**
 * Event Staff System — Registration Field Map
 * Single source of truth for form, database, and spreadsheet export.
 * Form name attributes MUST match the "name" column below.
 */

const REGISTRATION_FIELDS = [
    { name: 'surname',        label: 'Surname',                      spreadsheet: 'Surname' },
    { name: 'first_name',     label: 'First Name',                   spreadsheet: 'First Name' },
    { name: 'full_address',   label: 'Full address',                 spreadsheet: 'Full Address' },
    { name: 'eircode',        label: 'Eircode',                      spreadsheet: 'Eircode' },
    { name: 'email',          label: 'Email Address',                spreadsheet: 'Email Address' },
    { name: 'mobile',         label: 'Mobile Number',                spreadsheet: 'Mobile Number' },
    { name: 'date_of_birth',  label: 'Date of Birth',                spreadsheet: 'Date of Birth' },
    { name: 'gender',         label: 'Gender',                       spreadsheet: 'Gender' },
    { name: 'pps_number',     label: 'NI / PPS Number',              spreadsheet: 'NI / PPS Number' },
    { name: 'bank_iban',      label: 'Bank Account / IBAN',          spreadsheet: 'Bank Account / IBAN' },
    { name: 'staff_role',     label: 'Role',                         spreadsheet: 'DSP / Static (PSA security)' }
    /* event_ids[] — multi-select checkboxes, one DB row per event */
];

/** Irish Eircode: 3-character routing key + 4-character unique id (space optional) */
const EIRCODE_PATTERN = /^[A-Z0-9]{3}\s?[A-Z0-9]{4}$/i;

function normalizeEircode(value) {
    return value.trim().toUpperCase().replace(/\s+/g, ' ');
}

function isValidEircode(value) {
    return EIRCODE_PATTERN.test(normalizeEircode(value));
}
