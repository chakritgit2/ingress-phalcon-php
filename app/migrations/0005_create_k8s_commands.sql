CREATE TABLE IF NOT EXISTS k8s_commands (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    ingress_request_id INT UNSIGNED NOT NULL,
    action ENUM('create', 'delete') NOT NULL,
    status ENUM('pending', 'success', 'failed') NOT NULL DEFAULT 'pending',
    requested_by_user_id INT UNSIGNED NOT NULL,
    result JSON NULL,
    error_message TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processed_at DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_k8s_commands_status_created (status, created_at),
    KEY idx_k8s_commands_ingress_request (ingress_request_id),
    CONSTRAINT fk_k8s_commands_ingress_request FOREIGN KEY (ingress_request_id) REFERENCES ingress_requests (id),
    CONSTRAINT fk_k8s_commands_requested_by FOREIGN KEY (requested_by_user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE ingress_requests
    MODIFY COLUMN service_name VARCHAR(255) NULL,
    MODIFY COLUMN node_port INT UNSIGNED NULL,
    MODIFY COLUMN expires_at DATETIME NULL,
    MODIFY COLUMN status ENUM('pending', 'active', 'deleting', 'expired', 'deleted', 'failed') NOT NULL DEFAULT 'pending';
