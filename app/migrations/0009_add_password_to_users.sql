ALTER TABLE users
    MODIFY google_sub VARCHAR(255) NULL,
    MODIFY hosted_domain VARCHAR(255) NULL,
    ADD COLUMN password_hash VARCHAR(255) NULL AFTER hosted_domain;
