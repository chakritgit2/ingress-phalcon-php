{% extends "layouts/main.volt" %}
{% block content %}
<h1 class="mb-6 text-2xl font-bold text-gray-900">รายละเอียด Ingress #{{ row.id }}</h1>

<div class="overflow-hidden rounded-lg border border-gray-200">
    <table class="w-full border-collapse text-sm">
        <tbody class="divide-y divide-gray-100">
            <tr><th class="w-56 bg-gray-50 px-4 py-3 text-left text-sm font-medium text-gray-600">ใคร</th><td class="px-4 py-3 text-gray-800">{{ row.developer_name }}</td></tr>
            <tr><th class="w-56 bg-gray-50 px-4 py-3 text-left text-sm font-medium text-gray-600">ใช้อะไร</th><td class="px-4 py-3 text-gray-800">{{ row.deployment_name }}</td></tr>
            <tr><th class="w-56 bg-gray-50 px-4 py-3 text-left text-sm font-medium text-gray-600">ที่ Namespace อะไร</th><td class="px-4 py-3 text-gray-800">{{ row.namespace }}</td></tr>
            <tr><th class="w-56 bg-gray-50 px-4 py-3 text-left text-sm font-medium text-gray-600">ออกที่ไหน</th><td class="px-4 py-3 text-gray-800">{{ row.node_ip }}:{{ row.node_port }}</td></tr>
            <tr><th class="w-56 bg-gray-50 px-4 py-3 text-left text-sm font-medium text-gray-600">เมื่อไหร</th><td class="px-4 py-3 text-gray-800">{{ row.created_at }}</td></tr>
            <tr><th class="w-56 bg-gray-50 px-4 py-3 text-left text-sm font-medium text-gray-600">กำหนดหมดอายุ</th><td class="px-4 py-3 text-gray-800">{{ row.expires_at }}</td></tr>
            <tr><th class="w-56 bg-gray-50 px-4 py-3 text-left text-sm font-medium text-gray-600">สถานะ</th><td class="px-4 py-3">{% include "partials/badge" with ["status": row.status] %}</td></tr>
            {% if row.deleted_at %}
            <tr><th class="w-56 bg-gray-50 px-4 py-3 text-left text-sm font-medium text-gray-600">ลบจริงเมื่อ</th><td class="px-4 py-3 text-gray-800">{{ row.deleted_at }} ({{ row.deleted_by }})</td></tr>
            {% endif %}
            {% if row.last_error %}
            <tr><th class="w-56 bg-gray-50 px-4 py-3 text-left text-sm font-medium text-gray-600">Error ล่าสุด</th><td class="px-4 py-3 text-red-700">{{ row.last_error }}</td></tr>
            {% endif %}
        </tbody>
    </table>
</div>

<h2 class="mt-8 mb-4 text-lg font-semibold text-gray-900">Trail</h2>
<div class="overflow-x-auto rounded-lg border border-gray-200">
    <table class="w-full border-collapse text-sm">
        <thead>
            <tr>
                <th class="bg-gray-50 px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">เวลา</th>
                <th class="bg-gray-50 px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Event</th>
                <th class="bg-gray-50 px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Actor</th>
                <th class="bg-gray-50 px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">รายละเอียด</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            {% for e in events %}
            <tr class="transition hover:bg-gray-50">
                <td class="px-4 py-3 text-gray-700">{{ e.created_at }}</td>
                <td class="px-4 py-3 text-gray-700">{{ e.event_type }}</td>
                <td class="px-4 py-3 text-gray-700">{{ e.actor_label }}</td>
                <td class="px-4 py-3 text-gray-700">{{ e.detail }}</td>
            </tr>
            {% else %}
            <tr><td class="px-4 py-8 text-center text-gray-500" colspan="4">
                <div class="animate-fade-in flex flex-col items-center gap-2">
                    <svg class="h-8 w-8 text-gray-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 9.5 5.5 4h13L21 9.5m-18 0V19a1 1 0 0 0 1 1h16a1 1 0 0 0 1-1V9.5m-18 0h18M9 13.5a3 3 0 0 0 6 0"/>
                    </svg>
                    <span>ไม่มีข้อมูล</span>
                </div>
            </td></tr>
            {% endfor %}
        </tbody>
    </table>
</div>


<h2 class="mt-8 mb-4 text-lg font-semibold text-gray-900">คำสั่งที่ส่งไป Kubernetes</h2>
<div class="overflow-x-auto rounded-lg border border-gray-200">
    <table class="w-full border-collapse text-sm">
        <thead>
            <tr>
                <th class="bg-gray-50 px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">เวลา</th>
                <th class="bg-gray-50 px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Action</th>
                <th class="bg-gray-50 px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">สถานะ</th>
                <th class="bg-gray-50 px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Request ที่ส่งไปจริง</th>
                <th class="bg-gray-50 px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">ผลลัพธ์ / Error</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            {% for c in commands %}
            <tr class="transition hover:bg-gray-50 align-top">
                <td class="px-4 py-3 whitespace-nowrap text-gray-700">
                    <div>ส่ง: {{ c.created_at }}</div>
                    {% if c.processed_at %}<div>เสร็จ: {{ c.processed_at }}</div>{% endif %}
                </td>
                <td class="px-4 py-3 text-gray-700">{{ c.action }}</td>
                <td class="px-4 py-3">{% include "partials/badge" with ["status": c.status] %}</td>
                <td class="px-4 py-3 font-mono text-xs text-gray-600">
                    {% if c.request_payload %}
                    {% if c.payload_source == 'preview' %}
                    <span class="mb-1 inline-flex items-center gap-1.5 rounded-full bg-gray-200 px-2.5 py-0.5 text-xs font-medium text-gray-700"><span class="h-1.5 w-1.5 rounded-full bg-gray-500"></span>แผน (ยังไม่ส่ง)</span>
                    {% elseif c.payload_source == 'sent' %}
                    <span class="mb-1 inline-flex items-center gap-1.5 rounded-full bg-green-100 px-2.5 py-0.5 text-xs font-medium text-green-800"><span class="h-1.5 w-1.5 rounded-full bg-green-500"></span>ส่งจริง</span>
                    {% endif %}
                    <pre class="whitespace-pre-wrap break-all">{{ c.request_payload }}</pre>
                    {% else %}<span class="text-gray-400">-</span>{% endif %}
                </td>
                <td class="px-4 py-3 font-mono text-xs text-gray-600">
                    {% if c.error_message %}<span class="text-red-700">{{ c.error_message }}</span>
                    {% elseif c.result %}<pre class="whitespace-pre-wrap break-all">{{ c.result }}</pre>
                    {% else %}<span class="text-gray-400">-</span>{% endif %}
                </td>
            </tr>
            {% else %}
            <tr><td class="px-4 py-8 text-center text-gray-500" colspan="5">
                <div class="animate-fade-in flex flex-col items-center gap-2">
                    <span>ไม่มีข้อมูล</span>
                </div>
            </td></tr>
            {% endfor %}
        </tbody>
    </table>
</div>

<p class="mt-6"><a class="inline-flex items-center gap-1 text-sm font-medium text-gray-600 transition hover:text-gray-900" href="/audit">
    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
        <path stroke-linecap="round" stroke-linejoin="round" d="m15 19-7-7 7-7"/>
    </svg>
    กลับ
</a></p>
{% endblock %}
