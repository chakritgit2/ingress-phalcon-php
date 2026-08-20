<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>Ingress Self-Service</title>
    <script>
    (function () {
        var stored = localStorage.getItem('theme');
        var dark = stored ? stored === 'dark' : window.matchMedia('(prefers-color-scheme: dark)').matches;
        document.documentElement.classList.toggle('dark', dark);
    })();
    </script>
    <link rel="stylesheet" href="{{ url.get('css/app.css') }}">
    <script>
    function enhanceSearchableSelect(select) {
        if (!select || select.dataset.searchableInit === '1') return;
        select.dataset.searchableInit = '1';

        var originalClassName = select.className;

        var wrapper = document.createElement('div');
        wrapper.className = 'relative';
        select.parentNode.insertBefore(wrapper, select);
        wrapper.appendChild(select);
        select.classList.add('sr-only');
        select.tabIndex = -1;

        var input = document.createElement('input');
        input.type = 'text';
        input.autocomplete = 'off';
        input.className = originalClassName + ' pr-16';
        wrapper.insertBefore(input, select);

        var clearBtn = document.createElement('button');
        clearBtn.type = 'button';
        clearBtn.tabIndex = -1;
        clearBtn.setAttribute('aria-label', 'ล้างค่า');
        clearBtn.className = 'absolute right-8 top-1/2 hidden -translate-y-1/2 rounded p-0.5 text-gray-400 transition hover:bg-gray-100 hover:text-gray-600 dark:hover:bg-gray-700 dark:hover:text-gray-300';
        clearBtn.innerHTML = '<svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 6l12 12M18 6 6 18"/></svg>';
        wrapper.insertBefore(clearBtn, select);

        var chevron = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        chevron.setAttribute('class', 'pointer-events-none absolute right-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400');
        chevron.setAttribute('fill', 'none');
        chevron.setAttribute('viewBox', '0 0 24 24');
        chevron.setAttribute('stroke', 'currentColor');
        chevron.setAttribute('stroke-width', '2');
        chevron.innerHTML = '<path stroke-linecap="round" stroke-linejoin="round" d="m6 9 6 6 6-6"/>';
        wrapper.insertBefore(chevron, select);

        var list = document.createElement('div');
        list.className = 'absolute z-20 mt-1 hidden max-h-56 w-full overflow-auto rounded-lg border border-gray-200 bg-white py-1 text-sm shadow-lg dark:border-gray-700 dark:bg-gray-800';
        wrapper.appendChild(list);

        var activeIndex = -1;

        function optionRows() {
            return Array.prototype.filter.call(select.options, function (opt) { return opt.value !== ''; });
        }

        function syncFromSelect() {
            input.disabled = select.disabled;
            var opt = select.options[select.selectedIndex];
            var hasValue = !!opt && opt.value !== '';
            if (!hasValue) {
                input.value = '';
                input.placeholder = opt ? opt.textContent : '';
            } else {
                input.value = opt.textContent;
            }
            clearBtn.classList.toggle('hidden', !hasValue || select.disabled);
        }

        function closeList() {
            list.classList.add('hidden');
            activeIndex = -1;
        }

        function highlight(idx) {
            activeIndex = idx;
            Array.prototype.forEach.call(list.children, function (child, i) {
                child.classList.toggle('bg-blue-50', i === idx);
                child.classList.toggle('dark:bg-gray-700', i === idx);
            });
        }

        function selectOption(opt) {
            // Not select.value = opt.value: Deployment names can repeat
            // across namespaces (e.g. "checkout-api" in both qa and
            // production), so multiple <option>s can share the same value.
            // Setting .value would ambiguously resolve to whichever matching
            // option comes first in the DOM, not the one actually clicked.
            // Selecting the exact <option> node is unambiguous.
            opt.selected = true;
            closeList();
            syncFromSelect();
            select.dispatchEvent(new Event('change', { bubbles: true }));
        }

        function renderList(filter) {
            list.innerHTML = '';
            var q = (filter || '').trim().toLowerCase();
            var rows = optionRows().filter(function (opt) {
                return !q || opt.textContent.toLowerCase().indexOf(q) !== -1;
            });

            if (rows.length === 0) {
                var empty = document.createElement('div');
                empty.className = 'px-3 py-2 text-gray-400';
                empty.textContent = 'ไม่พบรายการ';
                list.appendChild(empty);
                return;
            }

            rows.forEach(function (opt) {
                var item = document.createElement('div');
                item.className = 'cursor-pointer px-3 py-2 hover:bg-blue-50 dark:hover:bg-gray-700';
                item.textContent = opt.textContent;
                item.addEventListener('mousedown', function (e) {
                    e.preventDefault();
                    selectOption(opt);
                });
                list.appendChild(item);
            });
            highlight(-1);
        }

        function openList() {
            if (select.disabled) return;
            renderList('');
            list.classList.remove('hidden');
        }

        function clearValue() {
            if (select.disabled) return;
            var placeholderOpt = Array.prototype.find.call(select.options, function (o) { return o.value === ''; });
            if (placeholderOpt) {
                placeholderOpt.selected = true;
            } else {
                select.selectedIndex = -1;
            }
            syncFromSelect();
            select.dispatchEvent(new Event('change', { bubbles: true }));
            openList();
        }

        // mousedown + preventDefault (not 'click'): keeps focus on the text
        // input instead of triggering its blur handler first, so clearing
        // and immediately reopening the list happens in one uninterrupted step.
        clearBtn.addEventListener('mousedown', function (e) {
            e.preventDefault();
            e.stopPropagation();
            clearValue();
        });

        input.addEventListener('focus', function () {
            input.select();
            openList();
        });
        input.addEventListener('input', function () {
            renderList(input.value);
            list.classList.remove('hidden');
        });
        input.addEventListener('blur', function () {
            setTimeout(function () {
                syncFromSelect();
                closeList();
            }, 150);
        });
        input.addEventListener('keydown', function (e) {
            var rows = list.children;
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                if (list.classList.contains('hidden')) { openList(); return; }
                highlight(Math.min(activeIndex + 1, rows.length - 1));
                if (rows[activeIndex]) rows[activeIndex].scrollIntoView({ block: 'nearest' });
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                highlight(Math.max(activeIndex - 1, 0));
                if (rows[activeIndex]) rows[activeIndex].scrollIntoView({ block: 'nearest' });
            } else if (e.key === 'Enter') {
                e.preventDefault();
                var q = input.value.trim().toLowerCase();
                var matches = optionRows().filter(function (o) {
                    return !q || o.textContent.toLowerCase().indexOf(q) !== -1;
                });
                var opt = matches[activeIndex >= 0 ? activeIndex : 0];
                if (opt) selectOption(opt);
            } else if (e.key === 'Escape') {
                syncFromSelect();
                closeList();
            }
        });

        var mo = new MutationObserver(syncFromSelect);
        mo.observe(select, { attributes: true, attributeFilter: ['disabled'], childList: true });

        syncFromSelect();

        // Exposed so code elsewhere can refresh this select's visible text
        // after setting select.value programmatically (e.g. auto-filling
        // Namespace from a picked Deployment) — plain property/value
        // assignment fires neither the 'change' event nor a DOM mutation,
        // so the MutationObserver above never sees it on its own.
        select._syncSearchableSelect = syncFromSelect;
    }

    function syncSearchableSelectDisplay(select) {
        if (select && select._syncSearchableSelect) select._syncSearchableSelect();
    }

    function fetchJson(url) {
        return fetch(url).then(function (res) { return res.json(); });
    }

    // showNamespace=true labels each option with its namespace (used for the
    // cross-namespace "all deployments" list); preselectNamespace disambiguates
    // same-named deployments in different namespaces when preselecting.
    function populateDeploymentSelect(depSelect, deployments, preselectName, preselectNamespace, showNamespace) {
        depSelect.innerHTML = '';
        if (!deployments || deployments.length === 0) {
            depSelect.innerHTML = '<option value="">-- ไม่พบ Deployment --</option>';
            depSelect.disabled = false;
            return;
        }
        var placeholder = document.createElement('option');
        placeholder.value = '';
        placeholder.textContent = '-- เลือก Deployment --';
        depSelect.appendChild(placeholder);
        deployments.forEach(function (d) {
            var opt = document.createElement('option');
            opt.value = d.name;
            opt.dataset.namespace = d.namespace || '';
            // The Deployment's own name (d.name) is an arbitrary label —
            // what's actually running is identified by its pod spec's
            // container name(s), so that's what callers like the nodered
            // default-port logic key off, not d.name.
            opt.dataset.nodered = (d.container_names || []).indexOf('nodered') !== -1 ? '1' : '';
            opt.textContent = showNamespace
                ? d.name + ' — ns: ' + d.namespace + ' (replicas: ' + d.replicas + ')'
                : d.name + ' (replicas: ' + d.replicas + ')';
            if (preselectName && d.name === preselectName && (!preselectNamespace || d.namespace === preselectNamespace)) {
                opt.selected = true;
            }
            depSelect.appendChild(opt);
        });
        depSelect.disabled = false;
    }

    function populateSecretSelect(secretSelect, names, preselectName) {
        secretSelect.innerHTML = '';
        var placeholder = document.createElement('option');
        placeholder.value = '';
        placeholder.textContent = '-- เลือก Secret Name --';
        secretSelect.appendChild(placeholder);
        names.forEach(function (name) {
            var opt = document.createElement('option');
            opt.value = name;
            opt.textContent = name;
            if (preselectName && name === preselectName) opt.selected = true;
            secretSelect.appendChild(opt);
        });
        secretSelect.disabled = false;
    }

    function loadDeploymentsForNamespace(depSelect, spinner, namespace, preselectName, silent) {
        if (!silent) {
            depSelect.disabled = true;
            depSelect.innerHTML = '<option value="">กำลังโหลด...</option>';
        }
        if (spinner) spinner.classList.remove('hidden');

        return fetchJson('/ingress/api/deployments?namespace=' + encodeURIComponent(namespace))
            .then(function (data) {
                populateDeploymentSelect(depSelect, data.deployments, preselectName, namespace, false);
            })
            .catch(function () {
                if (!silent) depSelect.innerHTML = '<option value="">โหลดไม่สำเร็จ</option>';
            })
            .finally(function () {
                if (spinner) spinner.classList.add('hidden');
            });
    }

    function loadAllDeployments(depSelect, spinner, preselectName, preselectNamespace, silent) {
        if (!silent) {
            depSelect.disabled = true;
            depSelect.innerHTML = '<option value="">กำลังโหลด...</option>';
        }
        if (spinner) spinner.classList.remove('hidden');

        return fetchJson('/ingress/api/deployments')
            .then(function (data) {
                populateDeploymentSelect(depSelect, data.deployments, preselectName, preselectNamespace, true);
            })
            .catch(function () {
                if (!silent) depSelect.innerHTML = '<option value="">โหลดไม่สำเร็จ</option>';
            })
            .finally(function () {
                if (spinner) spinner.classList.add('hidden');
            });
    }

    function loadSecretsForNamespace(secretSelect, namespace, preselectName, silent) {
        var FALLBACK_SECRETS = ['advws-tls'];
        if (!silent) {
            secretSelect.disabled = true;
            secretSelect.innerHTML = '<option value="">กำลังโหลด...</option>';
        }

        return fetchJson('/ingress/api/secrets?namespace=' + encodeURIComponent(namespace))
            .then(function (data) {
                var secrets = (data.secrets && data.secrets.length > 0) ? data.secrets : FALLBACK_SECRETS;
                populateSecretSelect(secretSelect, secrets, preselectName);
            })
            .catch(function () {
                populateSecretSelect(secretSelect, FALLBACK_SECRETS, preselectName);
            });
    }
    </script>
</head>
<body class="min-h-screen bg-gray-50 font-sans text-gray-900 antialiased dark:bg-gray-950 dark:text-gray-100">
<header class="sticky top-0 z-10 flex items-center justify-between bg-gray-900 px-6 py-4 shadow-md">
    <div class="flex items-center">
        <span class="mr-6 text-base font-semibold tracking-tight text-white">Ingress Self-Service</span>
        {% if currentUser is defined and currentUser %}
        <nav class="flex items-center gap-1">
            <a class="inline-flex shrink-0 items-center gap-1.5 whitespace-nowrap rounded-md px-3 py-2 text-sm font-medium transition {{ dispatcher.getControllerName() == 'ingress' ? 'bg-white/10 text-white' : 'text-gray-300 hover:bg-white/10 hover:text-white' }}" href="/ingress">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                    <circle cx="5" cy="12" r="2.5"/>
                    <circle cx="19" cy="6" r="2.5"/>
                    <circle cx="19" cy="18" r="2.5"/>
                    <path d="M7.2 11 16.8 6.8M7.2 13 16.8 17.2"/>
                </svg>
                Ingress
            </a>
            <a class="inline-flex shrink-0 items-center gap-1.5 whitespace-nowrap rounded-md px-3 py-2 text-sm font-medium transition {{ dispatcher.getControllerName() == 'audit' ? 'bg-white/10 text-white' : 'text-gray-300 hover:bg-white/10 hover:text-white' }}" href="/audit">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                    <rect x="5" y="4" width="14" height="17" rx="1.5"/>
                    <path d="M9 3.5h6v2H9zM8 10h8M8 13.5h8M8 17h5"/>
                </svg>
                Audit Log
            </a>
            {% if currentUser.isDevops() %}
            <a class="inline-flex shrink-0 items-center gap-1.5 whitespace-nowrap rounded-md px-3 py-2 text-sm font-medium transition {{ dispatcher.getControllerName() == 'users' ? 'bg-white/10 text-white' : 'text-gray-300 hover:bg-white/10 hover:text-white' }}" href="/users">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                    <circle cx="9" cy="8" r="3"/>
                    <path stroke-linecap="round" d="M3.5 19a5.5 5.5 0 0 1 11 0"/>
                    <path stroke-linecap="round" d="M16 8.5a3 3 0 1 1 3.5 4.5M18.5 14.5A5 5 0 0 1 21 19"/>
                </svg>
                ผู้ใช้งาน
            </a>
            {% endif %}
        </nav>
        {% endif %}
    </div>
    <div class="flex items-center gap-3">
        <button type="button" id="themeToggle" aria-label="สลับธีมสว่าง/มืด" class="inline-flex shrink-0 cursor-pointer items-center justify-center rounded-lg border border-white/20 bg-white/5 p-2 text-white transition hover:bg-white/10">
            <svg id="themeIconSun" class="hidden h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                <circle cx="12" cy="12" r="4"/>
                <path stroke-linecap="round" d="M12 2.5v2M12 19.5v2M4.2 4.2l1.4 1.4M18.4 18.4l1.4 1.4M2.5 12h2M19.5 12h2M4.2 19.8l1.4-1.4M18.4 5.6l1.4-1.4"/>
            </svg>
            <svg id="themeIconMoon" class="hidden h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M20.5 14.5A8.5 8.5 0 1 1 9.5 3.5a7 7 0 0 0 11 11Z"/>
            </svg>
        </button>
        {% if currentUser is defined and currentUser %}
            <span class="whitespace-nowrap text-sm text-gray-300">{{ currentUser.email }} <span class="text-gray-500">({{ currentUser.role }})</span></span>
            <form class="inline shrink-0" method="post" action="/logout">
                <button type="submit" class="inline-flex shrink-0 cursor-pointer items-center gap-1.5 whitespace-nowrap rounded-lg border border-white/20 bg-white/5 px-3 py-1.5 text-sm font-medium text-white transition hover:bg-white/10">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 8V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h7a2 2 0 0 0 2-2v-2"/>
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h11m0 0-3-3m3 3-3 3"/>
                    </svg>
                    ออกจากระบบ
                </button>
            </form>
        {% endif %}
    </div>
</header>
<main class="mx-auto my-8 {% block container_class %}max-w-4xl{% endblock %} px-4">
    <div class="animate-fade-in">{{ flash.output() }}</div>
    <div class="animate-fade-in rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
        {% block content %}{% endblock %}
    </div>
</main>
<script>
(function () {
    var toggle = document.getElementById('themeToggle');
    var sun = document.getElementById('themeIconSun');
    var moon = document.getElementById('themeIconMoon');

    function sync() {
        var isDark = document.documentElement.classList.contains('dark');
        sun.classList.toggle('hidden', !isDark);
        moon.classList.toggle('hidden', isDark);
    }

    toggle.addEventListener('click', function () {
        var isDark = document.documentElement.classList.toggle('dark');
        localStorage.setItem('theme', isDark ? 'dark' : 'light');
        sync();
    });

    sync();
})();
</script>
</body>
</html>
