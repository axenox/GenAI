-- UP

ALTER TABLE `exf_ai_conversation`
    ADD COLUMN `rating` TINYINT NULL DEFAULT NULL AFTER `page_uid`,
	ADD COLUMN `rating_feedback` TEXT NULL AFTER `rating`;

-- DOWN

-- Do not delete new columns!