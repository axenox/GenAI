-- UP

ALTER TABLE dbo.exf_ai_message
ADD finish_reason varchar(20) NULL;

-- DOWN

ALTER TABLE dbo.exf_ai_message
DROP COLUMN finish_reason;