{% extends "layouts/main.volt" %}
{% block content %}
<h1 class="mb-6 text-2xl font-bold text-gray-900">สร้าง Ingress ใหม่ (NodePort)</h1>

<form method="post" action="/ingress/store" class="max-w-md space-y-5">
    <input type="hidden" name="{{ security.getTokenKey() }}" value="{{ security.getToken() }}">

    <div>
        <label class="mb-1.5 block text-sm font-medium text-gray-700" for="developer_name">ใคร (Developer Name) *</label>
        <input class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm transition focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/20" type="text" id="developer_name" name="developer_name" value="{{ developerNameDefault }}" required>
    </div>

    <div>
        <label class="mb-1.5 block text-sm font-medium text-gray-700" for="namespace">ที่ Namespace อะไร *</label>
        <select class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm transition focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/20" id="namespace" name="namespace" required>
            <option value="">-- เลือก Namespace --</option>
            {% for ns in namespaces %}
            <option value="{{ ns }}">{{ ns }}</option>
            {% endfor %}
        </select>
    </div>

    <div>
        <label class="mb-1.5 flex items-center gap-2 text-sm font-medium text-gray-700" for="deployment_name">
            ใช้อะไร (Deployment) *
            <svg id="deployment_spinner" class="hidden h-3.5 w-3.5 animate-spin text-gray-400" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                <circle class="opacity-25" cx="12" cy="12" r="9" stroke="currentColor" stroke-width="3"/>
                <path class="opacity-75" fill="currentColor" d="M21 12a9 9 0 0 0-9-9V0c6.627 0 12 5.373 12 12h-3z"/>
            </svg>
        </label>
        <select class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm transition focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/20 disabled:cursor-not-allowed disabled:bg-gray-50 disabled:text-gray-400" id="deployment_name" name="deployment_name" required disabled>
            <option value="">-- เลือก Namespace ก่อน --</option>
        </select>
    </div>

    <div>
        <label class="mb-1.5 block text-sm font-medium text-gray-700" for="target_port">Port</label>
        <input class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm transition focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/20" type="number" id="target_port" name="target_port" value="80" min="1" max="65535" required>
    </div>

    <div>
        <label class="mb-1.5 block text-sm font-medium text-gray-700" for="schedule_end_minutes">Schedule End (นาที) *</label>
        <input class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm transition focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/20" type="number" id="schedule_end_minutes" name="schedule_end_minutes" min="1" max="10080" required>
    </div>

    <div class="pt-2">
        <button type="submit" class="inline-flex items-center justify-center rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white shadow-sm transition hover:bg-blue-700 cursor-pointer">สร้าง</button>
    </div>
</form>

<script>
document.getElementById('namespace').addEventListener('change', function () {
    var ns = this.value;
    var depSelect = document.getElementById('deployment_name');
    var spinner = document.getElementById('deployment_spinner');
    depSelect.disabled = true;
    depSelect.innerHTML = '<option value="">กำลังโหลด...</option>';

    if (!ns) {
        depSelect.innerHTML = '<option value="">-- เลือก Namespace ก่อน --</option>';
        return;
    }

    spinner.classList.remove('hidden');

    fetch('/ingress/api/deployments?namespace=' + encodeURIComponent(ns))
        .then(function (res) { return res.json(); })
        .then(function (data) {
            depSelect.innerHTML = '';
            if (!data.deployments || data.deployments.length === 0) {
                depSelect.innerHTML = '<option value="">-- ไม่พบ Deployment --</option>';
                return;
            }
            var placeholder = document.createElement('option');
            placeholder.value = '';
            placeholder.textContent = '-- เลือก Deployment --';
            depSelect.appendChild(placeholder);
            data.deployments.forEach(function (d) {
                var opt = document.createElement('option');
                opt.value = d.name;
                opt.textContent = d.name + ' (replicas: ' + d.replicas + ')';
                depSelect.appendChild(opt);
            });
            depSelect.disabled = false;
        })
        .catch(function () {
            depSelect.innerHTML = '<option value="">โหลดไม่สำเร็จ</option>';
        })
        .finally(function () {
            spinner.classList.add('hidden');
        });
});
</script>
{% endblock %}
