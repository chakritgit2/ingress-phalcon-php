{% extends "layouts/main.volt" %}
{% block content %}
<h1 class="mb-6 border-b border-gray-200 pb-4 text-xl font-semibold text-gray-900 dark:border-gray-800 dark:text-white">สร้าง Ingress ใหม่</h1>

<form method="post" action="/ingress/store" class="max-w-md space-y-6">
    <input type="hidden" name="{{ security.getTokenKey() }}" value="{{ security.getToken() }}">

    <div>
        <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">ประเภท *</label>
        <div class="inline-flex rounded-lg bg-gray-100 p-1 dark:bg-gray-800">
            <label class="cursor-pointer rounded-md px-4 py-1.5 text-sm font-medium text-gray-600 transition has-[:checked]:bg-white has-[:checked]:text-blue-700 has-[:checked]:shadow-sm has-[:checked]:ring-1 has-[:checked]:ring-blue-200 dark:text-gray-400 dark:has-[:checked]:bg-gray-700 dark:has-[:checked]:text-blue-400 dark:has-[:checked]:ring-blue-500/30">
                <input type="radio" name="request_type" value="nodeport" checked class="sr-only">
                NodePort ชั่วคราว
            </label>
            <label class="cursor-pointer rounded-md px-4 py-1.5 text-sm font-medium text-gray-600 transition has-[:checked]:bg-white has-[:checked]:text-blue-700 has-[:checked]:shadow-sm has-[:checked]:ring-1 has-[:checked]:ring-blue-200 dark:text-gray-400 dark:has-[:checked]:bg-gray-700 dark:has-[:checked]:text-blue-400 dark:has-[:checked]:ring-blue-500/30">
                <input type="radio" name="request_type" value="ingress" class="sr-only">
                Ingress + TLS
            </label>
        </div>

        <p id="type_desc_nodeport" class="mt-2 text-xs text-gray-500 dark:text-gray-400">เข้าถึงผ่าน <code class="rounded bg-gray-100 px-1 py-0.5 text-[11px] dark:bg-gray-800">node_ip:node_port</code> โดยตรง ไม่ต้องมีโดเมนหรือ TLS</p>
        <p id="type_desc_ingress" class="mt-2 hidden text-xs text-gray-500 dark:text-gray-400">เข้าถึงผ่านโดเมนที่กำหนดเอง (HTTPS) ต้องกรอก Host และเลือก TLS Secret</p>

        <p class="mt-2 flex items-start gap-1.5 text-xs text-gray-500 dark:text-gray-400">
            <svg class="mt-0.5 h-3.5 w-3.5 shrink-0 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <circle cx="12" cy="12" r="9"/>
                <path stroke-linecap="round" d="M12 11v5m0-8h.01"/>
            </svg>
            <span>ทั้งสองแบบถูกลบอัตโนมัติเมื่อครบกำหนดใน "Schedule End" เหมือนกัน — "ถาวรตามโดเมน" หมายถึงรูปแบบการเข้าถึงเท่านั้น ไม่ใช่ว่าจะไม่ถูกลบ</span>
        </p>
    </div>

    <div>
        <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300" for="developer_name">ใคร (Developer Name) *</label>
        <input class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm transition focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/20 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100" type="text" id="developer_name" name="developer_name" value="{{ developerNameDefault }}" required>
    </div>

    <div>
        <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300" for="namespace">ที่ Namespace อะไร *</label>
        <select class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm transition focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/20 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100" id="namespace" name="namespace" required>
            <option value="">-- เลือก Namespace --</option>
            {% for ns in namespaces %}
            <option value="{{ ns }}">{{ ns }}</option>
            {% endfor %}
        </select>
    </div>

    <div>
        <label class="mb-1.5 flex items-center gap-2 text-sm font-medium text-gray-700 dark:text-gray-300" for="deployment_name">
            ใช้อะไร (Deployment) *
            <svg id="deployment_spinner" class="hidden h-3.5 w-3.5 animate-spin text-gray-400" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                <circle class="opacity-25" cx="12" cy="12" r="9" stroke="currentColor" stroke-width="3"/>
                <path class="opacity-75" fill="currentColor" d="M21 12a9 9 0 0 0-9-9V0c6.627 0 12 5.373 12 12h-3z"/>
            </svg>
        </label>
        <select class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm transition focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/20 disabled:cursor-not-allowed disabled:bg-gray-50 disabled:text-gray-400 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 dark:disabled:bg-gray-900 dark:disabled:text-gray-600" id="deployment_name" name="deployment_name" required disabled>
            <option value="">กำลังโหลด...</option>
        </select>
        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">เลือก Namespace หรือ Deployment ก่อนก็ได้ — เลือกอย่างใดอย่างหนึ่งแล้วอีกช่องจะปรับตาม</p>
    </div>

    <div>
        <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300" for="target_port">Port</label>
        <input class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm transition focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/20 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100" type="number" id="target_port" name="target_port" value="80" min="1" max="65535" required>
    </div>

    <div id="ingress_fields" class="hidden space-y-5 border-l-2 border-blue-200 pl-4 dark:border-blue-500/30">
        <div>
            <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300" for="host">Host (โดเมน) *</label>
            <div class="flex gap-2">
                <input class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm transition focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/20 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100" type="text" id="host" name="host" placeholder="myapp.advws.com" pattern="[a-z0-9]([-a-z0-9]*[a-z0-9])?(\.[a-z0-9]([-a-z0-9]*[a-z0-9])?)*" title="ใส่แค่ชื่อโดเมน เช่น myapp.advws.com (ห้ามมี http:// หรือ / ต่อท้าย)">
                <button type="button" id="genHostUuidBtn" title="สุ่ม UUID ใส่ Host" class="inline-flex shrink-0 cursor-pointer items-center rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 shadow-sm transition hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h5M20 20v-5h-5M4 9a8 8 0 0 1 14.5-4.5M20 15a8 8 0 0 1-14.5 4.5"/>
                    </svg>
                </button>
            </div>
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">ใส่แค่ชื่อโดเมนเปล่าๆ เช่น myapp.advws.com — ห้ามมี http:// หรือ / ต่อท้าย</p>
        </div>

        <div>
            <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300" for="secret_name">Secret Name (TLS) *</label>
            <select class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm transition focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/20 disabled:cursor-not-allowed disabled:bg-gray-50 disabled:text-gray-400 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 dark:disabled:bg-gray-900 dark:disabled:text-gray-600" id="secret_name" name="secret_name" disabled>
                <option value="">-- เลือก Namespace ก่อน --</option>
            </select>
        </div>
    </div>

    <div>
        <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300" for="schedule_end_minutes">Schedule End (นาที) *</label>
        <input class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm transition focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/20 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100" type="number" id="schedule_end_minutes" name="schedule_end_minutes" min="1" max="10080" required>
    </div>

    <div>
        <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300" for="note">หมายเหตุ (ถ้ามี)</label>
        <textarea class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm transition focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/20 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100" id="note" name="note" rows="2" maxlength="255" placeholder="เหตุผลที่ขอ เช่น ทดสอบ feature X ให้ทีม QA"></textarea>
    </div>

    <div class="pt-2">
        <button type="submit" class="inline-flex items-center justify-center rounded-lg bg-blue-600 px-3.5 py-2 text-sm font-medium text-white shadow-sm transition hover:bg-blue-700 cursor-pointer">สร้าง</button>
    </div>
</form>

<script>
enhanceSearchableSelect(document.getElementById('namespace'));
enhanceSearchableSelect(document.getElementById('deployment_name'));
enhanceSearchableSelect(document.getElementById('secret_name'));

var namespaceSelect = document.getElementById('namespace');
var deploymentSelect = document.getElementById('deployment_name');
var secretSelect = document.getElementById('secret_name');
var deploymentSpinner = document.getElementById('deployment_spinner');

namespaceSelect.addEventListener('change', function () {
    var ns = this.value;
    if (!ns) {
        loadAllDeployments(deploymentSelect, deploymentSpinner, null, null, false);
        secretSelect.disabled = true;
        secretSelect.innerHTML = '<option value="">-- เลือก Namespace ก่อน --</option>';
        return;
    }
    loadDeploymentsForNamespace(deploymentSelect, deploymentSpinner, ns, null, false);
    loadSecretsForNamespace(secretSelect, ns, null, false);
});

// Picking a Deployment before a Namespace: the initial load below shows
// every Deployment across every namespace, so this reads the namespace back
// off the chosen <option> and back-fills the Namespace field with it.
deploymentSelect.addEventListener('change', function () {
    var opt = this.options[this.selectedIndex];
    var ns = opt ? opt.dataset.namespace : '';
    if (!ns || namespaceSelect.value === ns) return;

    namespaceSelect.value = ns;
    syncSearchableSelectDisplay(namespaceSelect);
    loadDeploymentsForNamespace(deploymentSelect, deploymentSpinner, ns, this.value, false);
    loadSecretsForNamespace(secretSelect, ns, null, false);
});

// nodered's own default container port is 1880, not 80 — auto-fill it
// whenever that Deployment is picked so the common case needs no manual edit.
var targetPortInput = document.getElementById('target_port');
deploymentSelect.addEventListener('change', function () {
    targetPortInput.value = this.value === 'nodered' ? 1880 : 80;
});

loadAllDeployments(deploymentSelect, deploymentSpinner, null, null, false);

var ingressFields = document.getElementById('ingress_fields');
var hostInput = document.getElementById('host');
var typeDescNodeport = document.getElementById('type_desc_nodeport');
var typeDescIngress = document.getElementById('type_desc_ingress');
document.querySelectorAll('input[name="request_type"]').forEach(function (radio) {
    radio.addEventListener('change', function () {
        var isIngress = this.value === 'ingress' && this.checked;
        ingressFields.classList.toggle('hidden', !isIngress);
        typeDescNodeport.classList.toggle('hidden', isIngress);
        typeDescIngress.classList.toggle('hidden', !isIngress);
        hostInput.required = isIngress;
        document.getElementById('secret_name').required = isIngress;
    });
});

function generateUuidV4() {
    if (window.crypto && crypto.randomUUID) {
        return crypto.randomUUID();
    }
    var bytes = new Uint8Array(16);
    crypto.getRandomValues(bytes);
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;
    var hex = Array.prototype.map.call(bytes, function (b) { return b.toString(16).padStart(2, '0'); }).join('');
    return hex.slice(0, 8) + '-' + hex.slice(8, 12) + '-' + hex.slice(12, 16) + '-' + hex.slice(16, 20) + '-' + hex.slice(20);
}

document.getElementById('genHostUuidBtn').addEventListener('click', function () {
    hostInput.value = 'nodered-' + generateUuidV4() + '.advws.org';
});
</script>
{% endblock %}
