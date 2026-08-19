ALTER TABLE ingress_requests
    ADD COLUMN note VARCHAR(255) NULL AFTER schedule_end_minutes;
