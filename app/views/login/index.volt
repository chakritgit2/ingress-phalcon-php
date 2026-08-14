{% extends "layouts/main.volt" %}
{% block content %}
<div class="flex flex-col items-center py-8 text-center">
    <h1 class="mb-2 text-2xl font-bold text-gray-900">Ingress Self-Service</h1>
    <p class="mb-6 text-sm text-gray-600">เข้าสู่ระบบด้วยอีเมลและรหัสผ่าน</p>

    <form method="post" action="/login" class="w-full max-w-xs text-left">
        <input type="hidden" name="{{ security.getTokenKey() }}" value="{{ security.getToken() }}">
        <label class="mb-1 block text-sm font-medium text-gray-700" for="email">อีเมล</label>
        <input id="email" name="email" type="email" required autofocus
               class="mb-4 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-900 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500">

        <label class="mb-1 block text-sm font-medium text-gray-700" for="password">รหัสผ่าน</label>
        <input id="password" name="password" type="password" required
               class="mb-4 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-900 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500">

        <button type="submit"
                class="w-full rounded-lg bg-blue-600 px-5 py-2.5 text-sm font-medium text-white shadow-sm transition hover:bg-blue-700">
            เข้าสู่ระบบ
        </button>
    </form>

    {# Google SSO button hidden from the UI per request; the backend routes
       (/login/google, /login/google/callback) are left intact and still
       reachable directly. #}

    {% if isLocalEnv %}
    <a href="/login/mock" class="mt-3 inline-flex items-center justify-center gap-2 rounded-lg border border-dashed border-yellow-400 bg-yellow-50 px-5 py-2.5 text-sm font-medium text-yellow-800 shadow-sm transition hover:bg-yellow-100">
        Mock Login (Dev only)
    </a>
    {% endif %}
</div>
{% endblock %}
