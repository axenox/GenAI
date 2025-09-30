-- UP

ALTER TABLE exf_ai_conversation
  ADD COLUMN devmode tinyint,
  ADD COLUMN connection_oid BINARY(16),
  ADD COLUMN model TEXT;

ALTER TABLE `exf_ai_conversation`
ADD FOREIGN KEY (`connection_oid`) REFERENCES `exf_data_connection` (`oid`) ON DELETE RESTRICT ON UPDATE RESTRICT

ALTER TABLE `exf_ai_conversation`
CHANGE `devmode` `devmode` tinyint NULL DEFAULT '0' AFTER `rating_feedback`;

-- DOWN

-- Do not delete new columns!