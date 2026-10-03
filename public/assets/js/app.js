/* =====================================================================
   CAHU Platform - front-end behaviour
   Progressive enhancement only: every feature below also works without
   JavaScript through a normal page load or form post.
   ===================================================================== */

(function () {
    'use strict';

    /* ---------------------------------------------------------------- */
    /* CSRF token, attached to every fetch() call                       */
    /* ---------------------------------------------------------------- */

    window.Cahu = window.Cahu || {};

    window.Cahu.csrf = function () {
        var input = document.querySelector('input[name="_token"]');
        if (input) return input.value;
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : '';
    };

    window.Cahu.get = function (url) {
        var separator = url.indexOf('?') === -1 ? '?' : '&';
        return fetch(url + separator + '_token=' + encodeURIComponent(window.Cahu.csrf()), {
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
            credentials: 'same-origin'
        }).then(function (response) { return response.json(); });
    };

    window.Cahu.post = function (url, data) {
        var body = new FormData();
        body.append('_token', window.Cahu.csrf());
        Object.keys(data || {}).forEach(function (key) { body.append(key, data[key]); });

        return fetch(url, {
            method: 'POST',
            body: body,
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
            credentials: 'same-origin'
        }).then(function (response) {
            return response.json().catch(function () { return { success: false, message: 'Unexpected server response.' }; });
        });
    };

    /* ---------------------------------------------------------------- */
    /* Toast notifications                                              */
    /* ---------------------------------------------------------------- */

    window.Cahu.toast = function (message, type) {
        var host = document.getElementById('cahu-toasts');
        if (!host) {
            host = document.createElement('div');
            host.id = 'cahu-toasts';
            host.style.cssText = 'position:fixed;top:70px;right:16px;z-index:2000;display:flex;flex-direction:column;gap:8px;max-width:340px';
            document.body.appendChild(host);
        }

        var tones = {
            success: ['#d7f0e1', '#14603a', 'M9 12l2 2 4-4'],
            danger: ['#fde3e1', '#8c1d18', 'M12 8v5m0 3h.01M10.3 3.9L2.6 17a2 2 0 001.7 3h15.4a2 2 0 001.7-3L13.7 3.9a2 2 0 00-3.4 0z'],
            warning: ['#fdeed6', '#8a4b0f', 'M12 9v4m0 4h.01M12 3a9 9 0 100 18 9 9 0 000-18z'],
            info: ['#ddeefb', '#0a4a78', 'M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z']
        };
        var tone = tones[type] || tones.info;

        var el = document.createElement('div');
        el.setAttribute('role', 'status');
        el.style.cssText = 'display:flex;gap:9px;align-items:flex-start;padding:10px 12px;border-radius:8px;'
            + 'box-shadow:0 4px 16px rgba(16,41,28,.14);font-size:.855rem;line-height:1.4;'
            + 'background:' + tone[0] + ';color:' + tone[1];
        el.innerHTML = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" '
            + 'stroke-linecap="round" stroke-linejoin="round" style="flex:0 0 16px;margin-top:2px"><path d="' + tone[2] + '"/></svg>'
            + '<span></span>';
        el.querySelector('span').textContent = message;
        host.appendChild(el);

        setTimeout(function () {
            el.style.transition = 'opacity .25s ease';
            el.style.opacity = '0';
            setTimeout(function () { el.remove(); }, 260);
        }, 4200);
    };

    /* ---------------------------------------------------------------- */
    /* Confirmations                                                    */
    /* ---------------------------------------------------------------- */

    document.addEventListener('submit', function (event) {
        var form = event.target;
        var message = form.getAttribute('data-confirm');
        if (message && !window.confirm(message)) {
            event.preventDefault();
        }
    });

    /* ---------------------------------------------------------------- */
    /* Sidebar (mobile)                                                  */
    /* ---------------------------------------------------------------- */

    function initSidebar() {
        var toggle = document.getElementById('cahu-nav-toggle');
        var sidebar = document.getElementById('cahu-sidebar');
        if (!toggle || !sidebar) return;

        var backdrop = null;

        function close() {
            sidebar.classList.remove('is-open');
            if (backdrop) { backdrop.remove(); backdrop = null; }
        }

        toggle.addEventListener('click', function () {
            if (sidebar.classList.contains('is-open')) { close(); return; }
            backdrop = document.createElement('div');
            backdrop.className = 'cahu-sidebar__backdrop';
            backdrop.addEventListener('click', close);
            document.body.appendChild(backdrop);
            sidebar.classList.add('is-open');
        });

        document.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });
    }

    /* ---------------------------------------------------------------- */
    /* Password visibility toggle                                       */
    /* ---------------------------------------------------------------- */

    function initPasswordToggles() {
        document.addEventListener('click', function (event) {
            var button = event.target.closest('.password-field .toggle');
            if (!button) return;
            var input = button.parentElement.querySelector('input');
            if (!input) return;
            var hidden = input.type === 'password';
            input.type = hidden ? 'text' : 'password';
            button.textContent = hidden ? 'Hide' : 'Show';
        });
    }

    /* ---------------------------------------------------------------- */
    /* Client side validation                                           */
    /* ---------------------------------------------------------------- */

    function initValidation() {
        document.addEventListener('submit', function (event) {
            var form = event.target;
            if (!form.hasAttribute('data-validate')) return;

            var problems = [];

            Array.prototype.forEach.call(form.querySelectorAll('[required]'), function (field) {
                if (field.type === 'hidden' || field.disabled) return;
                var value = field.type === 'checkbox' ? field.checked : field.value.trim();
                if (value === '' || value === false) {
                    problems.push((field.getAttribute('data-label') || field.name) + ' is required.');
                }
            });

            Array.prototype.forEach.call(form.querySelectorAll('input[type="email"]'), function (field) {
                if (field.value === '') return;
                if (!/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(field.value.trim())) {
                    problems.push((field.getAttribute('data-label') || 'Email') + ' must be a valid email address.');
                }
            });

            Array.prototype.forEach.call(form.querySelectorAll('input[type="tel"]'), function (field) {
                if (field.value === '') return;
                if (field.value.replace(/[^0-9]/g, '').length < 7) {
                    problems.push((field.getAttribute('data-label') || 'Phone') + ' must be a valid phone number.');
                }
            });

            Array.prototype.forEach.call(form.querySelectorAll('input[type="number"]'), function (field) {
                if (field.value === '' || field.getAttribute('data-allow-negative') !== null) return;
                if (parseFloat(field.value) < 0) {
                    problems.push((field.getAttribute('data-label') || 'Value') + ' cannot be negative.');
                }
            });

            if (problems.length) {
                event.preventDefault();
                window.Cahu.toast(problems[0], 'danger');
            }
        });
    }

    /* ---------------------------------------------------------------- */
    /* Auto-submit filter forms                                          */
    /* ---------------------------------------------------------------- */

    function initFilters() {
        document.querySelectorAll('[data-autosubmit]').forEach(function (field) {
            field.addEventListener('change', function () { field.form.submit(); });
        });

        var searchTimer = null;
        document.querySelectorAll('[data-search-submit]').forEach(function (field) {
            field.addEventListener('input', function () {
                clearTimeout(searchTimer);
                searchTimer = setTimeout(function () { field.form.submit(); }, 550);
            });
        });
    }

    /* ---------------------------------------------------------------- */
    /* Cascading select (district -> sub county -> parish -> village)    */
    /* ---------------------------------------------------------------- */

    function initCascades() {
        document.querySelectorAll('[data-cascade]').forEach(function (select) {
            var config = JSON.parse(select.getAttribute('data-cascade') || '{}');

            select.addEventListener('change', function () {
                var targets = config.targets || [];
                targets.forEach(function (targetId) {
                    var target = document.getElementById(targetId);
                    if (!target) return;
                    target.innerHTML = '<option value="">Loading...</option>';
                    target.disabled = true;

                    if (!select.value) {
                        target.innerHTML = '<option value="">Select...</option>';
                        target.disabled = false;
                        return;
                    }

                    window.Cahu.get('/api/v1/locations?type=' + config.type + '&parent_id=' + encodeURIComponent(select.value))
                        .then(function (data) {
                            target.innerHTML = '<option value="">Select...</option>';
                            (data.data || []).forEach(function (row) {
                                var option = document.createElement('option');
                                option.value = row.id;
                                option.textContent = row.name;
                                target.appendChild(option);
                            });
                            target.disabled = false;
                        })
                        .catch(function () {
                            target.innerHTML = '<option value="">Could not load</option>';
                            target.disabled = false;
                        });
                });
            });
        });
    }

    /* ---------------------------------------------------------------- */
    /* GPS capture                                                      */
    /* ---------------------------------------------------------------- */

    window.Cahu.locate = function (latField, lngField, statusElement, onFound) {
        var status = statusElement ? document.getElementById(statusElement) : null;

        function say(text, className) {
            if (!status) return;
            status.textContent = text;
            status.className = 'gps-status ' + (className || '');
        }

        if (!navigator.geolocation) {
            say('This device does not support GPS.', 'is-err');
            return;
        }

        say('Locating...');

        navigator.geolocation.getCurrentPosition(function (position) {
            var lat = position.coords.latitude.toFixed(7);
            var lng = position.coords.longitude.toFixed(7);
            var latEl = document.getElementById(latField);
            var lngEl = document.getElementById(lngField);

            if (latEl) latEl.value = lat;
            if (lngEl) lngEl.value = lng;

            say('Captured ' + lat + ', ' + lng, 'is-ok');
            if (typeof onFound === 'function') onFound(parseFloat(lat), parseFloat(lng));
        }, function (error) {
            var messages = {
                1: 'Location permission denied. Enter coordinates manually.',
                2: 'Position unavailable. Move to an open area and retry.',
                3: 'GPS timed out. Enter coordinates manually.'
            };
            say(messages[error.code] || 'Could not capture GPS.', 'is-err');
        }, { enableHighAccuracy: true, timeout: 15000, maximumAge: 30000 });
    };

    /* ---------------------------------------------------------------- */
    /* Live global search                                                */
    /* ---------------------------------------------------------------- */

    function initGlobalSearch() {
        var input = document.getElementById('cahu-global-search');
        if (!input) return;

        var box = null;
        var timer = null;

        input.addEventListener('input', function () {
            var term = input.value.trim();
            clearTimeout(timer);

            if (term.length < 2) {
                if (box) { box.remove(); box = null; }
                return;
            }

            timer = setTimeout(function () {
                window.Cahu.get('/search?search=' + encodeURIComponent(term)).then(function (data) {
                    if (box) box.remove();
                    box = document.createElement('div');
                    box.style.cssText = 'position:absolute;top:100%;left:0;right:0;background:#fff;border:1px solid #e2e8e4;'
                        + 'border-radius:0 0 10px 10px;box-shadow:0 8px 28px rgba(16,41,28,.12);max-height:380px;overflow:auto;z-index:1050';

                    var groups = data.results || {};
                    var keys = Object.keys(groups);

                    if (!keys.length) {
                        box.innerHTML = '<div style="padding:14px;font-size:.85rem;color:#7d8b84">No matches for &ldquo;'
                            + term.replace(/[<>&]/g, '') + '&rdquo;</div>';
                    } else {
                        keys.forEach(function (group) {
                            var heading = document.createElement('div');
                            heading.textContent = group;
                            heading.style.cssText = 'padding:7px 12px;font-size:.66rem;text-transform:uppercase;letter-spacing:.08em;'
                                + 'font-weight:700;color:#7d8b84;background:#fafbfa;border-bottom:1px solid #eef2ef';
                            box.appendChild(heading);

                            groups[group].forEach(function (row) {
                                var link = document.createElement('a');
                                link.href = row.url;
                                link.style.cssText = 'display:block;padding:8px 12px;font-size:.855rem;border-bottom:1px solid #f2f5f3;color:#16211b';
                                link.innerHTML = '<span style="font-family:ui-monospace,Menlo,monospace;font-size:.75rem;'
                                    + 'color:#17402c;font-weight:600">' + row.code + '</span> ' + row.label;
                                box.appendChild(link);
                            });
                        });
                    }

                    var host = input.parentElement;
                    host.style.position = 'relative';
                    host.appendChild(box);
                });
            }, 320);
        });

        document.addEventListener('click', function (event) {
            if (box && !input.parentElement.contains(event.target)) { box.remove(); box = null; }
        });
    }

    /* ---------------------------------------------------------------- */
    /* Loading state on submit                                           */
    /* ---------------------------------------------------------------- */

    function initSubmitState() {
        document.addEventListener('submit', function (event) {
            var form = event.target;
            var button = form.querySelector('button[type="submit"]');
            if (!button || form.hasAttribute('data-no-loading')) return;

            setTimeout(function () {
                button.disabled = true;
                var label = button.getAttribute('data-label') || button.innerHTML;
                button.setAttribute('data-label', label);
                button.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Saving...';
            }, 0);
        });
    }

    /* ---------------------------------------------------------------- */
    /* Activity calendar                                                 */
    /* ---------------------------------------------------------------- */

    function initCalendar() {
        var grid = document.querySelector('[data-calendar-events]');

        if (!grid) return;

        var endpoint = grid.getAttribute('data-url');

        if (!endpoint) return;

        Cahu.get(endpoint)
            .then(function (data) {
                (data.events || []).forEach(place);
            })
            .catch(function () {
                var notice = document.createElement('p');
                notice.className = 'text-muted small mb-0';
                notice.textContent = 'The schedule could not be loaded. Use the list view.';
                grid.appendChild(notice);
            });

        function place(event) {
            // Planned and due can differ by a few days, so an activity occupies
            // every day in its window, capped to keep a long window from
            // flooding the month.
            var start = parseDay(event.planned);
            var end   = parseDay(event.start);

            if (start === null || end === null) return;

            var span = Math.min(6, Math.round((end - start) / 86400000) + 1);
            var shown = 0;

            for (var offset = 0; offset < span; offset++) {
                var date = formatDay(start + offset * 86400000);
                var cell = grid.querySelector('[data-date="' + date + '"] [data-day-events]');

                if (!cell || shown >= 3) continue;

                cell.appendChild(chip(event, offset === 0 ? null : date));
                shown++;
            }
        }

        function chip(event, rangeLabel) {
            var link = document.createElement('a');
            link.className = 'cahu-cal__event cahu-cal__event--' + event.status;
            link.href = event.url;
            link.title = event.title + ' — ' + event.farmer + (event.garden ? ' (' + event.garden + ')' : '');
            link.textContent = event.title + (rangeLabel ? ' →' : '');

            if (event.priority === 'critical' || event.priority === 'high') {
                link.classList.add('is-' + event.priority);
            }

            return link;
        }
    }

    function parseDay(value) {
        if (!value) return null;
        var time = Date.parse(value + 'T00:00:00');
        return isNaN(time) ? null : time;
    }

    function formatDay(time) {
        var date = new Date(time);
        var month = String(date.getMonth() + 1).padStart(2, '0');
        var day = String(date.getDate()).padStart(2, '0');
        return date.getFullYear() + '-' + month + '-' + day;
    }

    /* ---------------------------------------------------------------- */
    /* Repeatable table rows                                              */
    /* ---------------------------------------------------------------- */

    // Rows are cloned from the last row of the body, so the markup for a row
    // lives in one place and a new row can never drift out of step with the
    // field names the server reads.
    function initRepeatableRows() {
        document.addEventListener('click', function (event) {
            var addButton = event.target.closest('[data-add-row]');
            if (addButton) { addRow(addButton); return; }

            var removeButton = event.target.closest('[data-remove-row]');
            if (removeButton) { removeRow(removeButton); }
        });

        function bodyFor(button) {
            var selector = button.getAttribute('data-add-row');
            return selector ? document.querySelector(selector) : null;
        }

        function addRow(button) {
            var body = bodyFor(button);
            if (!body) return;

            var template = body.querySelector('tr:last-child');
            if (!template) return;

            var row = template.cloneNode(true);
            resetRow(row);
            body.appendChild(row);

            var firstField = row.querySelector('input, select, textarea');
            if (firstField) firstField.focus();
        }

        function removeRow(button) {
            var row = button.closest('tr');
            var body = row && row.parentElement;
            if (!row || !body) return;

            // The body always keeps at least one row: an empty one is a row the
            // officer can type into, and the server skips rows left blank.
            if (body.querySelectorAll('tr').length > 1) {
                row.remove();
            } else {
                resetRow(body.querySelector('tr'));
            }
        }

        function resetRow(row) {
            if (!row) return;

            row.querySelectorAll('input, textarea').forEach(function (field) {
                if (field.type === 'hidden') return;
                field.value = '';
                field.removeAttribute('value');
            });

            row.querySelectorAll('select').forEach(function (field) {
                var first = Array.prototype.find.call(field.options, function (option) {
                    return option.value !== '';
                });
                field.selectedIndex = first ? first.index : 0;
            });

            row.querySelectorAll('[data-remove-row]').forEach(function (button) {
                button.disabled = false;
            });
        }
    }

    /* ---------------------------------------------------------------- */

    document.addEventListener('DOMContentLoaded', function () {
        initSidebar();
        initPasswordToggles();
        initValidation();
        initFilters();
        initCascades();
        initGlobalSearch();
        initSubmitState();
        initCalendar();
        initRepeatableRows();
    });
})();
