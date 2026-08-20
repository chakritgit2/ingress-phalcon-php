{% if detail['reason'] is defined %}
    {% if detail['reason'] == 'invalid_credentials' %}อีเมลหรือรหัสผ่านไม่ถูกต้อง
    {% elseif detail['reason'] == 'rate_limited' %}ลองรหัสผ่านผิดหลายครั้งเกินไป
    {% elseif detail['reason'] == 'hosted_domain_mismatch' %}Google account ไม่ใช่โดเมนที่อนุญาต ({{ detail['hd'] }})
    {% else %}{{ detail['reason'] }}
    {% endif %}
{% elseif detail['event'] is defined %}
    {{ detail['event'] }}
{% elseif event_type == 'user_role_changed' %}
    {{ detail['email']|e }} ({{ detail['old_role']|e }} &rarr; {{ detail['new_role']|e }})
{% elseif event_type == 'user_activated' or event_type == 'user_deactivated' or event_type == 'user_password_reset' %}
    {{ detail['email']|e }}
{% elseif event_type == 'user_email_changed' %}
    {{ detail['old_email']|e }} &rarr; {{ detail['new_email']|e }}
{% elseif event_type == 'preview_payload_failed' %}
    {{ detail['error']|e }}
{% elseif detail['request_type'] is defined %}
    {{ detail['request_type']|e }}{% if detail['target_port'] is defined %} &middot; port {{ detail['target_port'] }}{% endif %}{% if detail['host'] is defined and detail['host'] %} &middot; host: {{ detail['host']|e }}{% endif %}{% if detail['note'] is defined and detail['note'] %} &middot; หมายเหตุ: {{ detail['note']|e }}{% endif %}
{% else %}
    <span class="text-gray-300 dark:text-gray-600">-</span>
{% endif %}
