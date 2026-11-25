CREATE DATABASE IF NOT EXISTS task5_jokes
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE task5_jokes;

CREATE TABLE IF NOT EXISTS jokes (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    type ENUM('single', 'twopart') NOT NULL,
    joke TEXT NULL,
    setup TEXT NULL,
    delivery TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT chk_jokes_content CHECK (
        (type = 'single' AND joke IS NOT NULL AND setup IS NULL AND delivery IS NULL)
        OR
        (type = 'twopart' AND joke IS NULL AND setup IS NOT NULL AND delivery IS NOT NULL)
    )
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS logs (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    url VARCHAR(2048) NOT NULL,
    date_time DATETIME NOT NULL
) ENGINE=InnoDB;
