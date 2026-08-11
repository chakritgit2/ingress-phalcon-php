ALTER TABLE k8s_commands
    ADD COLUMN request_payload JSON NULL AFTER action;
