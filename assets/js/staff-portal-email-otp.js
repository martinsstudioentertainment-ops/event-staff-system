(function () {
    'use strict';

    var root = document.getElementById('staff-portal-email-otp');
    if (!root) {
        return;
    }

    var sendUrl = root.getAttribute('data-send-url') || 'api/staff-portal-otp-send.php';
    var verifyUrl = root.getAttribute('data-verify-url') || 'api/staff-portal-otp-verify.php';
    var csrf = root.getAttribute('data-csrf') || '';

    var toggleBtn = document.getElementById('staff-portal-email-toggle');
    var panel = document.getElementById('staff-portal-email-panel');
    var emailInput = document.getElementById('staff-portal-email-input');
    var sendBtn = document.getElementById('staff-portal-email-send');
    var verifyBtn = document.getElementById('staff-portal-email-verify');
    var backBtn = document.getElementById('staff-portal-email-back');
    var codeInput = document.getElementById('staff-portal-code-input');
    var emailDisplay = document.getElementById('staff-portal-email-display');
    var errorEl = document.getElementById('staff-portal-email-error');
    var stepEmail = root.querySelector('[data-step="email"]');
    var stepCode = root.querySelector('[data-step="code"]');

    var currentEmail = '';
    var resendTimer = null;

    function showError(message) {
        if (!errorEl) {
            return;
        }
        if (!message) {
            errorEl.hidden = true;
            errorEl.textContent = '';
            return;
        }
        errorEl.hidden = false;
        errorEl.textContent = message;
        if (typeof errorEl.scrollIntoView === 'function') {
            errorEl.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        }
    }

    function setBusy(button, busy) {
        if (!button) {
            return;
        }
        button.disabled = !!busy;
        button.setAttribute('aria-busy', busy ? 'true' : 'false');
    }

    function postJson(url, payload) {
        return fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
            },
            credentials: 'same-origin',
            body: JSON.stringify(payload),
        }).then(function (response) {
            return response.json().catch(function () {
                return { ok: false, error: 'Unexpected server response.', code: 'PARSE_ERROR' };
            }).then(function (data) {
                return { response: response, data: data };
            });
        });
    }

    function mapError(data) {
        var code = String((data && data.code) || '');
        var fallback = (data && data.error) || 'Something went wrong. Please try again.';

        if (code === 'STAFF_NOT_FOUND') {
            return 'No approved staff profile found for this email. Register first or use the email on your staff record.';
        }
        if (code === 'BLACKLISTED') {
            return 'Access denied for this account.';
        }
        if (code === 'OTP_EXPIRED') {
            return 'This code has expired. Request a new verification code.';
        }
        if (code === 'INVALID_OTP') {
            return 'That code is not correct. Check the email and try again.';
        }
        if (code === 'RATE_LIMITED') {
            return 'Please wait a moment before requesting another code.';
        }
        if (code === 'OTP_SEND_FAILED') {
            return 'We could not send the email. Try again shortly.';
        }

        return fallback;
    }

    function showCodeStep(email) {
        currentEmail = email;
        if (emailDisplay) {
            emailDisplay.textContent = email;
        }
        if (stepEmail) {
            stepEmail.hidden = true;
        }
        if (stepCode) {
            stepCode.hidden = false;
        }
        if (codeInput) {
            codeInput.value = '';
            codeInput.focus();
        }
    }

    function showEmailStep() {
        if (stepCode) {
            stepCode.hidden = true;
        }
        if (stepEmail) {
            stepEmail.hidden = false;
        }
        if (panel) {
            panel.hidden = false;
        }
        if (toggleBtn) {
            toggleBtn.hidden = true;
        }
        showError('');
    }

    if (toggleBtn && panel) {
        toggleBtn.addEventListener('click', function () {
            panel.hidden = false;
            toggleBtn.hidden = true;
            if (emailInput) {
                emailInput.focus();
            }
        });
    }

    if (sendBtn) {
        sendBtn.addEventListener('click', function () {
            showError('');
            var email = emailInput ? String(emailInput.value || '').trim().toLowerCase() : '';
            if (!email || email.indexOf('@') < 1) {
                showError('Enter a valid email address.');
                return;
            }

            setBusy(sendBtn, true);
            postJson(sendUrl, { email: email, csrf_token: csrf }).then(function (result) {
                setBusy(sendBtn, false);
                if (!result.data || !result.data.ok) {
                    showError(mapError(result.data));
                    return;
                }
                showCodeStep(email);
            }).catch(function () {
                setBusy(sendBtn, false);
                showError('Could not reach the server. Check your connection and try again.');
            });
        });
    }

    if (verifyBtn) {
        verifyBtn.addEventListener('click', function () {
            showError('');
            var code = codeInput ? String(codeInput.value || '').replace(/\s+/g, '') : '';
            if (!/^\d{6}$/.test(code)) {
                showError('Enter the 6-digit verification code from your email.');
                return;
            }

            setBusy(verifyBtn, true);
            postJson(verifyUrl, {
                email: currentEmail,
                code: code,
                csrf_token: csrf,
            }).then(function (result) {
                setBusy(verifyBtn, false);
                if (!result.data || !result.data.ok) {
                    showError(mapError(result.data));
                    return;
                }
                window.location.href = result.data.redirect || 'staff-app.php';
            }).catch(function () {
                setBusy(verifyBtn, false);
                showError('Could not verify the code. Try again.');
            });
        });
    }

    if (backBtn) {
        backBtn.addEventListener('click', function () {
            showEmailStep();
            if (emailInput) {
                emailInput.focus();
            }
        });
    }
})();
