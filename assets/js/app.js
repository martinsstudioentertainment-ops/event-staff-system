/**
 * Event Staff System — Main Application
 * Component loader, sidebar, theme toggle, form validation.
 */

(function () {
    'use strict';

    const THEME_KEY = 'eventStaffTheme';

    function getBasePath() {
        return window.EVENT_STAFF_BASE || '';
    }

    function getComponents() {
        const base = getBasePath();
        return {
            sidebar: base + 'includes/components/sidebar.html',
            header: base + 'includes/components/header.html',
            footer: base + 'includes/components/footer.html'
        };
    }

    async function loadComponent(url, targetId) {
        const container = document.getElementById(targetId);
        if (!container) return;

        try {
            const response = await fetch(url);
            if (!response.ok) throw new Error('Failed to load ' + url);
            container.innerHTML = await response.text();
        } catch (err) {
            console.warn('[EventStaff] Component load failed:', url, err.message);
        }
    }

    async function loadLayout() {
        if (document.body.dataset.registrationPage === 'true') {
            applySiteName();
            initThemeToggle();
            return;
        }

        const components = getComponents();

        await Promise.all([
            loadComponent(components.sidebar, 'sidebar-container'),
            loadComponent(components.header, 'header-container'),
            loadComponent(components.footer, 'footer-container')
        ]);

        fixNavigationPaths();
        setPageTitle();
        applySiteName();
        initSidebar();
        initThemeToggle();
        highlightActiveNav();
    }

    function fixNavigationPaths() {
        const base = getBasePath();

        document.querySelectorAll('.sidebar__link[data-page="register"]').forEach(function (link) {
            link.setAttribute('href', base + 'index.php');
        });

        document.querySelectorAll('.sidebar__link[data-page="admin"]').forEach(function (link) {
            link.setAttribute('href', base + 'admin/login.php');
        });
    }

    function setPageTitle() {
        const title = document.body.dataset.pageTitle;
        const el = document.getElementById('page-title');
        if (title && el) el.textContent = title;
    }

    function applySiteName() {
        const siteName = document.body.dataset.siteName;
        if (!siteName) return;

        const sidebarTitle = document.querySelector('.sidebar__title');
        if (sidebarTitle) sidebarTitle.textContent = siteName;

        const publicTitle = document.querySelector('.public-header__title');
        if (publicTitle) publicTitle.textContent = siteName;

        document.title = document.title.replace('Event Staff System', siteName);
    }

    function initSidebar() {
        const menuBtn = document.getElementById('menu-toggle');
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('sidebar-overlay');

        if (!menuBtn || !sidebar) return;

        function closeSidebar() {
            sidebar.classList.remove('sidebar--open');
            if (overlay) overlay.classList.remove('sidebar-overlay--visible');
            document.body.style.overflow = '';
        }

        function openSidebar() {
            sidebar.classList.add('sidebar--open');
            if (overlay) overlay.classList.add('sidebar-overlay--visible');
            document.body.style.overflow = 'hidden';
        }

        menuBtn.addEventListener('click', function () {
            if (sidebar.classList.contains('sidebar--open')) {
                closeSidebar();
            } else {
                openSidebar();
            }
        });

        if (overlay) overlay.addEventListener('click', closeSidebar);

        sidebar.querySelectorAll('.sidebar__link:not(.sidebar__link--disabled)').forEach(function (link) {
            link.addEventListener('click', function () {
                if (window.innerWidth <= 768) closeSidebar();
            });
        });
    }

    function getStoredTheme() {
        return localStorage.getItem(THEME_KEY) || 'light';
    }

    function applyTheme(theme) {
        document.documentElement.setAttribute('data-theme', theme === 'dark' ? 'dark' : 'light');

        const toggle = document.getElementById('theme-toggle');
        if (toggle) {
            toggle.textContent = theme === 'dark' ? '☀️' : '🌙';
            toggle.setAttribute('aria-label', theme === 'dark' ? 'Switch to light mode' : 'Switch to dark mode');
        }
    }

    function initThemeToggle() {
        const saved = getStoredTheme();
        applyTheme(saved);

        const toggle = document.getElementById('theme-toggle');
        if (!toggle) return;

        toggle.addEventListener('click', function () {
            const current = getStoredTheme();
            const next = current === 'dark' ? 'light' : 'dark';
            localStorage.setItem(THEME_KEY, next);
            applyTheme(next);
        });
    }

    function highlightActiveNav() {
        const currentPath = window.location.pathname;

        document.querySelectorAll('.sidebar__link[data-page]').forEach(function (link) {
            link.classList.remove('sidebar__link--active');

            if (currentPath.includes('admin') && link.dataset.page === 'admin') {
                link.classList.add('sidebar__link--active');
            } else if (!currentPath.includes('admin') && link.dataset.page === 'register') {
                link.classList.add('sidebar__link--active');
            }
        });
    }

    function showFieldError(fieldId, message) {
        const field = document.getElementById(fieldId);
        const errorEl = document.getElementById(fieldId + '-error');

        if (field) {
            if (field.tagName === 'SELECT') {
                field.classList.add('form-select--error');
            } else {
                field.classList.add('form-input--error');
            }
        }

        if (fieldId === 'event_ids') {
            const shiftList = document.getElementById('shift-picker-list');
            if (shiftList) shiftList.classList.add('shift-picker-list--error');
        }

        if (errorEl) {
            errorEl.textContent = message;
            errorEl.classList.add('form-error--visible');
        }
    }

    function clearErrors(form) {
        form.querySelectorAll('.form-input--error, .form-select--error').forEach(function (el) {
            el.classList.remove('form-input--error', 'form-select--error');
        });
        form.querySelectorAll('.form-error--visible').forEach(function (el) {
            el.classList.remove('form-error--visible');
            el.textContent = '';
        });

        const shiftList = document.getElementById('shift-picker-list');
        if (shiftList) shiftList.classList.remove('shift-picker-list--error');
    }

    function validateForm(form) {
        clearErrors(form);
        let isValid = true;

        if (typeof REGISTRATION_FIELDS !== 'undefined') {
            REGISTRATION_FIELDS.forEach(function (field) {
                if (field.name === 'gender' || field.name === 'staff_role') return;

                const el = document.getElementById(field.name);
                if (!el || !el.value.trim()) {
                    showFieldError(field.name, field.label + ' is required.');
                    isValid = false;
                }
            });
        }

        const emailEl = document.getElementById('email');
        if (emailEl && emailEl.value.trim() && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(emailEl.value.trim())) {
            showFieldError('email', 'Please enter a valid email address.');
            isValid = false;
        }

        const eircodeEl = document.getElementById('eircode');
        if (eircodeEl && eircodeEl.value.trim() && typeof isValidEircode === 'function' && !isValidEircode(eircodeEl.value)) {
            showFieldError('eircode', 'Please enter a valid Eircode (e.g. D02 X285).');
            isValid = false;
        }

        if (!form.querySelector('input[name="gender"]:checked')) {
            const genderError = document.getElementById('gender-error');
            if (genderError) {
                genderError.textContent = 'Please select a gender.';
                genderError.classList.add('form-error--visible');
            }
            isValid = false;
        }

        if (!form.querySelector('input[name="staff_role"]')?.value && !document.body.dataset.lockedRole) {
            const roleError = document.getElementById('staff_role-error');
            if (roleError) {
                roleError.textContent = 'Please select a registration form type.';
                roleError.classList.add('form-error--visible');
            }
            isValid = false;
        }

        const pickedFormSlug = document.querySelector('input[name="form_slug"]:checked');
        const formSlugEl = document.getElementById('form_slug');
        if (!pickedFormSlug && formSlugEl && formSlugEl.tagName === 'SELECT' && !formSlugEl.value) {
            showFieldError('form_slug', 'Please select your role.');
            isValid = false;
        }
        if (!pickedFormSlug && !formSlugEl && !document.querySelector('input[type="hidden"][name="form_slug"]')) {
            showFieldError('form_slug', 'Please select your role.');
            isValid = false;
        }

        const shiftList = document.getElementById('shift-picker-list');
        const checkedShifts = shiftList
            ? shiftList.querySelectorAll('input[name="event_ids[]"]:checked:not(:disabled)')
            : [];
        if (!shiftList || checkedShifts.length === 0) {
            showFieldError('event_ids', 'Please tick at least one shift you want to work.');
            isValid = false;
        }

        const privacyEl = form.querySelector('input[name="privacy_consent"]');
        if (privacyEl && !privacyEl.checked) {
            showFieldError('privacy_consent', 'You must agree to the privacy notice before registering.');
            isValid = false;
        }

        return isValid;
    }

    function isBackendSubmit() {
        return document.body.dataset.backendSubmit === 'true';
    }

    function showAlert(message, type) {
        const alertEl = document.getElementById('form-alert');
        if (!alertEl) return;
        alertEl.textContent = message;
        alertEl.className = 'alert alert--' + type + ' alert--visible';
    }

    function applyServerErrors(errors) {
        if (!errors || typeof errors !== 'object') return;
        Object.keys(errors).forEach(function (field) {
            showFieldError(field, errors[field]);
        });
    }

    function initFlashMessages() {
        const flash = document.body.dataset.flash;
        if (!flash) return;

        if (flash === 'success') {
            const count = parseInt(document.body.dataset.registeredCount || '1', 10);
            const message = count === 1
                ? 'Registration submitted successfully for 1 event! Your application is pending approval.'
                : 'Registration submitted successfully for ' + count + ' events! Your applications are pending approval.';
            showAlert(message, 'success');
        } else if (flash === 'db') {
            showAlert('We could not save your registration. Please try again in a few minutes.', 'error');
        } else if (flash === 'validation') {
            showAlert('Please correct the highlighted fields before submitting.', 'error');
        }

        if (typeof window.SERVER_FORM_ERRORS !== 'undefined') {
            applyServerErrors(window.SERVER_FORM_ERRORS);
        }
    }

    function resetFormAfterSuccess(form) {
        form.reset();
        const shiftList = document.getElementById('shift-picker-list');
        if (shiftList) {
            shiftList.dataset.selected = '[]';
            if (typeof setRegisteredEventIds === 'function') {
                setRegisteredEventIds([]);
            } else if (typeof refreshShiftPicker === 'function') {
                refreshShiftPicker([]);
            }
        }
    }

    function getFormDataObject(form) {
        const data = {};
        const eventIds = [];
        new FormData(form).forEach(function (value, key) {
            if (key === 'event_ids[]') {
                eventIds.push(value);
            } else {
                data[key] = value;
            }
        });
        data.event_ids = eventIds;
        return data;
    }

    function handleSubmitPreview(form) {
        const alertEl = document.getElementById('form-alert');

        if (!validateForm(form)) {
            if (alertEl) {
                alertEl.textContent = 'Please correct the highlighted fields before submitting.';
                alertEl.className = 'alert alert--error alert--visible';
            }
            var firstError = form.querySelector('.form-error--visible');
            if (firstError) {
                var fieldId = (firstError.id || '').replace(/-error$/, '');
                var field = fieldId ? document.getElementById(fieldId) : null;
                if (field && typeof field.scrollIntoView === 'function') {
                    field.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
            }
            return;
        }

        console.log('[EventStaff] Registration preview:', getFormDataObject(form));

        if (alertEl) {
            alertEl.textContent = 'Registration preview saved locally. Use index.php via Laragon to save to the database.';
            alertEl.className = 'alert alert--success alert--visible';
        }

        resetFormAfterSuccess(form);
    }

    async function handleSubmitBackend(form) {
        const alertEl = document.getElementById('form-alert');
        const submitBtn = form.querySelector('[type="submit"]');

        if (!validateForm(form)) {
            if (alertEl) {
                alertEl.textContent = 'Please correct the highlighted fields before submitting.';
                alertEl.className = 'alert alert--error alert--visible';
            }
            var firstError = form.querySelector('.form-error--visible');
            if (firstError) {
                var fieldId = (firstError.id || '').replace(/-error$/, '');
                var field = fieldId ? document.getElementById(fieldId) : null;
                if (field && typeof field.scrollIntoView === 'function') {
                    field.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
            }
            return;
        }

        if (submitBtn) submitBtn.disabled = true;

        try {
            const response = await fetch(form.action, {
                method: 'POST',
                body: new FormData(form),
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });

            const result = await response.json();

            if (result.success) {
                showAlert(result.message || 'Registration submitted successfully!', 'success');
                resetFormAfterSuccess(form);
                return;
            }

            if (result.errors) applyServerErrors(result.errors);
            showAlert(result.message || 'Please correct the highlighted fields before submitting.', 'error');
        } catch (err) {
            showAlert('We could not reach the server. Check your connection and try again.', 'error');
        } finally {
            if (submitBtn) submitBtn.disabled = false;
        }
    }

    async function initRegistrationForm() {
        const form = document.getElementById('registration-form');
        if (typeof initShiftSelection === 'function') {
            await initShiftSelection();
        } else if (typeof initVenueEventSelection === 'function') {
            await initVenueEventSelection();
        }
        if (!form) return;

        function syncRoleFromFormSlug() {
            const roleInput = document.getElementById('staff_role');
            const picked = document.querySelector('input[name="form_slug"]:checked');
            const select = document.getElementById('form_slug');
            if (roleInput && picked && picked.dataset.role) {
                roleInput.value = picked.dataset.role;
                window.REGISTRATION_FORM_SLUG = picked.value;
            } else if (select && select.tagName === 'SELECT' && select.selectedOptions[0]) {
                const option = select.selectedOptions[0];
                if (roleInput && option.dataset.role) {
                    roleInput.value = option.dataset.role;
                }
                window.REGISTRATION_FORM_SLUG = select.value;
            }
        }
        document.querySelectorAll('input[name="form_slug"]').forEach(function (el) {
            el.addEventListener('change', syncRoleFromFormSlug);
        });
        const formSlugSelect = document.getElementById('form_slug');
        if (formSlugSelect && formSlugSelect.tagName === 'SELECT') {
            formSlugSelect.addEventListener('change', syncRoleFromFormSlug);
        }
        syncRoleFromFormSlug();

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            if (isBackendSubmit()) {
                handleSubmitBackend(form);
            } else {
                handleSubmitPreview(form);
            }
        });

        form.addEventListener('reset', function () {
            clearErrors(form);
            const alertEl = document.getElementById('form-alert');
            if (alertEl) alertEl.className = 'alert';
            const shiftList = document.getElementById('shift-picker-list');
            const venueInput = document.getElementById('venue_id');
            if (venueInput) {
                venueInput.value = '0';
            }
            if (shiftList) {
                shiftList.dataset.selected = '[]';
                if (typeof refreshShiftPicker === 'function') {
                    refreshShiftPicker([]);
                }
            }
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        applyTheme(getStoredTheme());
        loadLayout().then(function () {
            initRegistrationForm();
            initFlashMessages();
        });
    });
})();
