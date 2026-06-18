/**
 * Live total calculator for saved job records.
 */
(function () {
    'use strict';

    var form = document.getElementById('job-record-form');
    if (!form) {
        return;
    }

    var staffEl = document.getElementById('staff_count');
    var hoursEl = document.getElementById('hours_per_staff');
    var rateEl = document.getElementById('hourly_rate');
    var overrideEl = document.getElementById('total_amount');
    var totalValueEl = document.getElementById('job-live-total-value');
    var hoursLabelEl = document.getElementById('job-live-hours-label');

    var currency = (form.getAttribute('data-currency') || 'EUR').toUpperCase();

    function formatMoney(n) {
        try {
            return new Intl.NumberFormat(undefined, { style: 'currency', currency: currency }).format(n);
        } catch (e) {
            return currency + ' ' + n.toFixed(2);
        }
    }

    function refresh() {
        var staff = Math.max(0, parseInt(staffEl && staffEl.value, 10) || 0);
        var hoursEach = Math.max(0, parseFloat(hoursEl && hoursEl.value) || 0);
        var rate = Math.max(0, parseFloat(rateEl && rateEl.value) || 0);
        var overrideRaw = overrideEl ? String(overrideEl.value || '').trim() : '';
        var totalHours = Math.round(staff * hoursEach * 100) / 100;
        var total = overrideRaw !== ''
            ? Math.max(0, parseFloat(overrideRaw) || 0)
            : Math.round(totalHours * rate * 100) / 100;

        if (hoursLabelEl) {
            hoursLabelEl.textContent = totalHours.toFixed(2);
        }
        if (totalValueEl) {
            totalValueEl.textContent = formatMoney(total);
        }
    }

    form.querySelectorAll('.job-calc, .job-calc-override').forEach(function (el) {
        el.addEventListener('input', refresh);
        el.addEventListener('change', refresh);
    });

    refresh();
})();
