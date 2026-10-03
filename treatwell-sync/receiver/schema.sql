-- Treatwell bookings, as sent by the Salon Bookings Sync extension (MySQL 5.7+ / MariaDB 10.3+).
-- Today's list:  SELECT * FROM treatwell_bookings WHERE booking_date = CURDATE() AND removed = 0 ORDER BY start_time;

CREATE TABLE IF NOT EXISTS treatwell_bookings (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    treatwell_id    VARCHAR(80)  NOT NULL,                 -- the booking's id in Treatwell (stable, use it to match)
    booking_date    DATE         NOT NULL,
    start_time      TIME         NOT NULL,
    end_time        TIME         NULL,
    staff_name      VARCHAR(120) NOT NULL DEFAULT '',      -- stylist, as named in Treatwell
    service         VARCHAR(255) NOT NULL DEFAULT '',      -- e.g. "Wash + Skin Fade"
    customer_name   VARCHAR(160) NOT NULL DEFAULT '',
    customer_phone  VARCHAR(40)  NOT NULL DEFAULT '',
    customer_email  VARCHAR(160) NOT NULL DEFAULT '',
    price           DECIMAL(10,2) NULL,
    status          VARCHAR(40)  NOT NULL DEFAULT '',      -- as Treatwell shows it, e.g. CONFIRMED
    cancelled       TINYINT(1)   NOT NULL DEFAULT 0,
    no_show         TINYINT(1)   NOT NULL DEFAULT 0,
    removed         TINYINT(1)   NOT NULL DEFAULT 0,       -- no longer in Treatwell's calendar for that day
    notes           TEXT         NULL,
    channel         VARCHAR(60)  NOT NULL DEFAULT '',      -- where it was booked, if Treatwell says
    first_seen_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_treatwell_bookings_tw (treatwell_id),
    KEY idx_treatwell_bookings_day (booking_date, start_time)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
