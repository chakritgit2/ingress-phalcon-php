{% extends "layouts/main.volt" %}
{% block content %}
<div class="mx-auto flex w-full max-w-sm flex-col items-center py-4 text-center">
    <h1 class="mb-2 text-2xl font-bold text-gray-900 dark:text-white">Ingress Self-Service</h1>
    <p class="mb-6 text-sm text-gray-600 dark:text-gray-400">เข้าสู่ระบบด้วยอีเมลและรหัสผ่าน</p>

    <form method="post" action="/login" class="w-full space-y-4 text-left">
        <input type="hidden" name="{{ security.getTokenKey() }}" value="{{ security.getToken() }}">
        <div>
            <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300" for="email">อีเมล</label>
            <input id="email" name="email" type="email" required autofocus
                   class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-900 shadow-sm transition focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/20 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100">
        </div>

        <div>
            <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300" for="password">รหัสผ่าน</label>
            <input id="password" name="password" type="password" required
                   class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-900 shadow-sm transition focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/20 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100">
        </div>

        <button type="submit"
                class="w-full inline-flex items-center justify-center rounded-lg bg-blue-600 px-3.5 py-2.5 text-sm font-medium text-white shadow-sm transition hover:bg-blue-700">
            เข้าสู่ระบบ
        </button>
    </form>

    {# Google SSO button hidden from the UI per request; the backend routes
       (/login/google, /login/google/callback) are left intact and still
       reachable directly. #}

    {% if isLocalEnv %}
    <a href="/login/mock" class="mt-3 inline-flex w-full items-center justify-center gap-2 rounded-lg border border-dashed border-yellow-400 bg-yellow-50 px-3.5 py-2.5 text-sm font-medium text-yellow-800 shadow-sm transition hover:bg-yellow-100 dark:border-yellow-500/40 dark:bg-yellow-500/10 dark:text-yellow-400">
        Mock Login (Dev only)
    </a>
    {% endif %}
</div>
{% endblock %}
