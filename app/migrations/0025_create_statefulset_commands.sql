CREATE TABLE IF NOT EXISTS statefulset_commands (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    statefulset_request_id INT UNSIGNED NOT NULL,
    action ENUM('create', 'delete') NOT NULL,
    request_payload JSON NULL,
    payload_source ENUM('preview', 'sent') NULL,
    status ENUM('pending', 'success', 'failed') NOT NULL DEFAULT 'pending',
    requested_by_user_id INT UNSIGNED NOT NULL,
    result JSON NULL,
    error_message TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processed_at DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_statefulset_commands_status_created (status, created_at),
    KEY idx_statefulset_commands_request (statefulset_request_id),
    CONSTRAINT fk_statefulset_commands_request FOREIGN KEY (statefulset_request_id) REFERENCES statefulset_requests (id),
    CONSTRAINT fk_statefulset_commands_requested_by FOREIGN KEY (requested_by_user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
