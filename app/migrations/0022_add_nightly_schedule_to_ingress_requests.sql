ALTER TABLE ingress_requests
    MODIFY COLUMN status ENUM('pending', 'active', 'deleting', 'expired', 'deleted', 'failed', 'closed') NOT NULL DEFAULT 'pending',
    ADD COLUMN schedule_closed_at DATETIME NULL AFTER expires_at,
    ADD COLUMN schedule_hold_open_until DATETIME NULL AFTER schedule_closed_at,
    ADD COLUMN schedule_reopen_requested_at DATETIME NULL AFTER schedule_hold_open_until,
    ADD COLUMN schedule_reopen_requested_by_user_id INT UNSIGNED NULL AFTER schedule_reopen_requested_at,
    ADD CONSTRAINT fk_ingress_requests_reopen_requested_by FOREIGN KEY (schedule_reopen_requested_by_user_id) REFERENCES users (id);
