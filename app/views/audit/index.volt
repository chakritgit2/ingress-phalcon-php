{% extends "layouts/main.volt" %}
{% block container_class %}max-w-7xl{% endblock %}
{% block content %}
<div class="mb-6 flex flex-wrap items-center justify-between gap-3 border-b border-gray-200 pb-4 dark:border-gray-800">
    <h1 class="text-xl font-semibold text-gray-900 dark:text-white">Audit Log</h1>
    <a href="/audit/security" class="inline-flex items-center gap-1.5 text-sm font-medium text-gray-600 transition hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-200">
        Security Log
        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="m9 5 7 7-7 7"/>
        </svg>
    </a>
</div>

<div class="overflow-x-auto">
    <table class="w-full min-w-[900px] text-sm">
        <thead>
            <tr>
                <th class="sticky top-0 z-10 bg-gray-50 px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:bg-gray-900 dark:text-gray-400">ใคร</th>
                <th class="sticky top-0 z-10 bg-gray-50 px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:bg-gray-900 dark:text-gray-400">ใช้อะไร</th>
                <th class="sticky top-0 z-10 bg-gray-50 px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:bg-gray-900 dark:text-gray-400">ที่ Namespace อะไร</th>
                <th class="sticky top-0 z-10 bg-gray-50 px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:bg-gray-900 dark:text-gray-400">ออกที่ไหน</th>
                <th class="sticky top-0 z-10 bg-gray-50 px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:bg-gray-900 dark:text-gray-400">เมื่อไหร</th>
                <th class="sticky top-0 z-10 bg-gray-50 px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:bg-gray-900 dark:text-gray-400">นานเท่าไหร</th>
                <th class="sticky top-0 z-10 bg-gray-50 px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:bg-gray-900 dark:text-gray-400"></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100 bg-white dark:divide-gray-800 dark:bg-gray-900">
            {% for row in rows %}
            <tr class="transition hover:bg-gray-50 dark:hover:bg-gray-800/60">
                <td class="px-4 py-3 text-gray-700 dark:text-gray-300">{{ row.developer_name }}</td>
                <td class="px-4 py-3 text-gray-700 dark:text-gray-300">{{ row.deployment_name }}</td>
                <td class="px-4 py-3 text-gray-700 dark:text-gray-300">{{ row.namespace }}</td>
                <td class="px-4 py-3 text-gray-700 dark:text-gray-300">{{ row.node_ip }}:{{ row.node_port }}</td>
                <td class="px-4 py-3 text-gray-700 dark:text-gray-300">{{ row.created_at }}</td>
                <td class="px-4 py-3 text-gray-700 dark:text-gray-300">
                    <div>กำหนด: {{ row.expires_at }}</div>
                    {% if row.deleted_at %}<div>จริง: {{ row.deleted_at }} ({{ row.deleted_by }})</div>{% endif %}
                    <div class="mt-1">{% include "partials/badge" with ["status": row.status] %}</div>
                </td>
                <td class="whitespace-nowrap px-4 py-3">
                    <a class="inline-flex items-center gap-1 text-sm font-medium text-blue-600 transition hover:text-blue-800 hover:underline dark:text-blue-400 dark:hover:text-blue-300" href="/audit/{{ row.id }}">
                        รายละเอียด
                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m9 5 7 7-7 7"/>
                        </svg>
                    </a>
                </td>
            </tr>
            {% else %}
            <tr><td class="px-4 py-8 text-center text-gray-500 dark:text-gray-400" colspan="7">
                <div class="animate-fade-in flex flex-col items-center gap-2">
                    <svg class="h-8 w-8 text-gray-300 dark:text-gray-700" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
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
    {% if page > 1 %}<a class="inline-flex shrink-0 items-center whitespace-nowrap rounded-lg border border-gray-300 bg-white px-3.5 py-1.5 text-sm font-medium text-gray-700 shadow-sm transition hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700" href="/audit?page={{ page - 1 }}">&laquo; ก่อนหน้า</a>{% endif %}
    {% if rows|length >= 50 %}<a class="inline-flex shrink-0 items-center whitespace-nowrap rounded-lg border border-gray-300 bg-white px-3.5 py-1.5 text-sm font-medium text-gray-700 shadow-sm transition hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700" href="/audit?page={{ page + 1 }}">ถัดไป &raquo;</a>{% endif %}
</p>
{% endblock %}
