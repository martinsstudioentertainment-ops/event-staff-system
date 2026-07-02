/**
 * Staff inbox — search picker to start a new chat thread.
 */
(function () {
    'use strict';

    var root = document.getElementById('staff-chat-picker');
    if (!root) {
        return;
    }

    var input = document.getElementById('staff-chat-picker-q');
    var list = document.getElementById('staff-chat-picker-results');
    var apiUrl = root.getAttribute('data-search-url') || '../api/admin-staff-search.php';
    var timer = null;
    var lastQuery = '';

    function clearResults() {
        if (!list) {
            return;
        }
        list.innerHTML = '';
        list.hidden = true;
    }

    function renderResults(items) {
        if (!list) {
            return;
        }

        list.innerHTML = '';

        if (!items || items.length === 0) {
            var empty = document.createElement('li');
            empty.className = 'msg-picker-empty';
            empty.textContent = 'No staff found — try name or email.';
            list.appendChild(empty);
            list.hidden = false;
            return;
        }

        items.forEach(function (item) {
            var li = document.createElement('li');
            var link = document.createElement('a');
            link.className = 'msg-picker-item';
            link.href = 'staff-inbox-thread.php?staff_id=' + encodeURIComponent(String(item.id));
            link.innerHTML =
                '<span class="msg-picker-item__name"></span>' +
                '<span class="msg-picker-item__email"></span>';
            link.querySelector('.msg-picker-item__name').textContent = item.name || 'Staff';
            link.querySelector('.msg-picker-item__email').textContent = item.email || '';
            li.appendChild(link);
            list.appendChild(li);
        });

        list.hidden = false;
    }

    function search(query) {
        if (query.length < 2) {
            clearResults();
            return;
        }

        if (query === lastQuery) {
            return;
        }

        lastQuery = query;

        fetch(apiUrl + '?q=' + encodeURIComponent(query), {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
        })
            .then(function (res) {
                return res.json();
            })
            .then(function (data) {
                if (!data || !data.ok) {
                    clearResults();
                    return;
                }
                renderResults(data.results || []);
            })
            .catch(function () {
                clearResults();
            });
    }

    if (!input) {
        return;
    }

    input.addEventListener('input', function () {
        var query = String(input.value || '').trim();
        clearTimeout(timer);
        timer = setTimeout(function () {
            search(query);
        }, 250);
    });

    input.addEventListener('focus', function () {
        var query = String(input.value || '').trim();
        if (query.length >= 2) {
            search(query);
        }
    });

    document.addEventListener('click', function (event) {
        if (!root.contains(event.target)) {
            clearResults();
        }
    });
})();
