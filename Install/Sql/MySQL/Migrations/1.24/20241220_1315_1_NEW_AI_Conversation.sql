-- UP

ALTER TABLE `exf_ai_conversation`
ADD `data` text COLLATE 'utf8mb3_general_ci' NULL,
ADD `page_uid` binary NULL AFTER `data`;

-- DOWN

ALTER TABLE `exf_ai_conversation`
DROP COLUMN `data`
DROP COLUMN `page_uid`;