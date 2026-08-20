{% extends "layouts/main.volt" %}
{% block container_class %}max-w-7xl{% endblock %}
{% block content %}
<h1 class="mb-6 border-b border-gray-200 pb-4 text-xl font-semibold text-gray-900 dark:border-gray-800 dark:text-white">ผู้ใช้งาน</h1>

<div class="overflow-x-auto">
    <table class="w-full min-w-[900px] text-sm">
        <thead>
            <tr>
                <th class="bg-gray-50 px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:bg-gray-900 dark:text-gray-400">Email</th>
                <th class="bg-gray-50 px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:bg-gray-900 dark:text-gray-400">ชื่อ</th>
                <th class="bg-gray-50 px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:bg-gray-900 dark:text-gray-400">Role</th>
                <th class="bg-gray-50 px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:bg-gray-900 dark:text-gray-400">สถานะ</th>
                <th class="bg-gray-50 px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:bg-gray-900 dark:text-gray-400">เข้าสู่ระบบล่าสุด</th>
                <th class="bg-gray-50 px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:bg-gray-900 dark:text-gray-400">สร้างเมื่อ</th>
                <th class="bg-gray-50 px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:bg-gray-900 dark:text-gray-400"></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100 bg-white dark:divide-gray-800 dark:bg-gray-900">
            {% for row in rows %}
            <tr class="transition duration-150 hover:bg-gray-50 dark:hover:bg-gray-800/60">
                <td class="px-4 py-3 text-gray-700 dark:text-gray-300">{{ row.email }}</td>
                <td class="px-4 py-3 text-gray-700 dark:text-gray-300">{{ row.name }}</td>
                <td class="px-4 py-3 text-gray-700 dark:text-gray-300">{{ row.role }}</td>
                <td class="px-4 py-3">{% include "partials/badge" with ["status": row.is_active ? "active" : "inactive"] %}</td>
                <td class="px-4 py-3 text-gray-700 dark:text-gray-300">
                    {% if row.last_login_at %}{{ row.last_login_at }}{% else %}<span class="text-gray-400 dark:text-gray-600">-</span>{% endif %}
                </td>
                <td class="px-4 py-3 text-gray-700 dark:text-gray-300">{{ row.created_at }}</td>
                <td class="px-4 py-3">
                    <div class="flex items-center gap-2">
                        {% if row.id == currentUser.id %}
                        <span class="text-xs text-gray-400 dark:text-gray-500">คุณ</span>
                        {% endif %}
                        <a href="/users/{{ row.id }}/edit" class="inline-flex items-center gap-1.5 rounded-lg border border-gray-300 bg-white px-2.5 py-1.5 text-xs font-medium text-gray-700 shadow-sm transition hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700">
                            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m16.5 4.5 3 3L7 20H4v-3L16.5 4.5Z"/>
                            </svg>
                            แก้ไข
                        </a>
                    </div>
                </td>
            </tr>
            {% else %}
            <tr><td class="px-4 py-8 text-center text-gray-500 dark:text-gray-400" colspan="7">
                <div class="animate-fade-in flex flex-col items-center gap-2">
                    <span>ยังไม่มีผู้ใช้งาน</span>
                </div>
            </td></tr>
            {% endfor %}
        </tbody>
    </table>
</div>
{% endblock %}
