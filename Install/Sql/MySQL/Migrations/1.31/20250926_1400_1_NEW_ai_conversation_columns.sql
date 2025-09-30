-- UP

ALTER TABLE exf_ai_conversation
  ADD COLUMN devmode tinyint NULL DEFAULT '0',
  ADD COLUMN connection_oid BINARY(16),
  ADD COLUMN model varchar(100);

ALTER TABLE `exf_ai_conversation`
    ADD FOREIGN KEY (`connection_oid`) REFERENCES `exf_data_connection` (`oid`) ON DELETE RESTRICT ON UPDATE RESTRICT

-- DOWN

-- Do not delete new columns!