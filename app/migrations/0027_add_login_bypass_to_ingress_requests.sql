ALTER TABLE ingress_requests
    ADD COLUMN login_bypass TINYINT(1) NOT NULL DEFAULT 0 AFTER note;
