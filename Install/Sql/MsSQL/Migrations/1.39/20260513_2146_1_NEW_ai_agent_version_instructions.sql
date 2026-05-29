/*
 * Add dedicated instructions column to AI agent versions
 *
 * Moves the instructions text from config_uxon JSON property
 * $.instructions into a dedicated column.
 *
 * @author OpenAI
 */
-- UP
ALTER TABLE [exf_ai_agent_version]
    ADD [instructions] NVARCHAR(MAX) NOT NULL
        CONSTRAINT [DF_exf_ai_agent_version_instructions] DEFAULT N'';

GO;

UPDATE [exf_ai_agent_version]
SET [instructions] = COALESCE(
    JSON_VALUE([config_uxon], '$.instructions'),
    N''
);

UPDATE [exf_ai_agent_version]
SET [config_uxon] = JSON_MODIFY([config_uxon], '$.instructions', NULL)
WHERE [config_uxon] IS NOT NULL
  AND JSON_VALUE([config_uxon], '$.instructions') IS NOT NULL;

GO;

ALTER TABLE [exf_ai_agent_version]
    DROP CONSTRAINT [DF_exf_ai_agent_version_instructions];

-- DOWN
UPDATE [exf_ai_agent_version]
SET [config_uxon] = JSON_MODIFY(
    CASE
        WHEN [config_uxon] IS NULL OR LTRIM(RTRIM([config_uxon])) = N''
            THEN N'{}'
        ELSE [config_uxon]
    END,
    '$.instructions',
    [instructions]
)
WHERE [instructions] IS NOT NULL;

ALTER TABLE [exf_ai_agent_version]
    ADD CONSTRAINT [DF_exf_ai_agent_version_instructions]
        DEFAULT N'' FOR [instructions];
GO
ALTER TABLE [exf_ai_agent_version]
    DROP COLUMN [instructions];
GO
ALTER TABLE [exf_ai_agent_version]
    DROP CONSTRAINT [DF_exf_ai_agent_version_instructions];