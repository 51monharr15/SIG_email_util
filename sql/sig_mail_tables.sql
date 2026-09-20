-- Utility tables for SIG_email_util (does not alter Flarum core tables).
-- Safe to run more than once (IF NOT EXISTS).

-- Current contact state (source of truth for welcome + last reminder).
CREATE TABLE IF NOT EXISTS `sig_mail_state` (
  `user_id` INT UNSIGNED NOT NULL,
  `welcome_sent_at` DATETIME NULL DEFAULT NULL,
  `last_kind` VARCHAR(32) NULL DEFAULT NULL COMMENT 'digest | away | long | dormant',
  `last_sent_at` DATETIME NULL DEFAULT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Legacy append log (optional history). New code does not write dry-runs here.
CREATE TABLE IF NOT EXISTS `sig_mail_log` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `email` VARCHAR(255) NOT NULL,
  `kind` VARCHAR(32) NOT NULL COMMENT 'welcome | digest | away | long | dormant',
  `tier` VARCHAR(32) NULL DEFAULT NULL,
  `status` VARCHAR(32) NOT NULL COMMENT 'sent | error',
  `subject` VARCHAR(255) NOT NULL DEFAULT '',
  `skip_reason` VARCHAR(255) NULL DEFAULT NULL,
  `meta_json` TEXT NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_sig_mail_user_kind` (`user_id`, `kind`),
  KEY `idx_sig_mail_kind_created` (`kind`, `created_at`),
  KEY `idx_sig_mail_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Optional manual kill-switch (unused by default in the agreed model).
CREATE TABLE IF NOT EXISTS `sig_mail_prefs` (
  `user_id` INT UNSIGNED NOT NULL,
  `allow_reengage` TINYINT(1) NOT NULL DEFAULT 1,
  `notes` VARCHAR(255) NULL DEFAULT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
