{% extends "layouts/main.volt" %}
{% block container_class %}max-w-7xl{% endblock %}
{% block content %}
<h1 class="mb-6 border-b border-gray-200 pb-4 text-xl font-semibold text-gray-900 dark:border-gray-800 dark:text-white">รายละเอียด Ingress #{{ row.id }}</h1>

<div class="max-w-2xl overflow-hidden rounded-lg border border-gray-200 dark:border-gray-800">
    <table class="w-full border-collapse text-sm">
        <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
            <tr><th class="w-56 bg-gray-50 px-4 py-3 text-left text-sm font-medium text-gray-600 dark:bg-gray-900 dark:text-gray-400">ใคร</th><td class="px-4 py-3 text-gray-800">{{ row.developer_name }}</td></tr>
            <tr><th class="w-56 bg-gray-50 px-4 py-3 text-left text-sm font-medium text-gray-600 dark:bg-gray-900 dark:text-gray-400">ใช้อะไร</th><td class="px-4 py-3 text-gray-800">{{ row.deployment_name }}</td></tr>
            <tr><th class="w-56 bg-gray-50 px-4 py-3 text-left text-sm font-medium text-gray-600 dark:bg-gray-900 dark:text-gray-400">ที่ Namespace อะไร</th><td class="px-4 py-3 text-gray-800">{{ row.namespace }}</td></tr>
            <tr><th class="w-56 bg-gray-50 px-4 py-3 text-left text-sm font-medium text-gray-600 dark:bg-gray-900 dark:text-gray-400">ออกที่ไหน</th><td class="px-4 py-3 text-gray-800">{{ row.node_ip }}:{{ row.node_port }}</td></tr>
            <tr><th class="w-56 bg-gray-50 px-4 py-3 text-left text-sm font-medium text-gray-600 dark:bg-gray-900 dark:text-gray-400">เมื่อไหร</th><td class="px-4 py-3 text-gray-800">{{ row.created_at }}</td></tr>
            <tr><th class="w-56 bg-gray-50 px-4 py-3 text-left text-sm font-medium text-gray-600 dark:bg-gray-900 dark:text-gray-400">กำหนดหมดอายุ</th><td class="px-4 py-3 text-gray-800">{{ row.expires_at }}</td></tr>
            <tr><th class="w-56 bg-gray-50 px-4 py-3 text-left text-sm font-medium text-gray-600 dark:bg-gray-900 dark:text-gray-400">สถานะ</th><td class="px-4 py-3">{% include "partials/badge" with ["status": row.status] %}</td></tr>
            {% if row.deleted_at %}
            <tr><th class="w-56 bg-gray-50 px-4 py-3 text-left text-sm font-medium text-gray-600 dark:bg-gray-900 dark:text-gray-400">ลบจริงเมื่อ</th><td class="px-4 py-3 text-gray-800">{{ row.deleted_at }} ({{ row.deleted_by }})</td></tr>
            {% endif %}
            {% if row.last_error %}
            <tr><th class="w-56 bg-gray-50 px-4 py-3 text-left text-sm font-medium text-gray-600 dark:bg-gray-900 dark:text-gray-400">Error ล่าสุด</th><td class="px-4 py-3 text-red-700 dark:text-red-400">{{ row.last_error }}</td></tr>
            {% endif %}
        </tbody>
    </table>
</div>

<h2 class="mt-8 mb-4 text-lg font-semibold text-gray-900 dark:text-white">Trail</h2>
<div class="overflow-x-auto">
    <table class="w-full min-w-[720px] text-sm">
        <thead>
            <tr>
                <th class="bg-gray-50 px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:bg-gray-900 dark:text-gray-400">เวลา</th>
                <th class="bg-gray-50 px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:bg-gray-900 dark:text-gray-400">Event</th>
                <th class="bg-gray-50 px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:bg-gray-900 dark:text-gray-400">Actor</th>
                <th class="bg-gray-50 px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:bg-gray-900 dark:text-gray-400">รายละเอียด</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100 bg-white dark:divide-gray-800 dark:bg-gray-900">
            {% for e in events %}
            {% set detail = e.getDetailArray() %}
            <tr class="transition hover:bg-gray-50 dark:hover:bg-gray-800/60">
                <td class="px-4 py-3 text-gray-700 dark:text-gray-300">{{ e.created_at }}</td>
                <td class="px-4 py-3">
                    {% include "partials/event_badge" with ["event_type": e.event_type, "detail": detail] %}
                </td>
                <td class="px-4 py-3 text-gray-700 dark:text-gray-300">{{ e.actor_label }}</td>
                <td class="px-4 py-3 text-gray-700 dark:text-gray-300">
                    {% include "partials/event_detail" with ["event_type": e.event_type, "detail": detail] %}
                </td>
            </tr>
            {% else %}
            <tr><td class="px-4 py-8 text-center text-gray-500 dark:text-gray-400" colspan="4">
                <div class="animate-fade-in flex flex-col items-center gap-2">
                    <svg class="h-8 w-8 text-gray-300 dark:text-gray-700" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 9.5 5.5 4h13L21 9.5m-18 0V19a1 1 0 0 0 1 1h16a1 1 0 0 0 1-1V9.5m-18 0h18M9 13.5a3 3 0 0 0 6 0"/>
                    </svg>
                    <span>ไม่มีข้อมูล</span>
                </div>
            </td></tr>
            {% endfor %}
        </tbody>
    </table>
</div>


<h2 class="mt-8 mb-4 text-lg font-semibold text-gray-900 dark:text-white">คำสั่งที่ส่งไป Kubernetes</h2>
<div class="overflow-x-auto">
    <table class="w-full min-w-[900px] text-sm">
        <thead>
            <tr>
                <th class="bg-gray-50 px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:bg-gray-900 dark:text-gray-400">เวลา</th>
                <th class="bg-gray-50 px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:bg-gray-900 dark:text-gray-400">Action</th>
                <th class="bg-gray-50 px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:bg-gray-900 dark:text-gray-400">สถานะ</th>
                <th class="bg-gray-50 px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:bg-gray-900 dark:text-gray-400">Request ที่ส่งไปจริง</th>
                <th class="bg-gray-50 px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:bg-gray-900 dark:text-gray-400">ผลลัพธ์ / Error</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100 bg-white dark:divide-gray-800 dark:bg-gray-900">
            {% for c in commands %}
            <tr class="transition hover:bg-gray-50 align-top dark:hover:bg-gray-800/60">
                <td class="px-4 py-3 whitespace-nowrap text-gray-700 dark:text-gray-300">
                    <div>ส่ง: {{ c.created_at }}</div>
                    {% if c.processed_at %}<div>เสร็จ: {{ c.processed_at }}</div>{% endif %}
                </td>
                <td class="px-4 py-3 text-gray-700 dark:text-gray-300">{{ c.action }}</td>
                <td class="px-4 py-3">{% include "partials/badge" with ["status": c.status] %}</td>
                <td class="px-4 py-3 font-mono text-xs text-gray-600 dark:text-gray-400">
                    {% if c.request_payload %}
                    {% if c.payload_source == 'preview' %}
                    <span class="mb-1 inline-flex items-center gap-1.5 rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-medium text-gray-600 ring-1 ring-inset ring-gray-500/10 dark:bg-gray-800 dark:text-gray-400 dark:ring-gray-700"><span class="h-1.5 w-1.5 rounded-full bg-gray-400"></span>แผน (ยังไม่ส่ง)</span>
                    {% elseif c.payload_source == 'sent' %}
                    <span class="mb-1 inline-flex items-center gap-1.5 rounded-full bg-green-50 px-2.5 py-0.5 text-xs font-medium text-green-700 ring-1 ring-inset ring-green-600/20 dark:bg-green-500/10 dark:text-green-400 dark:ring-green-500/20"><span class="h-1.5 w-1.5 rounded-full bg-green-500"></span>ส่งจริง</span>
                    {% endif %}
                    <pre class="whitespace-pre-wrap break-all">{{ c.request_payload }}</pre>
                    {% else %}<span class="text-gray-400 dark:text-gray-600">-</span>{% endif %}
                </td>
                <td class="px-4 py-3 font-mono text-xs text-gray-600 dark:text-gray-400">
                    {% if c.error_message %}<span class="text-red-700 dark:text-red-400">{{ c.error_message }}</span>
                    {% elseif c.result %}<pre class="whitespace-pre-wrap break-all">{{ c.result }}</pre>
                    {% else %}<span class="text-gray-400 dark:text-gray-600">-</span>{% endif %}
                </td>
            </tr>
            {% else %}
            <tr><td class="px-4 py-8 text-center text-gray-500 dark:text-gray-400" colspan="5">
                <div class="animate-fade-in flex flex-col items-center gap-2">
                    <span>ไม่มีข้อมูล</span>
                </div>
            </td></tr>
            {% endfor %}
        </tbody>
    </table>
</div>

<p class="mt-6"><a class="inline-flex items-center gap-1 text-sm font-medium text-gray-600 transition hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-200" href="/audit">
    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
        <path stroke-linecap="round" stroke-linejoin="round" d="m15 19-7-7 7-7"/>
    </svg>
    กลับ
</a></p>
{% endblock %}
