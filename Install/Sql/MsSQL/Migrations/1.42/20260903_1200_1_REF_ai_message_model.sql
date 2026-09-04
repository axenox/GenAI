/*
 * Move the model column from AI conversations to messages
 */
-- UP

ALTER TABLE dbo.exf_ai_message
    ADD model NVARCHAR(100) NULL;

GO

UPDATE m
SET model = c.model
FROM dbo.exf_ai_message AS m
INNER JOIN dbo.exf_ai_conversation AS c
    ON c.oid = m.ai_conversation_oid;

ALTER TABLE dbo.exf_ai_conversation
    DROP COLUMN model;

-- DOWN

ALTER TABLE dbo.exf_ai_conversation
    ADD model NVARCHAR(100) NULL;

GO

UPDATE c
SET model = m.model
FROM dbo.exf_ai_conversation AS c
LEFT JOIN (
    SELECT ai_conversation_oid, MAX(model) AS model
    FROM dbo.exf_ai_message
    GROUP BY ai_conversation_oid
) AS m ON m.ai_conversation_oid = c.oid;

ALTER TABLE dbo.exf_ai_message
    DROP COLUMN model;