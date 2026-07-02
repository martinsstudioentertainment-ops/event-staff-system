/**

 * Multi-line personal invoice calculator.

 */

(function () {

    'use strict';



    var form = document.getElementById('personal-invoice-form');

    if (!form) {

        return;

    }



    var container = document.getElementById('personal-lines-container');

    var template = document.getElementById('personal-line-template');

    var addBtn = document.getElementById('personal-add-line');

    var totalValueEl = document.getElementById('personal-live-total-value');

    var hoursLabelEl = document.getElementById('personal-live-hours-label');

    var jobsLabelEl = document.getElementById('personal-live-jobs-label');

    var lineIndex = container ? container.querySelectorAll('[data-line]').length : 0;



    var currency = (form.getAttribute('data-currency') || 'EUR').toUpperCase();



    function formatMoney(n) {

        try {

            return new Intl.NumberFormat(undefined, { style: 'currency', currency: currency }).format(n);

        } catch (e) {

            return currency + ' ' + n.toFixed(2);

        }

    }



    function lineAmount(hours, rate, overrideRaw) {

        if (overrideRaw !== '') {

            return Math.max(0, parseFloat(overrideRaw) || 0);

        }

        return Math.round(hours * rate * 100) / 100;

    }



    function refreshRemoveButtons() {

        var lines = container.querySelectorAll('[data-line]');

        lines.forEach(function (line) {

            var btn = line.querySelector('.personal-remove-line');

            if (btn) {

                btn.disabled = lines.length <= 1;

            }

        });

    }



    function reindexLines() {

        container.querySelectorAll('[data-line]').forEach(function (line, index) {

            line.querySelectorAll('[name^="lines["]').forEach(function (input) {

                input.name = input.name.replace(/lines\[\d+]/, 'lines[' + index + ']');

            });

        });

        lineIndex = container.querySelectorAll('[data-line]').length;

        refreshRemoveButtons();

    }



    function pickedWorkLogTotals() {

        var hours = 0;

        var amount = 0;

        var count = 0;

        form.querySelectorAll('.personal-work-log-pick:checked').forEach(function (el) {

            hours += parseFloat(el.getAttribute('data-hours') || '0') || 0;

            amount += parseFloat(el.getAttribute('data-amount') || '0') || 0;

            count += 1;

        });

        return { hours: hours, amount: amount, count: count };

    }



    function refresh() {

        var totalHours = 0;

        var totalAmount = 0;

        var lineCount = 0;



        container.querySelectorAll('[data-line]').forEach(function (line) {

            var hoursEl = line.querySelector('[data-name="hours"], [name*="[hours]"]');

            var rateEl = line.querySelector('[data-name="hourly_rate"], [name*="[hourly_rate]"]');

            var amountEl = line.querySelector('[data-name="line_amount"], [name*="[line_amount]"]');

            var descEl = line.querySelector('[data-name="description"], [name*="[description]"]');



            var desc = descEl ? String(descEl.value || '').trim() : '';

            var hours = Math.max(0, parseFloat(hoursEl && hoursEl.value) || 0);

            var rate = Math.max(0, parseFloat(rateEl && rateEl.value) || 0);

            var overrideRaw = amountEl ? String(amountEl.value || '').trim() : '';

            var amount = lineAmount(hours, rate, overrideRaw);



            if (desc !== '' || hours > 0 || amount > 0) {

                lineCount += 1;

                totalHours += hours;

                totalAmount += amount;

            }

        });



        var picked = pickedWorkLogTotals();

        totalHours += picked.hours;

        totalAmount += picked.amount;



        if (hoursLabelEl) {

            hoursLabelEl.textContent = totalHours.toFixed(2);

        }

        if (jobsLabelEl) {

            jobsLabelEl.textContent = String(lineCount) + (picked.count > 0 ? ' + ' + picked.count + ' saved' : '');

        }

        if (totalValueEl) {

            totalValueEl.textContent = formatMoney(Math.round(totalAmount * 100) / 100);

        }

    }



    function bindLine(line) {

        line.querySelectorAll('.personal-line-calc, .personal-line-amount').forEach(function (el) {

            el.addEventListener('input', refresh);

            el.addEventListener('change', refresh);

        });

        var desc = line.querySelector('[data-name="description"], [name*="[description]"]');

        if (desc) {

            desc.addEventListener('input', refresh);

        }

        var removeBtn = line.querySelector('.personal-remove-line');

        if (removeBtn) {

            removeBtn.addEventListener('click', function () {

                if (container.querySelectorAll('[data-line]').length <= 1) {

                    return;

                }

                line.remove();

                reindexLines();

                refresh();

            });

        }

    }



    container.querySelectorAll('[data-line]').forEach(bindLine);



    if (addBtn && template) {

        addBtn.addEventListener('click', function () {

            var clone = template.content.cloneNode(true);

            var line = clone.querySelector('[data-line]');

            if (!line) {

                return;

            }

            line.querySelectorAll('[data-name]').forEach(function (input) {

                var key = input.getAttribute('data-name');

                input.setAttribute('name', 'lines[' + lineIndex + '][' + key + ']');

                input.removeAttribute('data-name');

            });

            container.appendChild(clone);

            bindLine(container.lastElementChild);

            lineIndex += 1;

            refreshRemoveButtons();

            refresh();

        });

    }



    form.querySelectorAll('.personal-work-log-pick').forEach(function (el) {

        el.addEventListener('change', refresh);

    });



    refresh();

})();

