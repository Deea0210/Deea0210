-- Deea Booking — database schema (MySQL 5.7+ / MariaDB 10.3+)
-- install.php runs this automatically. To import by hand in phpMyAdmin:
-- create a database (utf8mb4_unicode_ci), select it, then import this file and seed.sql.

CREATE TABLE IF NOT EXISTS services (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug             VARCHAR(80)  NOT NULL,
    name             VARCHAR(120) NOT NULL,
    tagline          VARCHAR(160) NOT NULL DEFAULT '',
    description      TEXT         NOT NULL,
    icon             VARCHAR(40)  NOT NULL DEFAULT 'spark',
    price_from       DECIMAL(10,2) NULL,
    duration_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 30,
    meeting_types    VARCHAR(60)  NOT NULL DEFAULT 'online,phone,in_person',
    is_active        TINYINT(1)   NOT NULL DEFAULT 1,
    sort_order       INT          NOT NULL DEFAULT 0,
    created_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_services_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bookings (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    reference     CHAR(8)      NOT NULL,
    token_hash    CHAR(64)     NOT NULL,
    service_id    INT UNSIGNED NOT NULL,
    start_at      DATETIME     NOT NULL,
    end_at        DATETIME     NOT NULL,
    meeting_type  VARCHAR(20)  NOT NULL,
    client_name   VARCHAR(120) NOT NULL,
    client_email  VARCHAR(190) NOT NULL,
    client_phone  VARCHAR(40)  NOT NULL DEFAULT '',
    business_name VARCHAR(160) NOT NULL DEFAULT '',
    website       VARCHAR(255) NOT NULL DEFAULT '',
    budget        VARCHAR(40)  NOT NULL DEFAULT '',
    message       TEXT         NOT NULL,
    status        ENUM('pending','confirmed','completed','cancelled') NOT NULL DEFAULT 'pending',
    admin_notes   TEXT         NULL,
    consent_at    DATETIME     NOT NULL,
    created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_bookings_reference (reference),
    KEY idx_bookings_start (start_at),
    KEY idx_bookings_status (status),
    KEY idx_bookings_email (client_email),
    CONSTRAINT fk_bookings_service FOREIGN KEY (service_id) REFERENCES services (id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS working_hours (
    day_of_week TINYINT UNSIGNED NOT NULL, -- 1 = Monday … 7 = Sunday
    is_open     TINYINT(1) NOT NULL DEFAULT 0,
    open_time   TIME       NOT NULL DEFAULT '09:00:00',
    close_time  TIME       NOT NULL DEFAULT '17:00:00',
    PRIMARY KEY (day_of_week)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS blocked_dates (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    blocked_on DATE         NOT NULL,
    reason     VARCHAR(160) NOT NULL DEFAULT '',
    PRIMARY KEY (id),
    UNIQUE KEY uq_blocked_on (blocked_on)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admins (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    username      VARCHAR(60)  NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    last_login_at DATETIME     NULL,
    created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_admins_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    ip           VARCHAR(45)  NOT NULL,
    attempted_at DATETIME     NOT NULL,
    PRIMARY KEY (id),
    KEY idx_login_attempts (ip, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
