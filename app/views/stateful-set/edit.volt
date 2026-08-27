{% extends "layouts/main.volt" %}
{% block content %}
<h1 class="mb-6 border-b border-gray-200 pb-4 text-xl font-semibold text-gray-900 dark:border-gray-800 dark:text-white">แก้ไข StatefulSet Service</h1>

<form method="post" action="/statefulsets/{{ row.id }}/update" class="max-w-md space-y-6">
    <input type="hidden" name="{{ security.getTokenKey() }}" value="{{ security.getToken() }}">

    <div>
        <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300" for="developer_name">ใคร (Developer Name) *</label>
        <input class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm transition focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/20 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100" type="text" id="developer_name" name="developer_name" value="{{ row.developer_name }}" required>
    </div>

    <div>
        <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300" for="namespace">ที่ Namespace อะไร *</label>
        <select class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm transition focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/20 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100" id="namespace" name="namespace" required>
            <option value="">-- เลือก Namespace --</option>
            {% for ns in namespaces %}
            <option value="{{ ns }}" {{ ns == row.namespace ? 'selected' : '' }}>{{ ns }}</option>
            {% endfor %}
        </select>
    </div>

    <div>
        <label class="mb-1.5 flex items-center gap-2 text-sm font-medium text-gray-700 dark:text-gray-300" for="statefulset_name">
            ใช้อะไร (StatefulSet) *
            <svg id="statefulset_spinner" class="hidden h-3.5 w-3.5 animate-spin text-gray-400" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                <circle class="opacity-25" cx="12" cy="12" r="9" stroke="currentColor" stroke-width="3"/>
                <path class="opacity-75" fill="currentColor" d="M21 12a9 9 0 0 0-9-9V0c6.627 0 12 5.373 12 12h-3z"/>
            </svg>
        </label>
        <select class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm transition focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/20 disabled:cursor-not-allowed disabled:bg-gray-50 disabled:text-gray-400 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 dark:disabled:bg-gray-900 dark:disabled:text-gray-600" id="statefulset_name" name="statefulset_name" required disabled>
            <option value="{{ row.statefulset_name }}">{{ row.statefulset_name }}</option>
        </select>
    </div>

    <div>
        <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300" for="target_port">Port</label>
        <input class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm transition focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/20 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100" type="number" id="target_port" name="target_port" value="{{ row.target_port }}" min="1" max="65535" required>
    </div>

    <div>
        <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300" for="schedule_end_datetime">Schedule End (วันและเวลา) *</label>
        <input class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm transition focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/20 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100" type="datetime-local" id="schedule_end_datetime" required>
        <input type="hidden" id="schedule_end_minutes" name="schedule_end_minutes" value="{{ row.schedule_end_minutes }}">
        <p id="schedule_end_preview" class="mt-1 text-xs text-gray-500 dark:text-gray-400"></p>
    </div>

    <div>
        <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300" for="note">หมายเหตุ (ถ้ามี)</label>
        <textarea class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm transition focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/20 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100" id="note" name="note" rows="2" maxlength="255" placeholder="เหตุผลที่ขอ เช่น ทดสอบ feature X ให้ทีม QA">{{ row.note|e }}</textarea>
    </div>

    <div class="pt-2">
        <button type="submit" class="inline-flex items-center justify-center rounded-lg bg-blue-600 px-3.5 py-2 text-sm font-medium text-white shadow-sm transition hover:bg-blue-700 cursor-pointer">บันทึกการแก้ไข</button>
    </div>
</form>

<script>
enhanceSearchableSelect(document.getElementById('namespace'));
enhanceSearchableSelect(document.getElementById('statefulset_name'));

var PRESELECT_STATEFULSET = {{ row.statefulset_name ? ('"' ~ row.statefulset_name ~ '"') : 'null' }};

var namespaceSelect = document.getElementById('namespace');
var statefulSetSelect = document.getElementById('statefulset_name');
var statefulSetSpinner = document.getElementById('statefulset_spinner');

namespaceSelect.addEventListener('change', function () {
    var ns = this.value;
    if (!ns) {
        loadAllStatefulSets(statefulSetSelect, statefulSetSpinner, null, null, false);
        return;
    }
    loadStatefulSetsForNamespace(statefulSetSelect, statefulSetSpinner, ns, null, false);
});

// Same "pick either field first" behaviour as create.volt.
statefulSetSelect.addEventListener('change', function () {
    var opt = this.options[this.selectedIndex];
    var ns = opt ? opt.dataset.namespace : '';
    if (!ns || namespaceSelect.value === ns) return;

    namespaceSelect.value = ns;
    syncSearchableSelectDisplay(namespaceSelect);
    loadStatefulSetsForNamespace(statefulSetSelect, statefulSetSpinner, ns, this.value, false);
});

var targetPortInput = document.getElementById('target_port');
statefulSetSelect.addEventListener('change', function () {
    var opt = this.options[this.selectedIndex];
    targetPortInput.value = (opt && opt.dataset.nodered === '1') ? 1880 : 80;
});

var initialNamespace = namespaceSelect.value;
loadAllStatefulSets(statefulSetSelect, statefulSetSpinner, PRESELECT_STATEFULSET, initialNamespace, true);

// schedule_end_minutes is a plain minutes count server-side — this picks an
// absolute date/time instead and converts it to minutes-from-now on submit,
// pre-filled as now + the row's existing schedule_end_minutes (same
// reasoning as the Ingress edit form).
(function () {
    var datetimeInput = document.getElementById('schedule_end_datetime');
    var minutesInput = document.getElementById('schedule_end_minutes');
    var preview = document.getElementById('schedule_end_preview');
    var form = datetimeInput.closest('form');
    var maxMinutes = 10080;

    function pad(n) {
        return String(n).padStart(2, '0');
    }

    function toLocalInputValue(date) {
        return date.getFullYear() + '-' + pad(date.getMonth() + 1) + '-' + pad(date.getDate())
            + 'T' + pad(date.getHours()) + ':' + pad(date.getMinutes());
    }

    datetimeInput.min = toLocalInputValue(new Date(Date.now() + 60000));
    datetimeInput.max = toLocalInputValue(new Date(Date.now() + maxMinutes * 60000));

    function syncMinutes() {
        if (!datetimeInput.value) {
            minutesInput.value = '';
            preview.textContent = '';
            return;
        }

        var diffMinutes = Math.round((new Date(datetimeInput.value).getTime() - Date.now()) / 60000);
        if (diffMinutes < 1 || diffMinutes > maxMinutes) {
            minutesInput.value = '';
            preview.textContent = 'กรุณาเลือกเวลาในอนาคต ไม่เกิน 7 วันข้างหน้า';
            return;
        }

        minutesInput.value = diffMinutes;
        var days = Math.floor(diffMinutes / 1440);
        var hours = Math.floor((diffMinutes % 1440) / 60);
        var mins = diffMinutes % 60;
        var parts = [];
        if (days) parts.push(days + ' วัน');
        if (hours) parts.push(hours + ' ชม.');
        if (mins || parts.length === 0) parts.push(mins + ' นาที');
        preview.textContent = 'ระยะเวลาที่ใช้งาน: ' + parts.join(' ');
    }

    var initialMinutes = parseInt(minutesInput.value, 10);
    if (initialMinutes > 0) {
        datetimeInput.value = toLocalInputValue(new Date(Date.now() + initialMinutes * 60000));
        syncMinutes();
    }

    datetimeInput.addEventListener('change', syncMinutes);
    form.addEventListener('submit', function (event) {
        syncMinutes();
        if (!minutesInput.value) {
            event.preventDefault();
            datetimeInput.reportValidity();
        }
    });
})();
</script>
{% endblock %}
