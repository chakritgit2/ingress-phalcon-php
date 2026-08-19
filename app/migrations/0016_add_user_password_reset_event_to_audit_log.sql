ALTER TABLE audit_log
    MODIFY COLUMN event_type ENUM(
        'login',
        'login_rejected',
        'ingress_requested',
        'ingress_delete_requested',
        'ingress_retry_requested',
        'ingress_create',
        'ingress_create_failed',
        'ingress_delete',
        'ingress_delete_failed',
        'ingress_updated',
        'bot_enabled',
        'bot_disabled',
        'preview_payload_failed',
        'user_role_changed',
        'user_activated',
        'user_deactivated',
        'user_password_reset'
    ) NOT NULL;
