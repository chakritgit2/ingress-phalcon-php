{% extends "layouts/main.volt" %}
{% block content %}
<h1 class="mb-6 text-2xl font-bold text-gray-900">Security Log</h1>

<div class="overflow-x-auto rounded-lg border border-gray-200">
    <table class="w-full border-collapse text-sm">
        <thead>
            <tr>
                <th class="bg-gray-50 px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">เวลา</th>
                <th class="bg-gray-50 px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">เหตุการณ์</th>
                <th class="bg-gray-50 px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">ผู้ใช้</th>
                <th class="bg-gray-50 px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">รายละเอียด</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            {% for e in events %}
            {% set detail = e.getDetailArray() %}
            <tr class="transition hover:bg-gray-50">
                <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ e.created_at }}</td>
                <td class="px-4 py-3">
                    {% if e.event_type == 'login' and detail['event'] is defined and detail['event'] == 'logout' %}
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-gray-100 px-2.5 py-1 text-xs font-medium text-gray-700">ออกจากระบบ</span>
                    {% elseif e.event_type == 'login' %}
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-green-50 px-2.5 py-1 text-xs font-medium text-green-700">เข้าสู่ระบบสำเร็จ</span>
                    {% elseif e.event_type == 'login_rejected' %}
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-red-50 px-2.5 py-1 text-xs font-medium text-red-700">เข้าสู่ระบบไม่สำเร็จ</span>
                    {% elseif e.event_type == 'bot_enabled' %}
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-blue-50 px-2.5 py-1 text-xs font-medium text-blue-700">เปิดบอท</span>
                    {% elseif e.event_type == 'bot_disabled' %}
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-yellow-50 px-2.5 py-1 text-xs font-medium text-yellow-700">ปิดบอท</span>
                    {% else %}
                        {{ e.event_type }}
                    {% endif %}
                </td>
                <td class="px-4 py-3 text-gray-700">{{ e.actor_label }}</td>
                <td class="px-4 py-3 text-gray-500">
                    {% if detail['reason'] is defined %}
                        {% if detail['reason'] == 'invalid_credentials' %}อีเมลหรือรหัสผ่านไม่ถูกต้อง
                        {% elseif detail['reason'] == 'rate_limited' %}ลองรหัสผ่านผิดหลายครั้งเกินไป
                        {% elseif detail['reason'] == 'hosted_domain_mismatch' %}Google account ไม่ใช่โดเมนที่อนุญาต ({{ detail['hd'] }})
                        {% else %}{{ detail['reason'] }}
                        {% endif %}
                    {% elseif detail['event'] is defined %}
                        {{ detail['event'] }}
                    {% else %}
                        <span class="text-gray-300">-</span>
                    {% endif %}
                </td>
            </tr>
            {% else %}
            <tr><td class="px-4 py-8 text-center text-gray-500" colspan="4">
                <div class="animate-fade-in flex flex-col items-center gap-2">
                    <span>ไม่มีข้อมูล</span>
                </div>
            </td></tr>
            {% endfor %}
        </tbody>
    </table>
</div>

<p class="mt-4 flex gap-3">
    {% if page > 1 %}<a class="inline-flex items-center rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 shadow-sm transition hover:bg-gray-50" href="/audit/security?page={{ page - 1 }}">&laquo; ก่อนหน้า</a>{% endif %}
    {% if events|length >= 50 %}<a class="inline-flex items-center rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 shadow-sm transition hover:bg-gray-50" href="/audit/security?page={{ page + 1 }}">ถัดไป &raquo;</a>{% endif %}
</p>

<p class="mt-6"><a class="inline-flex items-center gap-1 text-sm font-medium text-gray-600 transition hover:text-gray-900" href="/audit">
    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
        <path stroke-linecap="round" stroke-linejoin="round" d="m15 19-7-7 7-7"/>
    </svg>
    กลับไป Audit Log
</a></p>
{% endblock %}
