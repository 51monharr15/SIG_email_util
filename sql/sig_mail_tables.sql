-- SIG_email_util: one state table only.
-- Drops unused test-era tables, then creates sig_mail_state.

DROP TABLE IF EXISTS `sig_mail_log`;
DROP TABLE IF EXISTS `sig_mail_prefs`;

CREATE TABLE IF NOT EXISTS `sig_mail_state` (
  `user_id` INT UNSIGNED NOT NULL,
  `welcome_sent_at` DATETIME NULL DEFAULT NULL,
  `last_kind` VARCHAR(32) NULL DEFAULT NULL COMMENT 'digest | away | long | dormant',
  `last_sent_at` DATETIME NULL DEFAULT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
