/**
 * Registration wizard - PSA camera-first upload UX (feature_registration_wizard_v2)
 */
(function () {
    'use strict';

    if (document.body.dataset.wizardMode !== '1') {
        return;
    }

    var ON_FILE_LABELS = {
        front: '\u2713 PSA front photo already on file',
        back: '\u2713 PSA back photo already on file',
    };

    var REPLACE_HINT = 'Optional: upload a new photo to replace the one on file.';

    function onFileLabel(side) {
        return ON_FILE_LABELS[side] || ON_FILE_LABELS.front;
    }

    function defaultHint(side) {
        return side === 'front'
            ? 'Photograph the front of your PSA card. On mobile, your camera opens automatically.'
            : 'Photograph the back of your PSA card. On mobile, your camera opens automatically.';
    }

    function updateWrapState(wrap, status, input, side) {
        var hasFile = input.files && input.files.length > 0;
        var onFile = input.dataset.psaOnFile === '1' && !input.required;

        wrap.classList.remove('reg-psa-upload--ready', 'reg-psa-upload--on-file');

        if (hasFile) {
            status.textContent = 'Selected: ' + input.files[0].name;
            wrap.classList.add('reg-psa-upload--ready');
            return;
        }

        if (onFile) {
            status.textContent = onFileLabel(side);
            wrap.classList.add('reg-psa-upload--on-file');
            return;
        }

        status.textContent = 'No photo selected';
    }

    function enhanceFileInput(input) {
        if (!input || input.dataset.psaEnhanced === '1') {
            return;
        }
        input.dataset.psaEnhanced = '1';

        var side = input.getAttribute('data-psa-upload') || 'front';
        var wrap = document.createElement('div');
        wrap.className = 'reg-psa-upload';

        var hint = document.createElement('p');
        hint.className = 'reg-psa-upload__hint';

        var replaceHint = document.createElement('p');
        replaceHint.className = 'reg-psa-upload__replace-hint';
        replaceHint.hidden = true;
        replaceHint.textContent = REPLACE_HINT;

        input.setAttribute('capture', 'environment');
        input.setAttribute('accept', input.getAttribute('accept') || 'image/*');

        var status = document.createElement('p');
        status.className = 'reg-psa-upload__status';
        status.setAttribute('aria-live', 'polite');
        status.textContent = 'No photo selected';

        var parent = input.parentNode;
        if (!parent) {
            return;
        }
        parent.insertBefore(wrap, input);
        wrap.appendChild(hint);
        wrap.appendChild(input);
        wrap.appendChild(status);
        wrap.appendChild(replaceHint);

        function refreshHint() {
            var onFile = input.dataset.psaOnFile === '1' && !input.required;
            hint.textContent = onFile ? REPLACE_HINT : defaultHint(side);
            replaceHint.hidden = true;
        }

        function updateStatus() {
            refreshHint();
            updateWrapState(wrap, status, input, side);
        }

        input.addEventListener('change', updateStatus);
        input._psaUpdateStatus = updateStatus;
        updateStatus();
    }

    function refreshAll() {
        ['psa_front_image', 'psa_back_image'].forEach(function (id) {
            var input = document.getElementById(id);
            if (input && typeof input._psaUpdateStatus === 'function') {
                input._psaUpdateStatus();
            }
        });
    }

    function initPsaLicenceVerify() {
        var input = document.getElementById('psa_licence');
        var status = document.getElementById('psa-licence-verify-status');
        var csrf = document.body.dataset.analyticsCsrf || '';
        if (!input || !status) {
            return;
        }

        function holderName() {
            var first = document.getElementById('first_name');
            var surname = document.getElementById('surname');
            return [first ? first.value.trim() : '', surname ? surname.value.trim() : ''].join(' ').trim();
        }

        function verify() {
            var licence = input.value.trim();
            if (!licence) {
                status.textContent = '';
                return;
            }
            status.textContent = 'Checking licence format…';
            var body = new FormData();
            body.append('csrf_token', csrf);
            body.append('psa_licence', licence);
            body.append('holder_name', holderName());
            fetch('api/psa-licence-verify.php', { method: 'POST', body: body, credentials: 'same-origin' })
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    status.textContent = data && data.message ? data.message : '';
                    status.className = 'form-hint' + (data && data.ok ? ' form-hint--success' : ' form-hint--warn');
                })
                .catch(function () {
                    status.textContent = '';
                });
        }

        input.addEventListener('blur', verify);
        input.addEventListener('change', verify);
    }

    function init() {
        ['psa_front_image', 'psa_back_image'].forEach(function (id) {
            enhanceFileInput(document.getElementById(id));
        });
        initPsaLicenceVerify();
    }

    window.RegistrationWizardPsa = {
        refreshAll: refreshAll,
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
