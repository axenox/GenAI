/*
 * Add an optional long-text description to AI agent version skills.
 */
-- UP

IF COL_LENGTH(
    N'dbo.exf_ai_agent_version_skill',
    N'description'
) IS NULL
ALTER TABLE dbo.exf_ai_agent_version_skill
ADD description nvarchar(max) NULL;

-- DOWN
-- ALTER TABLE dbo.exf_ai_agent_version_skill DROP COLUMN description;