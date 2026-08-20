{% extends "layouts/main.volt" %}
{% block container_class %}max-w-7xl{% endblock %}
{% block content %}
{% if currentUser.isDevops() %}
<div class="mb-4 flex items-center justify-between rounded-lg border {{ botEnabled ? 'border-green-200 bg-green-50 dark:border-green-500/20 dark:bg-green-500/10' : 'border-red-200 bg-red-50 dark:border-red-500/20 dark:bg-red-500/10' }} px-4 py-3">
    <span class="text-sm font-medium {{ botEnabled ? 'text-green-800 dark:text-green-400' : 'text-red-800 dark:text-red-400' }}">
        บอทประมวลผลคำขอ:
        {% if botEnabled %}
            กำลังทำงาน
        {% else %}
            ปิดอยู่ (คำขอใหม่จะค้างจนกว่าจะเปิด)
        {% endif %}
    </span>
    {% if botKillSwitchActive %}
        <span class="text-xs text-gray-500 dark:text-gray-400">ถูกบังคับปิดโดย env var BOT_ENABLED</span>
    {% else %}
        <form method="post" action="/ingress/toggle-bot">
            <input type="hidden" name="{{ security.getTokenKey() }}" value="{{ security.getToken() }}">
            <button type="submit" class="inline-flex shrink-0 cursor-pointer items-center gap-1.5 whitespace-nowrap rounded-lg border px-3 py-1.5 text-xs font-medium transition {{ botEnabled ? 'border-red-200 bg-white text-red-700 hover:bg-red-100 dark:border-red-500/20 dark:bg-gray-900 dark:text-red-400 dark:hover:bg-red-500/10' : 'border-green-200 bg-white text-green-700 hover:bg-green-100 dark:border-green-500/20 dark:bg-gray-900 dark:text-green-400 dark:hover:bg-green-500/10' }}">
                {% if botEnabled %}ปิดบอท{% else %}เปิดบอท{% endif %}
            </button>
        </form>
    {% endif %}
</div>
<p class="mb-4 flex items-start gap-1.5 text-xs text-gray-500 dark:text-gray-400">
    <svg class="mt-0.5 h-3.5 w-3.5 shrink-0 text-gray-400 dark:text-gray-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
        <circle cx="12" cy="12" r="9"/>
        <path stroke-linecap="round" d="M12 11v5m0-8h.01"/>
    </svg>
    <span>บอททำงานอัตโนมัติทุก 1 นาที 2 อย่าง: <strong>ประมวลผลคำขอ</strong> (สร้าง/ลบ Ingress หรือ NodePort จริงบน Kubernetes ตามคิวที่ค้างอยู่) และ <strong>เก็บกวาดของหมดอายุ</strong> (ลบรายการที่ครบกำหนด "Schedule End" อัตโนมัติ) — ถ้าปิดบอทไว้ คำขอใหม่จะค้างที่สถานะ pending จนกว่าจะเปิดอีกครั้ง</span>
</p>
{% if stuckCommandCount > 0 %}
<div class="mb-4 flex items-center justify-between rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 dark:border-amber-500/20 dark:bg-amber-500/10">
    <span class="text-sm font-medium text-amber-800 dark:text-amber-400">
        ⚠ มีคำขอค้างประมวลผลอยู่ {{ stuckCommandCount }} รายการเกิน 5 นาที — บอทอาจไม่ทำงาน กรุณาตรวจสอบ CronJob/Pod
    </span>
</div>
{% endif %}
{% endif %}
<div class="mb-6 flex flex-wrap items-center justify-between gap-3 border-b border-gray-200 pb-4 dark:border-gray-800">
    <h1 class="text-xl font-semibold text-gray-900 dark:text-white">Ingress ที่เปิดอยู่</h1>
    {% if currentUser.isDevops() %}
    <a href="/ingress/create" class="inline-flex items-center gap-1.5 rounded-lg bg-blue-600 px-3.5 py-2 text-sm font-medium text-white shadow-sm transition hover:bg-blue-700">
        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <circle cx="12" cy="12" r="9"/>
            <path stroke-linecap="round" d="M12 8v8M8 12h8"/>
        </svg>
        สร้าง Ingress ใหม่
    </a>
    {% endif %}
</div>

<form method="get" action="/ingress" class="mb-4 flex flex-wrap items-end gap-3">
    <div>
        <label class="mb-1.5 block text-xs font-medium text-gray-700 dark:text-gray-300" for="filter_namespace">Namespace</label>
        <input class="h-9 rounded-lg border border-gray-300 px-3 py-1.5 text-sm shadow-sm transition focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/20 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100" type="text" id="filter_namespace" name="namespace" value="{{ filterNamespace|e }}" placeholder="namespace">
    </div>
    <div>
        <label class="mb-1.5 block text-xs font-medium text-gray-700 dark:text-gray-300" for="filter_developer_name">Developer</label>
        <input class="h-9 rounded-lg border border-gray-300 px-3 py-1.5 text-sm shadow-sm transition focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/20 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100" type="text" id="filter_developer_name" name="developer_name" value="{{ filterDeveloperName|e }}" placeholder="developer">
    </div>
    <div>
        <label class="mb-1.5 block text-xs font-medium text-gray-700 dark:text-gray-300" for="filter_status">สถานะ</label>
        <select class="h-9 rounded-lg border border-gray-300 px-3 py-1.5 text-sm shadow-sm transition focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/20 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100" id="filter_status" name="status">
            <option value="">ทั้งหมด</option>
            {% for s in ['pending', 'active', 'deleting', 'expired', 'deleted', 'failed'] %}
            <option value="{{ s }}" {{ filterStatus == s ? 'selected' : '' }}>{{ s }}</option>
            {% endfor %}
        </select>
    </div>
    <button type="submit" class="inline-flex h-9 shrink-0 items-center self-end whitespace-nowrap rounded-lg border border-gray-300 bg-white px-3.5 py-1.5 text-sm font-medium text-gray-700 shadow-sm transition hover:bg-gray-50 cursor-pointer dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700">ค้นหา</button>
    {% if filterNamespace or filterDeveloperName or filterStatus %}
    <a href="/ingress" class="inline-flex h-9 shrink-0 items-center self-end whitespace-nowrap text-sm text-gray-500 underline hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200">ล้างข้อมูลการค้นหา</a>
    {% endif %}
    <a href="/ingress/export?namespace={{ filterNamespace|url_encode }}&developer_name={{ filterDeveloperName|url_encode }}&status={{ filterStatus|url_encode }}" class="inline-flex h-9 shrink-0 items-center self-end gap-1.5 whitespace-nowrap rounded-lg border border-gray-300 bg-white px-3.5 py-1.5 text-sm font-medium text-gray-700 shadow-sm transition hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700">
        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v12m0 0-4-4m4 4 4-4M5 19h14"/>
        </svg>
        Export CSV
    </a>
</form>

{% if currentUser.isDevops() %}
<form id="bulkActionsForm" method="post">
    <input type="hidden" name="{{ security.getTokenKey() }}" value="{{ security.getToken() }}">
</form>
<div class="mb-1.5 flex items-center gap-3">
    <span id="bulkSelectedCount" class="text-sm text-gray-500 dark:text-gray-400">เลือกแล้ว 0 รายการ</span>
    <button type="submit" form="bulkActionsForm" formaction="/ingress/bulk-delete" onclick="return confirm('ยืนยันลบรายการที่เลือก (เฉพาะสถานะ active)?');" disabled id="bulkDeleteBtn" class="inline-flex cursor-pointer items-center gap-1.5 rounded-lg border border-red-200 bg-red-50 px-3 py-1.5 text-xs font-medium text-red-700 transition hover:bg-red-100 disabled:cursor-not-allowed disabled:opacity-40 dark:border-red-500/20 dark:bg-red-500/10 dark:text-red-400 dark:hover:bg-red-500/20">Bulk Delete</button>
    <button type="submit" form="bulkActionsForm" formaction="/ingress/bulk-retry" onclick="return confirm('ยืนยันลองใหม่รายการที่เลือก (เฉพาะสถานะ failed)?');" disabled id="bulkRetryBtn" class="inline-flex cursor-pointer items-center gap-1.5 rounded-lg border border-blue-200 bg-blue-50 px-3 py-1.5 text-xs font-medium text-blue-700 transition hover:bg-blue-100 disabled:cursor-not-allowed disabled:opacity-40 dark:border-blue-500/20 dark:bg-blue-500/10 dark:text-blue-400 dark:hover:bg-blue-500/20">Bulk Retry</button>
</div>
<p class="mb-3 flex items-start gap-1.5 text-xs text-gray-400 dark:text-gray-500">
    <svg class="mt-0.5 h-3.5 w-3.5 shrink-0 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
        <circle cx="12" cy="12" r="9"/>
        <path stroke-linecap="round" d="M12 11v5m0-8h.01"/>
    </svg>
    <span><strong>Bulk Delete</strong> จะลบเฉพาะรายการที่ติ๊กไว้ <em>และ</em> มีสถานะ active เท่านั้น — <strong>Bulk Retry</strong> จะลองใหม่เฉพาะรายการที่ติ๊กไว้ <em>และ</em> มีสถานะ failed เท่านั้น รายการที่ติ๊กไว้แต่สถานะไม่ตรงเงื่อนไขจะถูกข้ามไปอัตโนมัติ (ทั้งสองปุ่มแค่ส่งคำขอเข้าคิว ไม่ได้สั่ง Kubernetes ทันที — บอทจะมาประมวลผลภายใน 1 นาที)</span>
</p>
{% endif %}

<div class="overflow-x-auto">
    <table class="w-full min-w-[900px] text-sm">
        <thead>
            <tr>
                <th class="sticky top-0 z-10 bg-gray-50 px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:bg-gray-900 dark:text-gray-400">
                    {% if currentUser.isDevops() %}<input type="checkbox" id="bulkSelectAll">{% endif %}
                </th>
                <th class="sticky top-0 z-10 bg-gray-50 px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:bg-gray-900 dark:text-gray-400">ID</th>
                <th class="sticky top-0 z-10 bg-gray-50 px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:bg-gray-900 dark:text-gray-400">ใคร</th>
                <th class="sticky top-0 z-10 bg-gray-50 px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:bg-gray-900 dark:text-gray-400">ใช้อะไร</th>
                <th class="sticky top-0 z-10 bg-gray-50 px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:bg-gray-900 dark:text-gray-400">Namespace</th>
                <th class="sticky top-0 z-10 bg-gray-50 px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:bg-gray-900 dark:text-gray-400">ประเภท</th>
                <th class="sticky top-0 z-10 bg-gray-50 px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:bg-gray-900 dark:text-gray-400">ออกที่ไหน</th>
                <th class="sticky top-0 z-10 bg-gray-50 px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:bg-gray-900 dark:text-gray-400">เมื่อไหร</th>
                <th class="sticky top-0 z-10 bg-gray-50 px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:bg-gray-900 dark:text-gray-400">หมดอายุ</th>
                <th class="sticky top-0 z-10 bg-gray-50 px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:bg-gray-900 dark:text-gray-400">สถานะ</th>
                <th class="sticky top-0 z-10 bg-gray-50 px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:bg-gray-900 dark:text-gray-400"></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100 bg-white dark:divide-gray-800 dark:bg-gray-900">
            {% for row in rows %}
            <tr class="transition duration-150 hover:bg-gray-50 dark:hover:bg-gray-800/60">
                <td class="px-4 py-3">
                    {% if currentUser.isDevops() and (row.status == 'active' or row.status == 'failed') %}
                    <input type="checkbox" form="bulkActionsForm" name="ids[]" value="{{ row.id }}" class="bulkRowCheckbox">
                    {% endif %}
                </td>
                <td class="px-4 py-3 text-gray-700 dark:text-gray-300">{{ row.id }}</td>
                <td class="px-4 py-3 text-gray-700 dark:text-gray-300">
                    <span class="inline-flex items-center gap-1">
                        {{ row.developer_name }}
                        {% if row.note %}
                        <svg class="h-3.5 w-3.5 shrink-0 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true" title="{{ row.note|e }}">
                            <circle cx="12" cy="12" r="9"/>
                            <path stroke-linecap="round" d="M12 11v5m0-8h.01"/>
                        </svg>
                        {% endif %}
                    </span>
                </td>
                <td class="px-4 py-3 text-gray-700 dark:text-gray-300">{{ row.deployment_name }}</td>
                <td class="px-4 py-3 text-gray-700 dark:text-gray-300">{{ row.namespace }}</td>
                <td class="px-4 py-3 text-gray-700 dark:text-gray-300">
                    {% if row.request_type == 'ingress' %}Ingress + TLS{% else %}NodePort{% endif %}
                </td>
                <td class="px-4 py-3 text-gray-700 dark:text-gray-300">
                    {% if row.request_type == 'ingress' %}
                        {% if row.host %}https://{{ row.host }}{% else %}<span class="text-gray-400 dark:text-gray-600">รอดำเนินการ</span>{% endif %}
                    {% else %}
                        {% if row.node_port %}{{ row.node_ip }}:{{ row.node_port }}{% else %}<span class="text-gray-400 dark:text-gray-600">รอดำเนินการ</span>{% endif %}
                    {% endif %}
                </td>
                <td class="px-4 py-3 text-gray-700 dark:text-gray-300">{{ row.created_at }}</td>
                <td class="px-4 py-3 text-gray-700 dark:text-gray-300">
                    {% if row.expires_at %}{{ row.expires_at }}{% else %}<span class="text-gray-400 dark:text-gray-600">-</span>{% endif %}
                </td>
                <td class="px-4 py-3">{% include "partials/badge" with ["status": row.status] %}</td>
                <td class="px-4 py-3">
                    {% if editableIds[row.id] is defined and currentUser.isDevops() %}
                    <a href="/ingress/{{ row.id }}/edit" class="inline-flex items-center gap-1.5 rounded-lg border border-gray-300 bg-white px-2.5 py-1.5 text-xs font-medium text-gray-700 shadow-sm transition hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700">
                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m16.5 4.5 3 3L7 20H4v-3L16.5 4.5Z"/>
                        </svg>
                        แก้ไข
                    </a>
                    {% endif %}
                    {% if row.status == 'active' and currentUser.isDevops() %}
                    <form class="inline" method="post" action="/ingress/{{ row.id }}/delete" onsubmit="return confirm('ยืนยันลบ?');">
                        <input type="hidden" name="{{ security.getTokenKey() }}" value="{{ security.getToken() }}">
                        <button type="submit" class="inline-flex cursor-pointer items-center gap-1.5 rounded-lg border border-red-200 bg-red-50 px-2.5 py-1.5 text-xs font-medium text-red-700 transition hover:bg-red-100 dark:border-red-500/20 dark:bg-red-500/10 dark:text-red-400 dark:hover:bg-red-500/20">
                            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M5 7h14M9 7V5a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2m-8 0 .8 12.1a1 1 0 0 0 1 .9h6.4a1 1 0 0 0 1-.9L17 7"/>
                            </svg>
                            ลบ
                        </button>
                    </form>
                    {% elseif row.status == 'failed' and currentUser.isDevops() %}
                    <form class="inline" method="post" action="/ingress/{{ row.id }}/retry" onsubmit="return confirm('ลองใหม่อีกครั้ง?');">
                        <input type="hidden" name="{{ security.getTokenKey() }}" value="{{ security.getToken() }}">
                        <button type="submit" class="inline-flex cursor-pointer items-center gap-1.5 rounded-lg border border-blue-200 bg-blue-50 px-2.5 py-1.5 text-xs font-medium text-blue-700 transition hover:bg-blue-100 dark:border-blue-500/20 dark:bg-blue-500/10 dark:text-blue-400 dark:hover:bg-blue-500/20">
                            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h5M20 20v-5h-5M4 9a8 8 0 0 1 14.5-4.5M20 15a8 8 0 0 1-14.5 4.5"/>
                            </svg>
                            ลองใหม่
                        </button>
                    </form>
                    {% endif %}
                </td>
            </tr>
            {% else %}
            <tr><td class="px-4 py-8 text-center text-gray-500 dark:text-gray-400" colspan="11">
                <div class="animate-fade-in flex flex-col items-center gap-2">
                    <svg class="h-8 w-8 text-gray-300 dark:text-gray-700" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 9.5 5.5 4h13L21 9.5m-18 0V19a1 1 0 0 0 1 1h16a1 1 0 0 0 1-1V9.5m-18 0h18M9 13.5a3 3 0 0 0 6 0"/>
                    </svg>
                    <span>ยังไม่มีรายการ</span>
                </div>
            </td></tr>
            {% endfor %}
        </tbody>
    </table>
</div>

{% if currentUser.isDevops() %}
<script>
var selectAll = document.getElementById('bulkSelectAll');
var rowCheckboxes = Array.prototype.slice.call(document.querySelectorAll('.bulkRowCheckbox'));
var countLabel = document.getElementById('bulkSelectedCount');
var deleteBtn = document.getElementById('bulkDeleteBtn');
var retryBtn = document.getElementById('bulkRetryBtn');

function updateBulkState() {
    var checked = rowCheckboxes.filter(function (cb) { return cb.checked; });
    countLabel.textContent = 'เลือกแล้ว ' + checked.length + ' รายการ';
    deleteBtn.disabled = checked.length === 0;
    retryBtn.disabled = checked.length === 0;
}

if (selectAll) {
    selectAll.addEventListener('change', function () {
        rowCheckboxes.forEach(function (cb) { cb.checked = selectAll.checked; });
        updateBulkState();
    });
}

rowCheckboxes.forEach(function (cb) {
    cb.addEventListener('change', updateBulkState);
});
</script>
{% endif %}
{% endblock %}
