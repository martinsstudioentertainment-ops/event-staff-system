(function () {
    'use strict';

    var form = document.getElementById('commission-invoice-form');
    var table = document.getElementById('invoice-lines-table');
    if (!form || !table) {
        return;
    }

    function parseNum(value) {
        var n = parseFloat(value);
        return Number.isFinite(n) ? n : 0;
    }

    function formatHours(n) {
        return n.toFixed(2) + ' h';
    }

    function formatMoney(n) {
        var bar = document.getElementById('invoice-totals-bar');
        var code = bar && bar.getAttribute('data-currency') ? bar.getAttribute('data-currency') + ' ' : '';
        return code + n.toFixed(2);
    }

    function recalcRow(row) {
        var override = row.querySelector('.invoice-line__override');
        var hoursBilled = row.querySelector('.invoice-line__hours-billed');
        var rate = row.querySelector('.invoice-line__rate');
        var amount = row.querySelector('.invoice-line__amount');

        if (!override || !hoursBilled || !rate || !amount) {
            return parseNum(amount ? amount.value : 0);
        }

        if (!override.checked) {
            amount.value = formatMoney(parseNum(hoursBilled.value) * parseNum(rate.value));
        }

        return parseNum(amount.value);
    }

    function updateTotals() {
        var rows = table.querySelectorAll('.invoice-line');
        var staff = rows.length;
        var hoursWorked = 0;
        var hoursBilled = 0;
        var amount = 0;

        rows.forEach(function (row) {
            hoursWorked += parseNum(row.querySelector('.invoice-line__hours-worked')?.value);
            hoursBilled += parseNum(row.querySelector('.invoice-line__hours-billed')?.value);
            amount += recalcRow(row);
        });

        var bar = document.getElementById('invoice-totals-bar');
        if (bar) {
            var staffEl = bar.querySelector('[data-total="staff"]');
            var hwEl = bar.querySelector('[data-total="hours-worked"]');
            var hbEl = bar.querySelector('[data-total="hours-billed"]');
            var amtEl = bar.querySelector('[data-total="amount"]');
            if (staffEl) staffEl.textContent = String(staff);
            if (hwEl) hwEl.textContent = formatHours(hoursWorked);
            if (hbEl) hbEl.textContent = formatHours(hoursBilled);
            if (amtEl) amtEl.textContent = formatMoney(amount);
        }

        var hwFoot = table.querySelector('[data-total="hours-worked-foot"]');
        var hbFoot = table.querySelector('[data-total="hours-billed-foot"]');
        var amtFoot = table.querySelector('[data-total="amount-foot"]');
        if (hwFoot) hwFoot.textContent = formatHours(hoursWorked);
        if (hbFoot) hbFoot.textContent = formatHours(hoursBilled);
        if (amtFoot) amtFoot.textContent = formatMoney(amount);
    }

    table.addEventListener('input', function (event) {
        if (event.target.matches('.invoice-line__hours-worked, .invoice-line__hours-billed, .invoice-line__rate, .invoice-line__amount')) {
            updateTotals();
        }
    });

    table.addEventListener('change', function (event) {
        if (event.target.matches('.invoice-line__override')) {
            updateTotals();
        }
    });

    updateTotals();
})();
