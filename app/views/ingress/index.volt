{% extends "layouts/main.volt" %}
{% block content %}
{% if currentUser.isDevops() %}
<div class="mb-4 flex items-center justify-between rounded-lg border {{ botEnabled ? 'border-green-200 bg-green-50' : 'border-red-200 bg-red-50' }} px-4 py-3">
    <span class="text-sm font-medium {{ botEnabled ? 'text-green-800' : 'text-red-800' }}">
        บอทประมวลผลคำขอ:
        {% if botEnabled %}
            กำลังทำงาน
        {% else %}
            ปิดอยู่ (คำขอใหม่จะค้างจนกว่าจะเปิด)
        {% endif %}
    </span>
    {% if botKillSwitchActive %}
        <span class="text-xs text-gray-500">ถูกบังคับปิดโดย env var BOT_ENABLED</span>
    {% else %}
        <form method="post" action="/ingress/toggle-bot">
            <input type="hidden" name="{{ security.getTokenKey() }}" value="{{ security.getToken() }}">
            <button type="submit" class="inline-flex cursor-pointer items-center gap-1.5 rounded-lg border px-3 py-1.5 text-xs font-medium transition {{ botEnabled ? 'border-red-200 bg-white text-red-700 hover:bg-red-100' : 'border-green-200 bg-white text-green-700 hover:bg-green-100' }}">
                {% if botEnabled %}ปิดบอท{% else %}เปิดบอท{% endif %}
            </button>
        </form>
    {% endif %}
</div>
{% endif %}
<div class="mb-6 flex items-center justify-between">
    <h1 class="text-2xl font-bold text-gray-900">Ingress ที่เปิดอยู่</h1>
    {% if currentUser.isDevops() %}
    <a href="/ingress/create" class="inline-flex items-center gap-1.5 rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white shadow-sm transition hover:bg-blue-700">
        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <circle cx="12" cy="12" r="9"/>
            <path stroke-linecap="round" d="M12 8v8M8 12h8"/>
        </svg>
        สร้าง Ingress ใหม่
    </a>
    {% endif %}
</div>

<div class="overflow-x-auto rounded-lg border border-gray-200">
    <table class="w-full border-collapse text-sm">
        <thead>
            <tr>
                <th class="bg-gray-50 px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">ใคร</th>
                <th class="bg-gray-50 px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">ใช้อะไร</th>
                <th class="bg-gray-50 px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Namespace</th>
                <th class="bg-gray-50 px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">ประเภท</th>
                <th class="bg-gray-50 px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">ออกที่ไหน</th>
                <th class="bg-gray-50 px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">เมื่อไหร</th>
                <th class="bg-gray-50 px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">หมดอายุ</th>
                <th class="bg-gray-50 px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">สถานะ</th>
                <th class="bg-gray-50 px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500"></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            {% for row in rows %}
            <tr class="transition duration-150 hover:bg-gray-50">
                <td class="px-4 py-3 text-gray-700">{{ row.developer_name }}</td>
                <td class="px-4 py-3 text-gray-700">{{ row.deployment_name }}</td>
                <td class="px-4 py-3 text-gray-700">{{ row.namespace }}</td>
                <td class="px-4 py-3 text-gray-700">
                    {% if row.request_type == 'ingress' %}Ingress + TLS{% else %}NodePort{% endif %}
                </td>
                <td class="px-4 py-3 text-gray-700">
                    {% if row.request_type == 'ingress' %}
                        {% if row.host %}https://{{ row.host }}{% else %}<span class="text-gray-400">รอดำเนินการ</span>{% endif %}
                    {% else %}
                        {% if row.node_port %}{{ row.node_ip }}:{{ row.node_port }}{% else %}<span class="text-gray-400">รอดำเนินการ</span>{% endif %}
                    {% endif %}
                </td>
                <td class="px-4 py-3 text-gray-700">{{ row.created_at }}</td>
                <td class="px-4 py-3 text-gray-700">
                    {% if row.expires_at %}{{ row.expires_at }}{% else %}<span class="text-gray-400">-</span>{% endif %}
                </td>
                <td class="px-4 py-3">{% include "partials/badge" with ["status": row.status] %}</td>
                <td class="px-4 py-3">
                    {% if row.status == 'active' and currentUser.isDevops() %}
                    <form class="inline" method="post" action="/ingress/{{ row.id }}/delete" onsubmit="return confirm('ยืนยันลบ?');">
                        <input type="hidden" name="{{ security.getTokenKey() }}" value="{{ security.getToken() }}">
                        <button type="submit" class="inline-flex cursor-pointer items-center gap-1.5 rounded-lg border border-red-200 bg-red-50 px-3 py-1.5 text-xs font-medium text-red-700 transition hover:bg-red-100">
                            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M5 7h14M9 7V5a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2m-8 0 .8 12.1a1 1 0 0 0 1 .9h6.4a1 1 0 0 0 1-.9L17 7"/>
                            </svg>
                            ลบ
                        </button>
                    </form>
                    {% elseif row.status == 'failed' and currentUser.isDevops() %}
                    <form class="inline" method="post" action="/ingress/{{ row.id }}/retry" onsubmit="return confirm('ลองใหม่อีกครั้ง?');">
                        <input type="hidden" name="{{ security.getTokenKey() }}" value="{{ security.getToken() }}">
                        <button type="submit" class="inline-flex cursor-pointer items-center gap-1.5 rounded-lg border border-blue-200 bg-blue-50 px-3 py-1.5 text-xs font-medium text-blue-700 transition hover:bg-blue-100">
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
            <tr><td class="px-4 py-8 text-center text-gray-500" colspan="9">
                <div class="animate-fade-in flex flex-col items-center gap-2">
                    <svg class="h-8 w-8 text-gray-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 9.5 5.5 4h13L21 9.5m-18 0V19a1 1 0 0 0 1 1h16a1 1 0 0 0 1-1V9.5m-18 0h18M9 13.5a3 3 0 0 0 6 0"/>
                    </svg>
                    <span>ยังไม่มีรายการ</span>
                </div>
            </td></tr>
            {% endfor %}
        </tbody>
    </table>
</div>
{% endblock %}
