-- UP

ALTER TABLE dbo.exf_ai_conversation
ADD data text COLLATE 'utf8mb3_general_ci' NULL,
    page_uid binary NULL;
-- DOWN

ALTER TABLE dbo.exf_ai_conversation
DROP COLUMN data,
            page_uid;