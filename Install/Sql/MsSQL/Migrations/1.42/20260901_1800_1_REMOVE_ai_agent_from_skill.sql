/*
 * Move agent-owned AI skills to their agent app and remove agent ownership.
 *
 * Validation prevents unresolved owners and alias collisions before data changes.
 */
-- UP

SET XACT_ABORT ON;

IF COL_LENGTH(N'dbo.exf_ai_skill', N'ai_agent_oid') IS NOT NULL
BEGIN
    EXEC sys.sp_executesql N'
        IF EXISTS (
            SELECT 1
            FROM dbo.exf_ai_skill skill
            LEFT JOIN dbo.exf_ai_agent agent
                ON agent.oid = skill.ai_agent_oid
            WHERE skill.ai_agent_oid IS NOT NULL
              AND (agent.oid IS NULL OR agent.app_oid IS NULL)
        )
        THROW 51000,
            N''Cannot remove AI skill agent ownership: an owner has no agent app'',
            1;

        IF EXISTS (
            SELECT 1
            FROM dbo.exf_ai_skill skill
            INNER JOIN dbo.exf_ai_agent owner_agent
                ON owner_agent.oid = skill.ai_agent_oid
            INNER JOIN dbo.exf_ai_skill other_skill
                ON other_skill.oid <> skill.oid
               AND other_skill.alias = skill.alias
            LEFT JOIN dbo.exf_ai_agent other_agent
                ON other_agent.oid = other_skill.ai_agent_oid
            WHERE skill.ai_agent_oid IS NOT NULL
              AND (
                (
                    other_skill.ai_agent_oid IS NULL
                    AND other_skill.app_oid = owner_agent.app_oid
                )
                OR (
                    other_skill.ai_agent_oid IS NOT NULL
                    AND other_agent.app_oid = owner_agent.app_oid
                )
              )
        )
        THROW 51000,
            N''Cannot remove AI skill agent ownership: app alias collision detected'',
            1;

        UPDATE skill
        SET skill.app_oid = agent.app_oid,
            skill.ai_agent_oid = NULL
        FROM dbo.exf_ai_skill skill
        INNER JOIN dbo.exf_ai_agent agent
            ON agent.oid = skill.ai_agent_oid
        WHERE skill.ai_agent_oid IS NOT NULL;
    ';
END;

DECLARE @drop_foreign_keys_sql nvarchar(max) = N'';

SELECT @drop_foreign_keys_sql = @drop_foreign_keys_sql
    + N'ALTER TABLE dbo.exf_ai_skill DROP CONSTRAINT '
    + QUOTENAME(foreign_key.name) + N';'
FROM sys.foreign_keys foreign_key
INNER JOIN sys.foreign_key_columns foreign_key_column
    ON foreign_key_column.constraint_object_id = foreign_key.object_id
INNER JOIN sys.columns skill_column
    ON skill_column.object_id = foreign_key_column.parent_object_id
   AND skill_column.column_id = foreign_key_column.parent_column_id
WHERE foreign_key.parent_object_id = OBJECT_ID(N'dbo.exf_ai_skill')
  AND skill_column.name = N'ai_agent_oid';

IF @drop_foreign_keys_sql <> N''
    EXEC sys.sp_executesql @drop_foreign_keys_sql;

DECLARE @drop_indexes_sql nvarchar(max) = N'';

SELECT @drop_indexes_sql = @drop_indexes_sql
    + N'DROP INDEX ' + QUOTENAME(skill_index.name)
    + N' ON dbo.exf_ai_skill;'
FROM sys.indexes skill_index
WHERE skill_index.object_id = OBJECT_ID(N'dbo.exf_ai_skill')
  AND skill_index.is_primary_key = 0
  AND (
    skill_index.filter_definition LIKE N'%ai_agent_oid%'
    OR EXISTS (
        SELECT 1
        FROM sys.index_columns index_column
        INNER JOIN sys.columns skill_column
            ON skill_column.object_id = index_column.object_id
           AND skill_column.column_id = index_column.column_id
        WHERE index_column.object_id = skill_index.object_id
          AND index_column.index_id = skill_index.index_id
          AND skill_column.name = N'ai_agent_oid'
    )
  );

IF @drop_indexes_sql <> N''
    EXEC sys.sp_executesql @drop_indexes_sql;

DECLARE @drop_checks_sql nvarchar(max) = N'';

SELECT @drop_checks_sql = @drop_checks_sql
    + N'ALTER TABLE dbo.exf_ai_skill DROP CONSTRAINT '
    + QUOTENAME(check_constraint.name) + N';'
FROM sys.check_constraints check_constraint
WHERE check_constraint.parent_object_id = OBJECT_ID(N'dbo.exf_ai_skill')
  AND check_constraint.definition LIKE N'%ai_agent_oid%';

IF @drop_checks_sql <> N''
    EXEC sys.sp_executesql @drop_checks_sql;

IF COL_LENGTH(N'dbo.exf_ai_skill', N'ai_agent_oid') IS NOT NULL
    EXEC sys.sp_executesql
        N'ALTER TABLE dbo.exf_ai_skill DROP COLUMN ai_agent_oid;';

IF NOT EXISTS (
    SELECT 1
    FROM sys.indexes
    WHERE name = N'UQ_exf_ai_skill_alias_global'
      AND object_id = OBJECT_ID(N'dbo.exf_ai_skill')
)
CREATE UNIQUE INDEX UQ_exf_ai_skill_alias_global
ON dbo.exf_ai_skill (alias)
WHERE app_oid IS NULL;

IF NOT EXISTS (
    SELECT 1
    FROM sys.indexes
    WHERE name = N'UQ_exf_ai_skill_alias_app'
      AND object_id = OBJECT_ID(N'dbo.exf_ai_skill')
)
CREATE UNIQUE INDEX UQ_exf_ai_skill_alias_app
ON dbo.exf_ai_skill (app_oid, alias)
WHERE app_oid IS NOT NULL;

-- DOWN

-- Keep app ownership and the app/global uniqueness model.
-- Reconstructing agent ownership would assign skills arbitrarily.