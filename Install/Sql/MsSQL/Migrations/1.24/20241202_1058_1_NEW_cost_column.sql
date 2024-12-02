-- UP

ALTER TABLE dbo.exf_ai_message ADD cost decimal(18,6) NULL;

-- DOWN

ALTER TABLE dbo.exf_ai_message DROP COLUMN cost;