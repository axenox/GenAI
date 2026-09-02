/*
 * Normalize AI agent version skills into an ordered assignment table.
 *
 * Existing UXON is only changed after every skill reference was resolved.
 */
-- UP

SET XACT_ABORT ON;

IF EXISTS (
    SELECT 1
    FROM sys.indexes
    WHERE name = N'UQ_exf_ai_skill_alias_ai_agent'
      AND object_id = OBJECT_ID(N'dbo.exf_ai_skill')
)
DROP INDEX UQ_exf_ai_skill_alias_ai_agent ON dbo.exf_ai_skill;

IF NOT EXISTS (
    SELECT 1
    FROM sys.check_constraints
    WHERE name = N'CK_exf_ai_skill_single_owner'
      AND parent_object_id = OBJECT_ID(N'dbo.exf_ai_skill')
)
ALTER TABLE dbo.exf_ai_skill WITH CHECK
ADD CONSTRAINT CK_exf_ai_skill_single_owner
CHECK (app_oid IS NULL OR ai_agent_oid IS NULL);

IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE name = N'UQ_exf_ai_skill_alias_global'
      AND object_id = OBJECT_ID(N'dbo.exf_ai_skill')
)
CREATE UNIQUE INDEX UQ_exf_ai_skill_alias_global
ON dbo.exf_ai_skill (alias)
WHERE app_oid IS NULL AND ai_agent_oid IS NULL;

IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE name = N'UQ_exf_ai_skill_alias_app'
      AND object_id = OBJECT_ID(N'dbo.exf_ai_skill')
)
CREATE UNIQUE INDEX UQ_exf_ai_skill_alias_app
ON dbo.exf_ai_skill (app_oid, alias)
WHERE app_oid IS NOT NULL AND ai_agent_oid IS NULL;

IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE name = N'UQ_exf_ai_skill_alias_agent'
      AND object_id = OBJECT_ID(N'dbo.exf_ai_skill')
)
CREATE UNIQUE INDEX UQ_exf_ai_skill_alias_agent
ON dbo.exf_ai_skill (ai_agent_oid, alias)
WHERE ai_agent_oid IS NOT NULL AND app_oid IS NULL;

IF OBJECT_ID(N'dbo.exf_ai_agent_version_skill', N'U') IS NULL
CREATE TABLE dbo.exf_ai_agent_version_skill (
    oid binary(16) NOT NULL,
    created_on datetime NOT NULL,
    modified_on datetime NOT NULL,
    created_by_user_oid binary(16),
    modified_by_user_oid binary(16),
    ai_agent_version_oid binary(16) NOT NULL,
    ai_skill_oid binary(16) NOT NULL,
    sort_index int NOT NULL
        CONSTRAINT DF_exf_ai_agent_version_skill_sort DEFAULT 0,
    CONSTRAINT PK_exf_ai_agent_version_skill PRIMARY KEY (oid),
    CONSTRAINT FK_exf_ai_agent_version_skill_version
        FOREIGN KEY (ai_agent_version_oid)
        REFERENCES dbo.exf_ai_agent_version (oid),
    CONSTRAINT FK_exf_ai_agent_version_skill_skill
        FOREIGN KEY (ai_skill_oid) REFERENCES dbo.exf_ai_skill (oid),
    CONSTRAINT UQ_exf_ai_agent_version_skill
        UNIQUE (ai_agent_version_oid, ai_skill_oid),
    CONSTRAINT CK_exf_ai_agent_version_skill_sort CHECK (sort_index >= 0)
);

IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE name = N'IX_exf_ai_agent_version_skill_order'
      AND object_id = OBJECT_ID(N'dbo.exf_ai_agent_version_skill')
)
CREATE INDEX IX_exf_ai_agent_version_skill_order
ON dbo.exf_ai_agent_version_skill (ai_agent_version_oid, sort_index);

IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE name = N'IX_exf_ai_agent_version_skill_skill'
      AND object_id = OBJECT_ID(N'dbo.exf_ai_agent_version_skill')
)
CREATE INDEX IX_exf_ai_agent_version_skill_skill
ON dbo.exf_ai_agent_version_skill (ai_skill_oid);

IF EXISTS (
    SELECT 1
    FROM dbo.exf_ai_agent_version version
    CROSS APPLY OPENJSON(
        CASE WHEN ISJSON(version.config_uxon) = 1
            THEN version.config_uxon ELSE N'{}' END
    ) property
    WHERE ISJSON(version.config_uxon) = 1
      AND property.[key] = N'skills'
      AND property.[type] NOT IN (0, 5)
)
THROW 51000,
    'Cannot migrate AI agent skills: config_uxon.skills must be a JSON map',
    1;

CREATE TABLE #ai_agent_version_skill_raw (
    entry_id int IDENTITY(1, 1) NOT NULL PRIMARY KEY,
    ai_agent_version_oid binary(16) NOT NULL,
    consumer_agent_oid binary(16) NOT NULL,
    skill_key nvarchar(100) NOT NULL,
    stored_alias nvarchar(255),
    entry_type int NOT NULL,
    source_position int NOT NULL,
    sort_index int
);

INSERT INTO #ai_agent_version_skill_raw (
    ai_agent_version_oid,
    consumer_agent_oid,
    skill_key,
    stored_alias,
    entry_type,
    source_position
)
SELECT
    version.oid,
    version.ai_agent_oid,
    skill.[key],
    JSON_VALUE(skill.[value], N'$.alias'),
    skill.[type],
    CHARINDEX(
        N'"' + STRING_ESCAPE(skill.[key], 'json') + N'"',
        version.config_uxon
    )
FROM dbo.exf_ai_agent_version version
CROSS APPLY OPENJSON(
    CASE WHEN ISJSON(version.config_uxon) = 1
        THEN version.config_uxon ELSE N'{}' END
) property
CROSS APPLY OPENJSON(
    CASE WHEN property.[type] = 5 THEN property.[value] ELSE N'{}' END
) skill
WHERE ISJSON(version.config_uxon) = 1
  AND property.[key] = N'skills'
  AND property.[type] = 5;

WITH ordered_skills AS (
    SELECT
        entry_id,
        ROW_NUMBER() OVER (
            PARTITION BY ai_agent_version_oid
            ORDER BY source_position, entry_id
        ) - 1 AS sort_index
    FROM #ai_agent_version_skill_raw
)
UPDATE raw
SET sort_index = ordered.sort_index
FROM #ai_agent_version_skill_raw raw
INNER JOIN ordered_skills ordered ON ordered.entry_id = raw.entry_id;

IF EXISTS (
    SELECT 1
    FROM #ai_agent_version_skill_raw
    WHERE entry_type <> 5
       OR stored_alias IS NULL
       OR LTRIM(RTRIM(stored_alias)) = N''
         OR source_position <= 0
)
THROW 51000,
     'Cannot migrate AI agent skills: invalid value, alias, or JSON order',
    1;

CREATE TABLE #ai_agent_version_skill_candidate (
    entry_id int NOT NULL,
    ai_skill_oid binary(16) NOT NULL
);

INSERT INTO #ai_agent_version_skill_candidate (entry_id, ai_skill_oid)
SELECT raw.entry_id, skill.oid
FROM #ai_agent_version_skill_raw raw
INNER JOIN dbo.exf_ai_skill skill
    ON skill.alias = raw.skill_key
   AND (skill.ai_agent_oid IS NULL
        OR skill.ai_agent_oid = raw.consumer_agent_oid)
LEFT JOIN dbo.exf_app skill_app ON skill_app.oid = skill.app_oid
LEFT JOIN dbo.exf_ai_agent owner_agent
    ON owner_agent.oid = skill.ai_agent_oid
LEFT JOIN dbo.exf_app owner_agent_app
    ON owner_agent_app.oid = owner_agent.app_oid
WHERE raw.stored_alias = CASE
    WHEN skill.app_oid IS NOT NULL
        THEN skill_app.app_alias + N'.' + skill.alias
    WHEN skill.ai_agent_oid IS NOT NULL
        THEN owner_agent_app.app_alias + N'.' + skill.alias
    ELSE skill.alias
END;

IF EXISTS (
    SELECT raw.entry_id
    FROM #ai_agent_version_skill_raw raw
    LEFT JOIN #ai_agent_version_skill_candidate candidate
        ON candidate.entry_id = raw.entry_id
    GROUP BY raw.entry_id
    HAVING COUNT(candidate.ai_skill_oid) <> 1
)
THROW 51000,
    'Cannot migrate AI agent skills: a reference did not resolve exactly once',
    1;

UPDATE assignment
SET assignment.sort_index = raw.sort_index,
    assignment.modified_on = GETDATE()
FROM dbo.exf_ai_agent_version_skill assignment
INNER JOIN #ai_agent_version_skill_candidate candidate
    ON candidate.ai_skill_oid = assignment.ai_skill_oid
INNER JOIN #ai_agent_version_skill_raw raw
    ON raw.entry_id = candidate.entry_id
   AND raw.ai_agent_version_oid = assignment.ai_agent_version_oid;

INSERT INTO dbo.exf_ai_agent_version_skill (
    oid,
    created_on,
    modified_on,
    created_by_user_oid,
    modified_by_user_oid,
    ai_agent_version_oid,
    ai_skill_oid,
    sort_index
)
SELECT
    CONVERT(binary(16), HASHBYTES(
        'MD5',
        LOWER(CONVERT(varchar(32), raw.ai_agent_version_oid, 2))
            + ':'
            + LOWER(CONVERT(varchar(32), candidate.ai_skill_oid, 2))
    )),
    GETDATE(),
    GETDATE(),
    NULL,
    NULL,
    raw.ai_agent_version_oid,
    candidate.ai_skill_oid,
    raw.sort_index
FROM #ai_agent_version_skill_raw raw
INNER JOIN #ai_agent_version_skill_candidate candidate
    ON candidate.entry_id = raw.entry_id
WHERE NOT EXISTS (
    SELECT 1
    FROM dbo.exf_ai_agent_version_skill assignment
    WHERE assignment.ai_agent_version_oid = raw.ai_agent_version_oid
      AND assignment.ai_skill_oid = candidate.ai_skill_oid
);

UPDATE version
SET config_uxon = JSON_MODIFY(version.config_uxon, N'$.skills', NULL)
FROM dbo.exf_ai_agent_version version
WHERE EXISTS (
    SELECT 1 FROM #ai_agent_version_skill_raw raw
    WHERE raw.ai_agent_version_oid = version.oid
);

DROP TABLE #ai_agent_version_skill_candidate;
DROP TABLE #ai_agent_version_skill_raw;

-- DOWN

-- Keep normalized skill assignments and skill configuration constraints.
-- Deleting this configuration data during rollback would be destructive.