(function () {
    'use strict';

    var root = document.getElementById('registration-email-otp');
    if (!root) {
        return;
    }

    var sendUrl = root.getAttribute('data-send-url') || 'api/registration-email-otp-send.php';
    var verifyUrl = root.getAttribute('data-verify-url') || 'api/registration-email-otp-verify.php';
    var returnUrl = root.getAttribute('data-return-url') || 'index.php';

    var emailInput = document.getElementById('registration-email-input');
    var sendBtn = document.getElementById('registration-email-send');
    var verifyBtn = document.getElementById('registration-email-verify');
    var backBtn = document.getElementById('registration-email-back');
    var codeInput = document.getElementById('registration-code-input');
    var emailDisplay = document.getElementById('registration-email-display');
    var errorEl = document.getElementById('registration-email-error');
    var stepEmail = root.querySelector('[data-step="email"]');
    var stepCode = root.querySelector('[data-step="code"]');

    var currentEmail = '';

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
    }

    function setBusy(button, busy) {
        if (!button) {
            return;
        }
        button.disabled = !!busy;
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
                return { ok: false, error: 'Unexpected server response.' };
            }).then(function (data) {
                return { response: response, data: data };
            });
        });
    }

    function mapError(data) {
        var code = String((data && data.code) || '');
        var fallback = (data && data.error) || 'Something went wrong. Please try again.';

        if (code === 'OTP_EXPIRED') {
            return 'This code has expired. Request a new verification code.';
        }
        if (code === 'INVALID_OTP') {
            return 'That code is not correct. Check your email and try again.';
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
        showError('');
    }

    if (sendBtn) {
        sendBtn.addEventListener('click', function () {
            showError('');
            var email = emailInput ? String(emailInput.value || '').trim().toLowerCase() : '';
            if (!email || email.indexOf('@') < 1) {
                showError('Enter a valid email address (Gmail, Outlook, Yahoo, work email, etc.).');
                return;
            }

            setBusy(sendBtn, true);
            postJson(sendUrl, { email: email }).then(function (result) {
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
            }).then(function (result) {
                setBusy(verifyBtn, false);
                if (!result.data || !result.data.ok) {
                    showError(mapError(result.data));
                    return;
                }
                window.location.href = returnUrl;
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
