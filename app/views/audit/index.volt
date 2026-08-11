{% extends "layouts/main.volt" %}
{% block content %}
<h1 class="mb-6 text-2xl font-bold text-gray-900">Audit Log</h1>

<div class="overflow-x-auto rounded-lg border border-gray-200">
    <table class="w-full border-collapse text-sm">
        <thead>
            <tr>
                <th class="bg-gray-50 px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">ใคร</th>
                <th class="bg-gray-50 px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">ใช้อะไร</th>
                <th class="bg-gray-50 px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">ที่ Namespace อะไร</th>
                <th class="bg-gray-50 px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">ออกที่ไหน</th>
                <th class="bg-gray-50 px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">เมื่อไหร</th>
                <th class="bg-gray-50 px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">นานเท่าไหร</th>
                <th class="bg-gray-50 px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500"></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            {% for row in rows %}
            <tr class="transition hover:bg-gray-50">
                <td class="px-4 py-3 text-gray-700">{{ row.developer_name }}</td>
                <td class="px-4 py-3 text-gray-700">{{ row.deployment_name }}</td>
                <td class="px-4 py-3 text-gray-700">{{ row.namespace }}</td>
                <td class="px-4 py-3 text-gray-700">{{ row.node_ip }}:{{ row.node_port }}</td>
                <td class="px-4 py-3 text-gray-700">{{ row.created_at }}</td>
                <td class="px-4 py-3 text-gray-700">
                    <div>กำหนด: {{ row.expires_at }}</div>
                    {% if row.deleted_at %}<div>จริง: {{ row.deleted_at }} ({{ row.deleted_by }})</div>{% endif %}
                    <div class="mt-1">{% include "partials/badge" with ["status": row.status] %}</div>
                </td>
                <td class="whitespace-nowrap px-4 py-3">
                    <a class="inline-flex items-center gap-1 text-sm font-medium text-blue-600 transition hover:text-blue-800 hover:underline" href="/audit/{{ row.id }}">
                        รายละเอียด
                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m9 5 7 7-7 7"/>
                        </svg>
                    </a>
                </td>
            </tr>
            {% else %}
            <tr><td class="px-4 py-8 text-center text-gray-500" colspan="7">
                <div class="animate-fade-in flex flex-col items-center gap-2">
                    <svg class="h-8 w-8 text-gray-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 9.5 5.5 4h13L21 9.5m-18 0V19a1 1 0 0 0 1 1h16a1 1 0 0 0 1-1V9.5m-18 0h18M9 13.5a3 3 0 0 0 6 0"/>
                    </svg>
                    <span>ยังไม่มีข้อมูล</span>
                </div>
            </td></tr>
            {% endfor %}
        </tbody>
    </table>
</div>

<p class="mt-4 flex gap-3">
    {% if page > 1 %}<a class="inline-flex items-center rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 shadow-sm transition hover:bg-gray-50" href="/audit?page={{ page - 1 }}">&laquo; ก่อนหน้า</a>{% endif %}
    {% if rows|length >= 50 %}<a class="inline-flex items-center rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 shadow-sm transition hover:bg-gray-50" href="/audit?page={{ page + 1 }}">ถัดไป &raquo;</a>{% endif %}
</p>
{% endblock %}
