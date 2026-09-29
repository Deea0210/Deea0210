-- Ticket Scanner: tables the receiver writes to (MySQL 5.7+ / MariaDB 10.3+).
-- Reception reads new tickets with:  SELECT * FROM scanned_tickets WHERE status = 'new' ORDER BY scanned_at;

CREATE TABLE IF NOT EXISTS scanned_tickets (
    id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
    scan_id            CHAR(36)     NOT NULL,                 -- unique per scan, set by the phone (duplicate sends are ignored)
    template           VARCHAR(60)  NOT NULL,                 -- which ticket design, e.g. barber-a6-v1
    scanned_at         DATETIME     NOT NULL,                 -- when it was scanned, in the salon's local time
    scanned_at_utc     DATETIME     NOT NULL,
    device             VARCHAR(100) NOT NULL DEFAULT '',
    field_c            VARCHAR(100) NOT NULL DEFAULT '',      -- the handwritten "C" box, as typed by staff
    treatments         VARCHAR(20)  NOT NULL DEFAULT '',      -- "How many treatments"
    tips               DECIMAL(8,2) NULL,                     -- "Tips" in GBP (NULL if left empty)
    services_count     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    services_total     DECIMAL(10,2) NOT NULL DEFAULT 0,
    corrected_by_staff TINYINT(1)   NOT NULL DEFAULT 0,       -- staff changed a tick before sending
    photo_path         VARCHAR(255) NULL,                     -- straightened ticket photo on disk
    status             ENUM('new','processed','rejected') NOT NULL DEFAULT 'new',
    received_at        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    raw_payload        LONGTEXT     NOT NULL,                 -- everything the phone sent, minus the images
    PRIMARY KEY (id),
    UNIQUE KEY uq_scanned_tickets_scan (scan_id),
    KEY idx_scanned_tickets_status (status, scanned_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS scanned_ticket_services (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    ticket_id         INT UNSIGNED NOT NULL,
    code              VARCHAR(40)  NOT NULL,                  -- e.g. skin-fade (stable, use this to map to your services)
    name              VARCHAR(120) NOT NULL,
    section           VARCHAR(60)  NOT NULL,
    price             DECIMAL(10,2) NULL,                     -- NULL for discount boxes (Senior, NHS/Student, 6th Free Cut)
    confidence        DECIMAL(4,3) NOT NULL,
    changed_by_staff  TINYINT(1)   NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_scanned_ticket_services_ticket (ticket_id),
    CONSTRAINT fk_scanned_ticket_services_ticket FOREIGN KEY (ticket_id) REFERENCES scanned_tickets (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
