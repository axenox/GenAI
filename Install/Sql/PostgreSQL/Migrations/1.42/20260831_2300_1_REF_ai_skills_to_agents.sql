/*
 * Reference AI skills to agents instead of apps.
 *
 * Ambiguous legacy rows remain unassigned while their app reference is preserved.
 */
-- UP

ALTER TABLE exf_ai_skill
    ADD COLUMN ai_agent_oid uuid;

UPDATE exf_ai_skill AS skill
SET ai_agent_oid = agent.oid
FROM exf_ai_agent AS agent
WHERE agent.app_oid = skill.app_oid
  AND (
      SELECT COUNT(*)
      FROM exf_ai_agent AS candidate
      WHERE candidate.app_oid = skill.app_oid
  ) = 1;

ALTER TABLE exf_ai_skill
        DROP CONSTRAINT uq_exf_ai_skill_alias_app,
    ADD CONSTRAINT uq_exf_ai_skill_alias_ai_agent
        UNIQUE (alias, ai_agent_oid);

CREATE INDEX idx_exf_ai_skill_ai_agent
    ON exf_ai_skill (ai_agent_oid);

ALTER TABLE exf_ai_skill
    ADD CONSTRAINT fk_exf_ai_skill_ai_agent
        FOREIGN KEY (ai_agent_oid) REFERENCES exf_ai_agent (oid);

-- DOWN

UPDATE exf_ai_skill AS skill
SET app_oid = agent.app_oid
FROM exf_ai_agent AS agent
WHERE agent.oid = skill.ai_agent_oid;

ALTER TABLE exf_ai_skill
    DROP CONSTRAINT fk_exf_ai_skill_ai_agent;

DROP INDEX idx_exf_ai_skill_ai_agent;

ALTER TABLE exf_ai_skill
    DROP CONSTRAINT uq_exf_ai_skill_alias_ai_agent,
    DROP COLUMN ai_agent_oid;

ALTER TABLE exf_ai_skill
    ADD CONSTRAINT uq_exf_ai_skill_alias_app
        UNIQUE (alias, app_oid);
