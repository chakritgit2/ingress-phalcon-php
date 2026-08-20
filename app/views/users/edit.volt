{% extends "layouts/main.volt" %}
{% block content %}
<h1 class="mb-6 border-b border-gray-200 pb-4 text-xl font-semibold text-gray-900 dark:border-gray-800 dark:text-white">แก้ไขผู้ใช้: {{ target.email|e }}</h1>

<div class="mx-auto max-w-xl space-y-5">
    <div class="flex items-center justify-between rounded-lg border border-gray-200 bg-gray-50 px-4 py-3 dark:border-gray-800 dark:bg-gray-800/50">
        <div>
            <p class="text-sm font-medium text-gray-900 dark:text-white">{{ target.name }}</p>
            <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                เข้าสู่ระบบล่าสุด: {% if target.last_login_at %}{{ target.last_login_at }}{% else %}<span class="text-gray-400 dark:text-gray-600">-</span>{% endif %}
            </p>
        </div>
        {% include "partials/badge" with ["status": target.is_active ? "active" : "inactive"] %}
    </div>

    <div class="space-y-3 rounded-lg border border-gray-200 p-4 dark:border-gray-800">
        <h2 class="text-sm font-semibold text-gray-900 dark:text-white">อีเมล</h2>
        <p class="text-xs text-gray-500 dark:text-gray-400">ถ้าผู้ใช้นี้ล็อกอินผ่าน Google การแก้ตรงนี้จะถูกซิงค์ทับด้วยอีเมล Google จริงอัตโนมัติในการล็อกอินครั้งถัดไป — มีผลถาวรเฉพาะบัญชีที่ล็อกอินด้วยรหัสผ่านเท่านั้น</p>
        <form method="post" action="/users/{{ target.id }}/email" class="flex items-center gap-2" onsubmit="return confirm('ยืนยันเปลี่ยนอีเมล?');">
            <input type="hidden" name="{{ security.getTokenKey() }}" value="{{ security.getToken() }}">
            <input class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm transition focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/20 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100" type="email" name="email" value="{{ target.email|e }}" required>
            <button type="submit" class="inline-flex shrink-0 cursor-pointer items-center rounded-lg border border-gray-300 bg-white px-3.5 py-2 text-sm font-medium text-gray-700 shadow-sm transition hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700">บันทึกอีเมล</button>
        </form>
    </div>

    {% if target.id == currentUser.id %}
    <p class="rounded-lg border border-gray-200 bg-gray-50 px-4 py-3 text-sm text-gray-500 dark:border-gray-800 dark:bg-gray-800/50 dark:text-gray-400">นี่คือบัญชีของคุณเอง — ไม่สามารถเปลี่ยน role หรือปิด/เปิดการใช้งานบัญชีตัวเองได้</p>
    {% else %}
    <div class="space-y-3 rounded-lg border border-gray-200 p-4 dark:border-gray-800">
        <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Role</h2>
        <form method="post" action="/users/{{ target.id }}/role" class="flex items-center gap-2" onsubmit="return confirm('ยืนยันเปลี่ยน role?');">
            <input type="hidden" name="{{ security.getTokenKey() }}" value="{{ security.getToken() }}">
            <select name="role" class="rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/20 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100">
                <option value="devops" {{ target.role == 'devops' ? 'selected' : '' }}>devops</option>
                <option value="viewer" {{ target.role == 'viewer' ? 'selected' : '' }}>viewer</option>
            </select>
            <button type="submit" class="inline-flex shrink-0 cursor-pointer items-center rounded-lg border border-gray-300 bg-white px-3.5 py-2 text-sm font-medium text-gray-700 shadow-sm transition hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700">บันทึก Role</button>
        </form>
    </div>

    <div class="space-y-3 rounded-lg border border-gray-200 p-4 dark:border-gray-800">
        <h2 class="text-sm font-semibold text-gray-900 dark:text-white">การใช้งานบัญชี</h2>
        <form method="post" action="/users/{{ target.id }}/toggle-active" onsubmit="return confirm('{{ target.is_active ? 'ยืนยันปิดการใช้งาน?' : 'ยืนยันเปิดการใช้งาน?' }}');">
            <input type="hidden" name="{{ security.getTokenKey() }}" value="{{ security.getToken() }}">
            <button type="submit" class="inline-flex cursor-pointer items-center gap-1.5 rounded-lg border px-3.5 py-2 text-sm font-medium transition {{ target.is_active ? 'border-red-200 bg-red-50 text-red-700 hover:bg-red-100 dark:border-red-500/20 dark:bg-red-500/10 dark:text-red-400 dark:hover:bg-red-500/20' : 'border-green-200 bg-green-50 text-green-700 hover:bg-green-100 dark:border-green-500/20 dark:bg-green-500/10 dark:text-green-400 dark:hover:bg-green-500/20' }}">
                {% if target.is_active %}ปิดการใช้งาน{% else %}เปิดการใช้งาน{% endif %}
            </button>
        </form>
    </div>
    {% endif %}

    <div class="space-y-3 rounded-lg border border-gray-200 p-4 dark:border-gray-800">
        <h2 class="text-sm font-semibold text-gray-900 dark:text-white">ตั้งรหัสผ่านใหม่</h2>
        <p class="text-xs text-gray-500 dark:text-gray-400">พิมพ์รหัสผ่านที่ต้องการเอง (อย่างน้อย 8 ตัวอักษร) หรือกด "สุ่มให้อัตโนมัติ" — เว้นว่างไว้ทั้งสองช่องเพื่อให้ระบบสุ่มให้เอง</p>
        <form id="resetPasswordForm" method="post" action="/users/{{ target.id }}/reset-password" class="space-y-3" data-email="{{ target.email|e }}">
            <input type="hidden" name="{{ security.getTokenKey() }}" value="{{ security.getToken() }}">
            <div>
                <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300" for="password">รหัสผ่านใหม่</label>
                <input class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm transition focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/20 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100" type="text" id="password" name="password" minlength="8" placeholder="เว้นว่างเพื่อสุ่มอัตโนมัติ">
            </div>
            <div>
                <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300" for="password_confirm">ยืนยันรหัสผ่านใหม่</label>
                <input class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm transition focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/20 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100" type="text" id="password_confirm" name="password_confirm" minlength="8" placeholder="พิมพ์ซ้ำให้ตรงกัน">
                <p id="passwordMismatchError" class="mt-1 hidden text-xs text-red-600 dark:text-red-400">รหัสผ่านทั้งสองช่องไม่ตรงกัน</p>
            </div>
            <div class="flex items-center gap-3">
                <button type="submit" class="inline-flex cursor-pointer items-center rounded-lg bg-blue-600 px-3.5 py-2 text-sm font-medium text-white shadow-sm transition hover:bg-blue-700">ตั้งรหัสผ่านใหม่</button>
                <button type="button" id="generatePasswordBtn" class="inline-flex cursor-pointer items-center rounded-lg border border-gray-300 bg-white px-3.5 py-2 text-sm font-medium text-gray-700 shadow-sm transition hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700">สุ่มให้อัตโนมัติ</button>
            </div>
        </form>
    </div>

    <p>
        <a class="inline-flex items-center gap-1 text-sm font-medium text-gray-600 transition hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-200" href="/users">
            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="m15 19-7-7 7-7"/>
            </svg>
            กลับไปหน้าผู้ใช้งาน
        </a>
    </p>
</div>

<script>
document.getElementById('generatePasswordBtn').addEventListener('click', function () {
    var bytes = new Uint8Array(9);
    crypto.getRandomValues(bytes);
    var generated = Array.prototype.map.call(bytes, function (b) { return b.toString(16).padStart(2, '0'); }).join('');
    document.getElementById('password').value = generated;
    document.getElementById('password_confirm').value = generated;
    document.getElementById('passwordMismatchError').classList.add('hidden');
});

var passwordInput = document.getElementById('password');
var passwordConfirmInput = document.getElementById('password_confirm');
var mismatchError = document.getElementById('passwordMismatchError');

function passwordsMismatch() {
    return passwordInput.value !== '' && passwordInput.value !== passwordConfirmInput.value;
}

[passwordInput, passwordConfirmInput].forEach(function (el) {
    el.addEventListener('input', function () {
        mismatchError.classList.toggle('hidden', !passwordsMismatch());
    });
});

var resetPasswordForm = document.getElementById('resetPasswordForm');
resetPasswordForm.addEventListener('submit', function (e) {
    if (passwordsMismatch()) {
        e.preventDefault();
        mismatchError.classList.remove('hidden');
        return;
    }
    if (!confirm('ยืนยันตั้งรหัสผ่านใหม่ของ ' + resetPasswordForm.dataset.email + '?')) {
        e.preventDefault();
    }
});
</script>
{% endblock %}
