{% extends "layouts/main.volt" %}
{% block container_class %}max-w-7xl{% endblock %}
{% block content %}
<div class="mb-6 flex flex-wrap items-center justify-between gap-3 border-b border-gray-200 pb-4 dark:border-gray-800">
    <h1 class="text-xl font-semibold text-gray-900 dark:text-white">Security Log</h1>
    <a href="/audit/security/export" class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-lg border border-gray-300 bg-white px-3.5 py-1.5 text-sm font-medium text-gray-700 shadow-sm transition hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700">
        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v12m0 0-4-4m4 4 4-4M5 19h14"/>
        </svg>
        Export CSV
    </a>
</div>

<div class="overflow-x-auto">
    <table class="w-full min-w-[720px] text-sm">
        <thead>
            <tr>
                <th class="bg-gray-50 px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:bg-gray-900 dark:text-gray-400">เวลา</th>
                <th class="bg-gray-50 px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:bg-gray-900 dark:text-gray-400">เหตุการณ์</th>
                <th class="bg-gray-50 px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:bg-gray-900 dark:text-gray-400">ผู้ใช้</th>
                <th class="bg-gray-50 px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:bg-gray-900 dark:text-gray-400">รายละเอียด</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100 bg-white dark:divide-gray-800 dark:bg-gray-900">
            {% for e in events %}
            {% set detail = e.getDetailArray() %}
            <tr class="transition hover:bg-gray-50 dark:hover:bg-gray-800/60">
                <td class="whitespace-nowrap px-4 py-3 text-gray-700 dark:text-gray-300">{{ e.created_at }}</td>
                <td class="px-4 py-3">
                    {% include "partials/event_badge" with ["event_type": e.event_type, "detail": detail] %}
                </td>
                <td class="px-4 py-3 text-gray-700 dark:text-gray-300">{{ e.actor_label }}</td>
                <td class="px-4 py-3 text-gray-500 dark:text-gray-400">
                    {% include "partials/event_detail" with ["event_type": e.event_type, "detail": detail] %}
                </td>
            </tr>
            {% else %}
            <tr><td class="px-4 py-8 text-center text-gray-500 dark:text-gray-400" colspan="4">
                <div class="animate-fade-in flex flex-col items-center gap-2">
                    <span>ไม่มีข้อมูล</span>
                </div>
            </td></tr>
            {% endfor %}
        </tbody>
    </table>
</div>

<p class="mt-4 flex gap-3">
    {% if page > 1 %}<a class="inline-flex shrink-0 items-center whitespace-nowrap rounded-lg border border-gray-300 bg-white px-3.5 py-1.5 text-sm font-medium text-gray-700 shadow-sm transition hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700" href="/audit/security?page={{ page - 1 }}">&laquo; ก่อนหน้า</a>{% endif %}
    {% if events|length >= 50 %}<a class="inline-flex shrink-0 items-center whitespace-nowrap rounded-lg border border-gray-300 bg-white px-3.5 py-1.5 text-sm font-medium text-gray-700 shadow-sm transition hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700" href="/audit/security?page={{ page + 1 }}">ถัดไป &raquo;</a>{% endif %}
</p>

<p class="mt-6"><a class="inline-flex items-center gap-1 text-sm font-medium text-gray-600 transition hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-200" href="/audit">
    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
        <path stroke-linecap="round" stroke-linejoin="round" d="m15 19-7-7 7-7"/>
    </svg>
    กลับไป Audit Log
</a></p>
{% endblock %}
