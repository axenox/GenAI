-- UP

ALTER TABLE dbo.exf_ai_conversation
ADD [data] text NULL,
    [page_uid] binary(16) NULL;
-- DOWN

ALTER TABLE dbo.exf_ai_conversation
DROP COLUMN [data],
            [page_uid];