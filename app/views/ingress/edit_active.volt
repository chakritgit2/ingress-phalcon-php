{% extends "layouts/main.volt" %}
{% block content %}
<h1 class="mb-6 border-b border-gray-200 pb-4 text-xl font-semibold text-gray-900 dark:border-gray-800 dark:text-white">แก้ไข Ingress (#{{ row.id }})</h1>

<div class="mb-6 max-w-md space-y-3 rounded-lg border border-gray-200 bg-gray-50 p-4 text-sm dark:border-gray-800 dark:bg-gray-900">
    <p class="flex items-start gap-1.5 text-xs text-gray-500 dark:text-gray-400">
        <svg class="mt-0.5 h-3.5 w-3.5 shrink-0 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <circle cx="12" cy="12" r="9"/>
            <path stroke-linecap="round" d="M12 11v5m0-8h.01"/>
        </svg>
        <span>รายการนี้กำลังทำงานอยู่ (active) มี Service/Ingress จริงบน Kubernetes แล้ว จึงแก้ไขได้เฉพาะข้อมูลที่ไม่กระทบ Service/Ingress — เปลี่ยน Namespace, Deployment, Port, Host หรือประเภทไม่ได้ (ต้องลบแล้วสร้างใหม่แทน)</span>
    </p>
    <dl class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-xs text-gray-600 dark:text-gray-400">
        <dt class="font-medium">ประเภท</dt>
        <dd>{% if row.request_type == 'ingress' %}Ingress + TLS{% else %}NodePort{% endif %}</dd>
        <dt class="font-medium">Namespace</dt>
        <dd>{{ row.namespace }}</dd>
        <dt class="font-medium">Deployment</dt>
        <dd>{{ row.deployment_name }}</dd>
        <dt class="font-medium">Port</dt>
        <dd>{{ row.target_port }}</dd>
        <dt class="font-medium">Address</dt>
        <dd>{{ row.address() }}</dd>
    </dl>
</div>

<form method="post" action="/ingress/{{ row.id }}/update" class="max-w-md space-y-6">
    <input type="hidden" name="{{ security.getTokenKey() }}" value="{{ security.getToken() }}">

    <div>
        <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300" for="developer_name">ใคร (Developer Name) *</label>
        <input class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm transition focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/20 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100" type="text" id="developer_name" name="developer_name" value="{{ row.developer_name }}" required>
    </div>

    <div>
        <label class="flex items-center gap-2 text-sm font-medium text-gray-700 dark:text-gray-300">
            <input type="checkbox" name="login_bypass" value="1" {{ row.login_bypass ? 'checked' : '' }} class="rounded border-gray-300 text-blue-600 focus:ring-blue-500 dark:border-gray-700 dark:bg-gray-800">
            Login Bypass
        </label>
        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">ปิดการตรวจสอบ LINE Login ชั่วคราว (ตั้งค่า NO_LINELOGIN=yes บน Deployment) — บันทึกแล้วจะแก้ไข Deployment จริงทันที และจะถูกคืนค่ากลับเป็น false อัตโนมัติเมื่อ Ingress นี้ถูกลบหรือหมดอายุ</p>
    </div>

    <div>
        <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300" for="note">หมายเหตุ (ถ้ามี)</label>
        <textarea class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm transition focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/20 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100" id="note" name="note" rows="2" maxlength="255" placeholder="เหตุผลที่ขอ เช่น ทดสอบ feature X ให้ทีม QA">{{ row.note|e }}</textarea>
    </div>

    <div class="pt-2">
        <button type="submit" class="inline-flex items-center justify-center rounded-lg bg-blue-600 px-3.5 py-2 text-sm font-medium text-white shadow-sm transition hover:bg-blue-700 cursor-pointer">บันทึกการแก้ไข</button>
    </div>
</form>
{% endblock %}
