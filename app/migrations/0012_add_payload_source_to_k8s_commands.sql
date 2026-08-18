ALTER TABLE k8s_commands
    ADD COLUMN payload_source ENUM('preview', 'sent') NULL AFTER request_payload;
