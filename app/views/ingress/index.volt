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
                    <button type="button" class="renewBtn inline-flex cursor-pointer items-center gap-1.5 rounded-lg border border-emerald-200 bg-emerald-50 px-2.5 py-1.5 text-xs font-medium text-emerald-700 transition hover:bg-emerald-100 dark:border-emerald-500/20 dark:bg-emerald-500/10 dark:text-emerald-400 dark:hover:bg-emerald-500/20" data-id="{{ row.id }}" data-expires-at="{{ row.expires_at }}" data-developer-name="{{ row.developer_name|e }}">
                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <circle cx="12" cy="12" r="9"/>
                            <path stroke-linecap="round" d="M12 7v5l3 3"/>
                        </svg>
                        ต่ออายุ
                    </button>
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
<dialog id="renewDialog" class="w-full max-w-sm rounded-xl border border-gray-200 bg-white p-0 shadow-xl backdrop:bg-black/40 dark:border-gray-700 dark:bg-gray-900">
    <form id="renewForm" method="post" class="p-5">
        <input type="hidden" name="{{ security.getTokenKey() }}" value="{{ security.getToken() }}">
        <input type="hidden" name="additional_minutes" id="renewMinutesInput" value="">

        <h3 class="text-sm font-semibold text-gray-900 dark:text-white">ต่ออายุ Ingress</h3>
        <p class="mb-4 mt-0.5 text-xs text-gray-500 dark:text-gray-400">
            <span id="renewDeveloperName"></span> &middot; หมดอายุปัจจุบัน <span id="renewCurrentExpiry" class="font-medium text-gray-700 dark:text-gray-300"></span>
        </p>

        <div class="mb-4 flex flex-wrap gap-1.5">
            <button type="button" class="renewPresetBtn rounded-full border border-gray-300 bg-white px-3 py-1 text-xs font-medium text-gray-700 transition hover:border-emerald-300 hover:bg-emerald-50 hover:text-emerald-700 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300 dark:hover:border-emerald-500/30 dark:hover:bg-emerald-500/10 dark:hover:text-emerald-400" data-minutes="60">+1 ชม.</button>
            <button type="button" class="renewPresetBtn rounded-full border border-gray-300 bg-white px-3 py-1 text-xs font-medium text-gray-700 transition hover:border-emerald-300 hover:bg-emerald-50 hover:text-emerald-700 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300 dark:hover:border-emerald-500/30 dark:hover:bg-emerald-500/10 dark:hover:text-emerald-400" data-minutes="360">+6 ชม.</button>
            <button type="button" class="renewPresetBtn rounded-full border border-gray-300 bg-white px-3 py-1 text-xs font-medium text-gray-700 transition hover:border-emerald-300 hover:bg-emerald-50 hover:text-emerald-700 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300 dark:hover:border-emerald-500/30 dark:hover:bg-emerald-500/10 dark:hover:text-emerald-400" data-minutes="1440">+1 วัน</button>
            <button type="button" class="renewPresetBtn rounded-full border border-gray-300 bg-white px-3 py-1 text-xs font-medium text-gray-700 transition hover:border-emerald-300 hover:bg-emerald-50 hover:text-emerald-700 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300 dark:hover:border-emerald-500/30 dark:hover:bg-emerald-500/10 dark:hover:text-emerald-400" data-minutes="4320">+3 วัน</button>
            <button type="button" class="renewPresetBtn rounded-full border border-gray-300 bg-white px-3 py-1 text-xs font-medium text-gray-700 transition hover:border-emerald-300 hover:bg-emerald-50 hover:text-emerald-700 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300 dark:hover:border-emerald-500/30 dark:hover:bg-emerald-500/10 dark:hover:text-emerald-400" data-minutes="10080">+7 วัน</button>
        </div>

        <label class="mb-1.5 block text-xs font-medium text-gray-700 dark:text-gray-300" for="renewDatetimeInput">หรือกำหนดวันเวลาหมดอายุใหม่เอง</label>
        <input type="datetime-local" id="renewDatetimeInput" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm transition focus:border-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-500/20 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100">
        <p id="renewPreview" class="mt-1.5 min-h-[1rem] text-xs text-gray-500 dark:text-gray-400"></p>

        <div class="mt-4 flex justify-end gap-2">
            <button type="button" id="renewCancelBtn" class="inline-flex items-center rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-xs font-medium text-gray-700 transition hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700">ยกเลิก</button>
            <button type="submit" id="renewSubmitBtn" disabled class="inline-flex items-center rounded-lg bg-emerald-600 px-3.5 py-1.5 text-xs font-medium text-white shadow-sm transition hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-40">ต่ออายุ</button>
        </div>
    </form>
</dialog>

<script>
// Re-run after each live-refresh (see the EventSource block below), since
// swapping <tbody> innerHTML replaces these checkboxes and drops listeners
// bound to the old ones.
function initBulkControls() {
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

    updateBulkState();
}

initBulkControls();

// The renew <dialog>/<form> live outside <tbody> (rendered once, not
// swapped by live-refresh) — so its own internals (presets, datetime
// input, cancel/submit) are wired up exactly once here. Only the trigger
// buttons on each row live inside <tbody> and need re-binding after every
// refresh — see initRenewTriggers() below.
(function () {
    var dialog = document.getElementById('renewDialog');
    var form = document.getElementById('renewForm');
    var minutesInput = document.getElementById('renewMinutesInput');
    var developerNameLabel = document.getElementById('renewDeveloperName');
    var currentExpiryLabel = document.getElementById('renewCurrentExpiry');
    var datetimeInput = document.getElementById('renewDatetimeInput');
    var preview = document.getElementById('renewPreview');
    var submitBtn = document.getElementById('renewSubmitBtn');
    var cancelBtn = document.getElementById('renewCancelBtn');
    var currentExpiresAt = null;
    var maxMinutes = 10080;

    function pad(n) {
        return String(n).padStart(2, '0');
    }

    // datetime-local wants "YYYY-MM-DDTHH:mm" in the *local* timezone —
    // toISOString() would shift it to UTC, so build the string by hand.
    function toLocalInputValue(date) {
        return date.getFullYear() + '-' + pad(date.getMonth() + 1) + '-' + pad(date.getDate())
            + 'T' + pad(date.getHours()) + ':' + pad(date.getMinutes());
    }

    function setPreview(text, isError) {
        preview.textContent = text;
        preview.classList.toggle('text-red-500', !!isError);
        preview.classList.toggle('dark:text-red-400', !!isError);
    }

    function applyMinutes(minutes) {
        if (!Number.isInteger(minutes) || minutes < 1 || minutes > maxMinutes) {
            minutesInput.value = '';
            submitBtn.disabled = true;
            setPreview('กรุณาเลือกเวลาใหม่ที่มากกว่าเวลาเดิม ไม่เกิน ' + maxMinutes + ' นาที (7 วัน)', true);
            return;
        }

        minutesInput.value = minutes;
        submitBtn.disabled = false;
        var days = Math.floor(minutes / 1440);
        var hours = Math.floor((minutes % 1440) / 60);
        var mins = minutes % 60;
        var parts = [];
        if (days) parts.push(days + ' วัน');
        if (hours) parts.push(hours + ' ชม.');
        if (mins || parts.length === 0) parts.push(mins + ' นาที');
        setPreview('ต่ออายุ ' + parts.join(' ') + ' — หมดอายุใหม่: ' + datetimeInput.value.replace('T', ' '), false);
    }

    function onDatetimeChange() {
        if (!datetimeInput.value || !currentExpiresAt) {
            minutesInput.value = '';
            submitBtn.disabled = true;
            setPreview('', false);
            return;
        }

        var picked = new Date(datetimeInput.value);
        var diffMinutes = Math.round((picked.getTime() - currentExpiresAt.getTime()) / 60000);
        applyMinutes(diffMinutes);
    }

    document.querySelectorAll('.renewPresetBtn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var minutes = parseInt(btn.dataset.minutes, 10);
            var newDate = new Date(currentExpiresAt.getTime() + minutes * 60000);
            datetimeInput.value = toLocalInputValue(newDate);
            applyMinutes(minutes);
        });
    });

    datetimeInput.addEventListener('change', onDatetimeChange);
    cancelBtn.addEventListener('click', function () {
        dialog.close();
    });
    // showModal()'s ::backdrop covers the full viewport regardless of where
    // the dialog itself is positioned — clicking it (not the form inside)
    // closes the dialog, same as clicking Cancel.
    dialog.addEventListener('click', function (event) {
        if (event.target === dialog) {
            dialog.close();
        }
    });

    // Anchored next to the row's own button instead of the browser's
    // default dead-center placement, so it stays visually tied to the row
    // being renewed. Clamped to the viewport, flipping above the button
    // when there isn't enough room below.
    function positionNear(btn) {
        var margin = 8;
        var btnRect = btn.getBoundingClientRect();
        var dialogRect = dialog.getBoundingClientRect();

        var left = Math.min(
            Math.max(margin, btnRect.left),
            window.innerWidth - dialogRect.width - margin
        );

        var top = btnRect.bottom + margin;
        if (top + dialogRect.height > window.innerHeight - margin) {
            top = btnRect.top - dialogRect.height - margin;
        }
        top = Math.max(margin, top);

        dialog.style.position = 'fixed';
        dialog.style.inset = 'auto';
        dialog.style.margin = '0';
        dialog.style.top = top + 'px';
        dialog.style.left = left + 'px';
    }

    window.openRenewDialog = function (btn) {
        currentExpiresAt = new Date(btn.dataset.expiresAt.replace(' ', 'T'));
        form.action = '/ingress/' + btn.dataset.id + '/renew';
        developerNameLabel.textContent = btn.dataset.developerName;
        currentExpiryLabel.textContent = btn.dataset.expiresAt;
        datetimeInput.min = toLocalInputValue(new Date(currentExpiresAt.getTime() + 60000));
        datetimeInput.value = '';
        minutesInput.value = '';
        submitBtn.disabled = true;
        setPreview('', false);
        dialog.showModal();
        positionNear(btn);
    };
})();

// Re-run after each live-refresh (see the EventSource block below), since
// swapping <tbody> innerHTML drops listeners bound to the old row buttons.
function initRenewTriggers() {
    document.querySelectorAll('.renewBtn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            window.openRenewDialog(btn);
        });
    });
}

initRenewTriggers();
</script>
{% endif %}

<script>
// Live status updates: the /ingress table doesn't otherwise reflect status
// changes (pending -> active/failed, active -> expired/deleted) until the
// page is manually reloaded. See app/controllers/EventsController.php.
(function () {
    var tbody = document.querySelector('table tbody');
    if (!tbody || typeof EventSource === 'undefined') {
        return;
    }

    var refreshing = false;

    function refresh() {
        if (refreshing) {
            return;
        }
        refreshing = true;

        var checkedIds = Array.prototype.slice.call(document.querySelectorAll('.bulkRowCheckbox:checked'))
            .map(function (cb) { return cb.value; });

        fetch(window.location.href)
            .then(function (res) { return res.text(); })
            .then(function (html) {
                var newTbody = new DOMParser().parseFromString(html, 'text/html').querySelector('table tbody');
                if (!newTbody) {
                    return;
                }
                tbody.innerHTML = newTbody.innerHTML;
                checkedIds.forEach(function (id) {
                    var cb = tbody.querySelector('.bulkRowCheckbox[value="' + id + '"]');
                    if (cb) {
                        cb.checked = true;
                    }
                });
                if (typeof initBulkControls === 'function') {
                    initBulkControls();
                }
                if (typeof initRenewTriggers === 'function') {
                    initRenewTriggers();
                }
            })
            .catch(function () {})
            .finally(function () { refreshing = false; });
    }

    new EventSource('/events/stream?channel=ingress').addEventListener('update', refresh);
})();
</script>
{% endblock %}
