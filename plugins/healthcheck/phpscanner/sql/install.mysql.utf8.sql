CREATE TABLE IF NOT EXISTS `#__phpscanner_baseline` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `path` VARCHAR(1024) NOT NULL,
    `hash` CHAR(64) NOT NULL,
    `created` DATETIME NULL,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
