{% if event_type == 'login' and detail is defined and detail['event'] is defined and detail['event'] == 'logout' %}
<span class="inline-flex items-center gap-1.5 rounded-full bg-gray-100 px-2.5 py-1 text-xs font-medium text-gray-600 ring-1 ring-inset ring-gray-500/10">ออกจากระบบ</span>
{% elseif event_type == 'login' %}
<span class="inline-flex items-center gap-1.5 rounded-full bg-green-50 px-2.5 py-1 text-xs font-medium text-green-700 ring-1 ring-inset ring-green-600/20">เข้าสู่ระบบสำเร็จ</span>
{% elseif event_type == 'login_rejected' %}
<span class="inline-flex items-center gap-1.5 rounded-full bg-red-50 px-2.5 py-1 text-xs font-medium text-red-700 ring-1 ring-inset ring-red-600/20">เข้าสู่ระบบไม่สำเร็จ</span>
{% elseif event_type == 'bot_enabled' %}
<span class="inline-flex items-center gap-1.5 rounded-full bg-blue-50 px-2.5 py-1 text-xs font-medium text-blue-700 ring-1 ring-inset ring-blue-600/20">เปิดบอท</span>
{% elseif event_type == 'bot_disabled' %}
<span class="inline-flex items-center gap-1.5 rounded-full bg-yellow-50 px-2.5 py-1 text-xs font-medium text-yellow-700 ring-1 ring-inset ring-yellow-600/20">ปิดบอท</span>
{% elseif event_type == 'user_role_changed' %}
<span class="inline-flex items-center gap-1.5 rounded-full bg-purple-50 px-2.5 py-1 text-xs font-medium text-purple-700 ring-1 ring-inset ring-purple-600/20">เปลี่ยน Role</span>
{% elseif event_type == 'user_activated' %}
<span class="inline-flex items-center gap-1.5 rounded-full bg-green-50 px-2.5 py-1 text-xs font-medium text-green-700 ring-1 ring-inset ring-green-600/20">เปิดใช้งานบัญชี</span>
{% elseif event_type == 'user_deactivated' %}
<span class="inline-flex items-center gap-1.5 rounded-full bg-red-50 px-2.5 py-1 text-xs font-medium text-red-700 ring-1 ring-inset ring-red-600/20">ปิดใช้งานบัญชี</span>
{% elseif event_type == 'user_password_reset' %}
<span class="inline-flex items-center gap-1.5 rounded-full bg-blue-50 px-2.5 py-1 text-xs font-medium text-blue-700 ring-1 ring-inset ring-blue-600/20">รีเซ็ตรหัสผ่าน</span>
{% elseif event_type == 'user_email_changed' %}
<span class="inline-flex items-center gap-1.5 rounded-full bg-blue-50 px-2.5 py-1 text-xs font-medium text-blue-700 ring-1 ring-inset ring-blue-600/20">เปลี่ยนอีเมล</span>
{% elseif event_type == 'ingress_requested' %}
<span class="inline-flex items-center gap-1.5 rounded-full bg-blue-50 px-2.5 py-1 text-xs font-medium text-blue-700 ring-1 ring-inset ring-blue-600/20">ขอสร้าง</span>
{% elseif event_type == 'ingress_delete_requested' %}
<span class="inline-flex items-center gap-1.5 rounded-full bg-blue-50 px-2.5 py-1 text-xs font-medium text-blue-700 ring-1 ring-inset ring-blue-600/20">ขอลบ</span>
{% elseif event_type == 'ingress_retry_requested' %}
<span class="inline-flex items-center gap-1.5 rounded-full bg-blue-50 px-2.5 py-1 text-xs font-medium text-blue-700 ring-1 ring-inset ring-blue-600/20">ขอลองใหม่</span>
{% elseif event_type == 'ingress_updated' %}
<span class="inline-flex items-center gap-1.5 rounded-full bg-purple-50 px-2.5 py-1 text-xs font-medium text-purple-700 ring-1 ring-inset ring-purple-600/20">แก้ไขข้อมูล</span>
{% elseif event_type == 'ingress_create' %}
<span class="inline-flex items-center gap-1.5 rounded-full bg-green-50 px-2.5 py-1 text-xs font-medium text-green-700 ring-1 ring-inset ring-green-600/20">สร้างสำเร็จ</span>
{% elseif event_type == 'ingress_create_failed' %}
<span class="inline-flex items-center gap-1.5 rounded-full bg-red-50 px-2.5 py-1 text-xs font-medium text-red-700 ring-1 ring-inset ring-red-600/20">สร้างไม่สำเร็จ</span>
{% elseif event_type == 'ingress_delete' %}
<span class="inline-flex items-center gap-1.5 rounded-full bg-green-50 px-2.5 py-1 text-xs font-medium text-green-700 ring-1 ring-inset ring-green-600/20">ลบสำเร็จ</span>
{% elseif event_type == 'ingress_delete_failed' %}
<span class="inline-flex items-center gap-1.5 rounded-full bg-red-50 px-2.5 py-1 text-xs font-medium text-red-700 ring-1 ring-inset ring-red-600/20">ลบไม่สำเร็จ</span>
{% elseif event_type == 'preview_payload_failed' %}
<span class="inline-flex items-center gap-1.5 rounded-full bg-gray-100 px-2.5 py-1 text-xs font-medium text-gray-600 ring-1 ring-inset ring-gray-500/10">Preview ล้มเหลว</span>
{% else %}
{{ event_type }}
{% endif %}
