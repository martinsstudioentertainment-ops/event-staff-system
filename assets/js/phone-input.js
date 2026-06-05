/**
 * Country code + national mobile → E.164 hidden field.
 */
(function () {
    'use strict';

    var DIAL_CODES = {
        IE: '+353', GB: '+44', PL: '+48', RO: '+40', LT: '+370', LV: '+371', EE: '+372',
        HU: '+36', SK: '+421', CZ: '+420', DE: '+49', FR: '+33', ES: '+34', IT: '+39',
        PT: '+351', NL: '+31', BE: '+32', SE: '+46', NO: '+47', DK: '+45', FI: '+358',
        UA: '+380', BG: '+359', HR: '+385', GR: '+30', AT: '+43', CH: '+41', US: '+1',
        CA: '+1', AU: '+61', NZ: '+64', IN: '+91', PH: '+63', BR: '+55', ZA: '+27',
        NG: '+234', PK: '+92', BD: '+880', CN: '+86', JP: '+81', KR: '+82', TR: '+90',
        SA: '+966', AE: '+971', MX: '+52', AR: '+54', CO: '+57', CL: '+56', PE: '+51',
        RU: '+7', IL: '+972', EG: '+20', MA: '+212', SN: '+221', GH: '+233', KE: '+254',
        ZW: '+263', MT: '+356', CY: '+357', LU: '+352', IS: '+354', SI: '+386', RS: '+381',
        BA: '+387', MK: '+389', AL: '+355', MD: '+373', BY: '+375', GE: '+995', AM: '+374',
        AZ: '+994', KZ: '+7', UZ: '+998', TH: '+66', VN: '+84', MY: '+60', SG: '+65',
        ID: '+62', HK: '+852', TW: '+886'
    };

    function dialForIso(iso) {
        return DIAL_CODES[String(iso || '').toUpperCase()] || DIAL_CODES.IE;
    }

    function digitsOnly(value) {
        return String(value || '').replace(/\D+/g, '');
    }

    function buildE164(iso, national) {
        var dial = dialForIso(iso);
        var dialDigits = dial.replace(/\D+/g, '');
        var raw = digitsOnly(national);
        if (!raw) {
            return '';
        }
        if (raw.indexOf('00') === 0) {
            raw = raw.slice(2);
            return raw ? '+' + raw : '';
        }
        if (raw.indexOf('0') === 0) {
            raw = raw.slice(1);
        }
        if (!raw) {
            return '';
        }
        if (raw.indexOf(dialDigits) === 0 && raw.length > dialDigits.length + 5) {
            return '+' + raw;
        }
        return dial + raw;
    }

    function isValidE164Mobile(value) {
        return /^\+[1-9]\d{6,14}$/.test(String(value || '').trim());
    }

    function syncWrapper(wrapper) {
        if (!wrapper) {
            return '';
        }
        var isoEl = wrapper.querySelector('[data-phone-country]');
        var nationalEl = wrapper.querySelector('[data-phone-national]');
        var hiddenEl = wrapper.querySelector('[data-phone-e164]');
        if (!isoEl || !nationalEl || !hiddenEl) {
            return '';
        }
        var e164 = buildE164(isoEl.value, nationalEl.value);
        hiddenEl.value = e164;
        return e164;
    }

    function syncPhoneInputMobile(root) {
        var scope = root && root.querySelectorAll ? root : document;
        var wrappers = scope.querySelectorAll('[data-phone-input]');
        var last = '';
        wrappers.forEach(function (wrapper) {
            last = syncWrapper(wrapper);
        });
        return last;
    }

    function splitStoredMobile(value, fallbackIso) {
        var raw = String(value || '').trim();
        fallbackIso = String(fallbackIso || 'IE').toUpperCase();
        if (!raw) {
            return { iso: fallbackIso, national: '', e164: '' };
        }
        var e164 = raw.indexOf('+') === 0 ? '+' + digitsOnly(raw.slice(1)) : buildE164(fallbackIso, raw);
        var digits = e164.replace(/\D+/g, '');
        var best = null;
        Object.keys(DIAL_CODES).forEach(function (iso) {
            var dialDigits = DIAL_CODES[iso].replace(/\D+/g, '');
            if (digits.indexOf(dialDigits) === 0) {
                if (!best || dialDigits.length > best.len) {
                    best = { iso: iso, len: dialDigits.length, national: digits.slice(dialDigits.length) };
                }
            }
        });
        if (best) {
            return { iso: best.iso, national: best.national, e164: e164 };
        }
        return { iso: fallbackIso, national: digitsOnly(raw), e164: e164 };
    }

    function setPhoneInputValue(value, root) {
        var scope = root && root.querySelectorAll ? root : document;
        var wrapper = scope.querySelector('[data-phone-input]');
        if (!wrapper) {
            var hidden = document.getElementById('mobile') || document.getElementById('phone');
            if (hidden) {
                hidden.value = String(value || '').trim();
            }
            return;
        }
        var isoEl = wrapper.querySelector('[data-phone-country]');
        var nationalEl = wrapper.querySelector('[data-phone-national]');
        var hiddenEl = wrapper.querySelector('[data-phone-e164]');
        var parts = splitStoredMobile(value, isoEl ? isoEl.value : 'IE');
        if (isoEl) {
            isoEl.value = parts.iso;
        }
        if (nationalEl) {
            nationalEl.value = parts.national;
        }
        if (hiddenEl) {
            hiddenEl.value = parts.e164;
        }
        syncWrapper(wrapper);
    }

    function bindPhoneInputs() {
        document.querySelectorAll('[data-phone-input]').forEach(function (wrapper) {
            if (wrapper.dataset.phoneBound === '1') {
                return;
            }
            wrapper.dataset.phoneBound = '1';
            wrapper.addEventListener('input', function () {
                syncWrapper(wrapper);
            });
            wrapper.addEventListener('change', function () {
                syncWrapper(wrapper);
            });
        });

        document.querySelectorAll('form').forEach(function (form) {
            if (form.dataset.phoneSubmitBound === '1') {
                return;
            }
            if (!form.querySelector('[data-phone-input]')) {
                return;
            }
            form.dataset.phoneSubmitBound = '1';
            form.addEventListener('submit', function () {
                syncPhoneInputMobile(form);
            }, true);
        });
    }

    window.buildE164 = buildE164;
    window.isValidE164Mobile = isValidE164Mobile;
    window.syncPhoneInputMobile = syncPhoneInputMobile;
    window.setPhoneInputValue = setPhoneInputValue;

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bindPhoneInputs);
    } else {
        bindPhoneInputs();
    }
})();
