CREATE TABLE IF NOT EXISTS audit_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    ingress_request_id INT UNSIGNED NULL,
    event_type ENUM(
        'login',
        'login_rejected',
        'ingress_create',
        'ingress_create_failed',
        'ingress_delete',
        'ingress_delete_failed'
    ) NOT NULL,
    actor_user_id INT UNSIGNED NULL,
    actor_label VARCHAR(255) NOT NULL,
    namespace VARCHAR(255) NULL,
    deployment_name VARCHAR(255) NULL,
    node_port INT UNSIGNED NULL,
    node_ip VARCHAR(45) NULL,
    detail JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_audit_log_ingress_request (ingress_request_id),
    KEY idx_audit_log_actor_user (actor_user_id),
    KEY idx_audit_log_created_at (created_at),
    CONSTRAINT fk_audit_log_ingress_request FOREIGN KEY (ingress_request_id) REFERENCES ingress_requests (id),
    CONSTRAINT fk_audit_log_actor_user FOREIGN KEY (actor_user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
