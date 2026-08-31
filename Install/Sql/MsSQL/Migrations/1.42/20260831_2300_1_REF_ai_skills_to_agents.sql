/*
 * Reference AI skills to agents instead of apps.
 *
 * Ambiguous legacy rows remain unassigned while their app reference is preserved.
 */
-- UP

ALTER TABLE dbo.exf_ai_skill
  ADD ai_agent_oid binary(16) NULL;

UPDATE skill
SET ai_agent_oid = agent.oid
FROM dbo.exf_ai_skill AS skill
INNER JOIN dbo.exf_ai_agent AS agent
  ON agent.app_oid = skill.app_oid
WHERE (
  SELECT COUNT(*)
  FROM dbo.exf_ai_agent AS candidate
  WHERE candidate.app_oid = skill.app_oid
) = 1;

ALTER TABLE dbo.exf_ai_skill
  DROP CONSTRAINT UQ_exf_ai_skill_alias_app;

CREATE UNIQUE INDEX UQ_exf_ai_skill_alias_ai_agent
  ON dbo.exf_ai_skill (alias, ai_agent_oid)
  WHERE ai_agent_oid IS NOT NULL;

CREATE INDEX IDX_dbo_exf_ai_skill_ai_agent
  ON dbo.exf_ai_skill (ai_agent_oid);

ALTER TABLE dbo.exf_ai_skill
  ADD CONSTRAINT FK_dbo_exf_ai_skill_ai_agent
    FOREIGN KEY (ai_agent_oid) REFERENCES dbo.exf_ai_agent (oid);

-- DOWN

UPDATE skill
SET app_oid = agent.app_oid
FROM dbo.exf_ai_skill AS skill
INNER JOIN dbo.exf_ai_agent AS agent
  ON agent.oid = skill.ai_agent_oid;

ALTER TABLE dbo.exf_ai_skill
  DROP CONSTRAINT FK_dbo_exf_ai_skill_ai_agent;

DROP INDEX IDX_dbo_exf_ai_skill_ai_agent ON dbo.exf_ai_skill;

DROP INDEX UQ_exf_ai_skill_alias_ai_agent ON dbo.exf_ai_skill;

ALTER TABLE dbo.exf_ai_skill
  DROP COLUMN ai_agent_oid;

ALTER TABLE dbo.exf_ai_skill
  ADD CONSTRAINT UQ_exf_ai_skill_alias_app
    UNIQUE (alias, app_oid);