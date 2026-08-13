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
        'ingress_delete_failed'
    ) NOT NULL;
