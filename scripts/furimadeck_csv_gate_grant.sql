-- FurimaDeck CSV MariaDB integration-test database.
-- Run this as a MariaDB administrator on the local test server only.
-- Do not run against the production database.
CREATE DATABASE IF NOT EXISTS `furimadeck_csv_gate`
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

GRANT ALL PRIVILEGES ON `furimadeck_csv_gate`.*
  TO 'shinji'@'127.0.0.1';

FLUSH PRIVILEGES;