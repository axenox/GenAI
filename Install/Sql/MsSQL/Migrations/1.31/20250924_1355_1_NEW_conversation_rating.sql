-- UP

ALTER TABLE dbo.exf_ai_conversation
    ADD rating TINYINT NULL;
ALTER TABLE dbo.exf_ai_conversation
	ADD rating_feedback nvarchar(max) NULL;

-- DOWN

-- Do not delete new columns!