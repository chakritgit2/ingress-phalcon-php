ALTER TABLE ingress_requests
    ADD COLUMN request_type ENUM('nodeport', 'ingress') NOT NULL DEFAULT 'nodeport' AFTER deployment_name,
    ADD COLUMN host VARCHAR(255) NULL AFTER node_ip,
    ADD COLUMN secret_name VARCHAR(255) NULL AFTER host,
    ADD COLUMN ingress_name VARCHAR(255) NULL AFTER service_name;
