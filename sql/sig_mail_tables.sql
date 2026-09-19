-- New tables only — does not alter Flarum core tables.
-- Safe to run more than once (IF NOT EXISTS).

CREATE TABLE IF NOT EXISTS `sig_mail_log` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `email` VARCHAR(255) NOT NULL,
  `kind` VARCHAR(32) NOT NULL COMMENT 'welcome | reengage',
  `tier` VARCHAR(32) NULL DEFAULT NULL COMMENT 'soft | softer | gentle',
  `status` VARCHAR(32) NOT NULL COMMENT 'dry_run | sent | skipped | error',
  `subject` VARCHAR(255) NOT NULL DEFAULT '',
  `skip_reason` VARCHAR(255) NULL DEFAULT NULL,
  `meta_json` TEXT NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_sig_mail_user_kind` (`user_id`, `kind`),
  KEY `idx_sig_mail_kind_created` (`kind`, `created_at`),
  KEY `idx_sig_mail_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sig_mail_prefs` (
  `user_id` INT UNSIGNED NOT NULL,
  `allow_reengage` TINYINT(1) NOT NULL DEFAULT 1,
  `notes` VARCHAR(255) NULL DEFAULT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
