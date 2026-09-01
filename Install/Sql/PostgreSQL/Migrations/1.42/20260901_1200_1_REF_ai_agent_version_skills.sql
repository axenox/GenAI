/*
 * Normalize AI agent version skills into an ordered assignment table.
 *
 * Existing UXON is only changed after every skill reference was resolved.
 */
-- UP

ALTER TABLE exf_ai_skill
    ADD COLUMN IF NOT EXISTS ai_agent_oid uuid;

ALTER TABLE exf_ai_skill
    DROP CONSTRAINT IF EXISTS uq_exf_ai_skill_alias_ai_agent;

ALTER TABLE exf_ai_skill
    DROP CONSTRAINT IF EXISTS uq_exf_ai_skill_alias_app;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'ck_exf_ai_skill_single_owner'
          AND conrelid = 'exf_ai_skill'::regclass
    ) THEN
        ALTER TABLE exf_ai_skill
            ADD CONSTRAINT ck_exf_ai_skill_single_owner
            CHECK (app_oid IS NULL OR ai_agent_oid IS NULL);
    END IF;
END $$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'fk_exf_ai_skill_ai_agent'
          AND conrelid = 'exf_ai_skill'::regclass
    ) THEN
        ALTER TABLE exf_ai_skill
            ADD CONSTRAINT fk_exf_ai_skill_ai_agent
            FOREIGN KEY (ai_agent_oid) REFERENCES exf_ai_agent (oid);
    END IF;
END $$;

CREATE INDEX IF NOT EXISTS idx_exf_ai_skill_ai_agent
    ON exf_ai_skill (ai_agent_oid);

CREATE UNIQUE INDEX IF NOT EXISTS uq_exf_ai_skill_alias_global
    ON exf_ai_skill (alias)
    WHERE app_oid IS NULL AND ai_agent_oid IS NULL;

CREATE UNIQUE INDEX IF NOT EXISTS uq_exf_ai_skill_alias_app
    ON exf_ai_skill (app_oid, alias)
    WHERE app_oid IS NOT NULL AND ai_agent_oid IS NULL;

CREATE UNIQUE INDEX IF NOT EXISTS uq_exf_ai_skill_alias_agent
    ON exf_ai_skill (ai_agent_oid, alias)
    WHERE ai_agent_oid IS NOT NULL AND app_oid IS NULL;

CREATE TABLE IF NOT EXISTS exf_ai_agent_version_skill (
    oid                  uuid      NOT NULL,
    created_on           timestamp NOT NULL,
    modified_on          timestamp NOT NULL,
    created_by_user_oid  uuid,
    modified_by_user_oid uuid,
    ai_agent_version_oid uuid      NOT NULL,
    ai_skill_oid         uuid      NOT NULL,
    sort_index           integer   NOT NULL DEFAULT 0,
    CONSTRAINT pk_exf_ai_agent_version_skill PRIMARY KEY (oid),
    CONSTRAINT fk_exf_ai_agent_version_skill_version
        FOREIGN KEY (ai_agent_version_oid) REFERENCES exf_ai_agent_version (oid),
    CONSTRAINT fk_exf_ai_agent_version_skill_skill
        FOREIGN KEY (ai_skill_oid) REFERENCES exf_ai_skill (oid),
    CONSTRAINT uq_exf_ai_agent_version_skill
        UNIQUE (ai_agent_version_oid, ai_skill_oid),
    CONSTRAINT ck_exf_ai_agent_version_skill_sort
        CHECK (sort_index >= 0)
);

CREATE INDEX IF NOT EXISTS idx_exf_ai_agent_version_skill_order
    ON exf_ai_agent_version_skill (ai_agent_version_oid, sort_index);

CREATE INDEX IF NOT EXISTS idx_exf_ai_agent_version_skill_skill
    ON exf_ai_agent_version_skill (ai_skill_oid);

CREATE TEMPORARY TABLE tmp_ai_agent_version_skill (
    ai_agent_version_oid uuid    NOT NULL,
    ai_skill_oid         uuid    NOT NULL,
    sort_index           integer NOT NULL,
    PRIMARY KEY (ai_agent_version_oid, ai_skill_oid)
) ON COMMIT DROP;

CREATE TEMPORARY TABLE tmp_ai_agent_version_skill_cleanup (
    ai_agent_version_oid uuid PRIMARY KEY
) ON COMMIT DROP;

DO $$
DECLARE
    version_row record;
    entry_row record;
    config_json json;
    skills_json json;
    stored_alias text;
    matching_skill_oid uuid;
    matching_skill_count integer;
BEGIN
    FOR version_row IN
        SELECT oid, ai_agent_oid, config_uxon
        FROM exf_ai_agent_version
        WHERE config_uxon IS NOT NULL
          AND btrim(config_uxon) <> ''
    LOOP
        BEGIN
            config_json := version_row.config_uxon::json;
        EXCEPTION WHEN others THEN
            CONTINUE;
        END;

        IF json_typeof(config_json) <> 'object'
           OR NOT (config_json::jsonb ? 'skills') THEN
            CONTINUE;
        END IF;

        skills_json := config_json -> 'skills';
        IF json_typeof(skills_json) = 'null' THEN
            CONTINUE;
        END IF;
        IF json_typeof(skills_json) <> 'object' THEN
            RAISE EXCEPTION
                'Cannot migrate AI agent version %: config_uxon.skills must be a JSON map',
                version_row.oid;
        END IF;

        FOR entry_row IN
            SELECT skill.key, skill.value, skill.ordinality
            FROM json_each(skills_json) WITH ORDINALITY AS skill(key, value, ordinality)
        LOOP
            IF json_typeof(entry_row.value) <> 'object' THEN
                RAISE EXCEPTION
                    'Cannot migrate AI agent version % skill "%": value must be an object',
                    version_row.oid, entry_row.key;
            END IF;

            stored_alias := entry_row.value ->> 'alias';
            IF stored_alias IS NULL OR btrim(stored_alias) = '' THEN
                RAISE EXCEPTION
                    'Cannot migrate AI agent version % skill "%": alias is missing',
                    version_row.oid, entry_row.key;
            END IF;

            SELECT count(*), min(skill.oid::text)::uuid
            INTO matching_skill_count, matching_skill_oid
            FROM exf_ai_skill skill
            LEFT JOIN exf_app skill_app
                ON skill_app.oid = skill.app_oid
            LEFT JOIN exf_ai_agent owner_agent
                ON owner_agent.oid = skill.ai_agent_oid
            LEFT JOIN exf_app owner_agent_app
                ON owner_agent_app.oid = owner_agent.app_oid
            WHERE skill.alias = entry_row.key
              AND (skill.ai_agent_oid IS NULL
                   OR skill.ai_agent_oid = version_row.ai_agent_oid)
              AND stored_alias = CASE
                  WHEN skill.app_oid IS NOT NULL
                      THEN skill_app.app_alias || '.' || skill.alias
                  WHEN skill.ai_agent_oid IS NOT NULL
                      THEN owner_agent_app.app_alias || '.' || skill.alias
                  ELSE skill.alias
              END;

            IF matching_skill_count <> 1 THEN
                RAISE EXCEPTION
                    'Cannot migrate AI agent version % skill "%" (alias "%"): expected exactly one AI skill, found %',
                    version_row.oid, entry_row.key, stored_alias,
                    matching_skill_count;
            END IF;

            INSERT INTO tmp_ai_agent_version_skill (
                ai_agent_version_oid,
                ai_skill_oid,
                sort_index
            ) VALUES (
                version_row.oid,
                matching_skill_oid,
                entry_row.ordinality - 1
            );
        END LOOP;

        INSERT INTO tmp_ai_agent_version_skill_cleanup (ai_agent_version_oid)
        VALUES (version_row.oid)
        ON CONFLICT DO NOTHING;
    END LOOP;
END $$;

INSERT INTO exf_ai_agent_version_skill (
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
    md5(
        replace(ai_agent_version_oid::text, '-', '')
        || ':'
        || replace(ai_skill_oid::text, '-', '')
    )::uuid,
    CURRENT_TIMESTAMP,
    CURRENT_TIMESTAMP,
    NULL,
    NULL,
    ai_agent_version_oid,
    ai_skill_oid,
    sort_index
FROM tmp_ai_agent_version_skill
ON CONFLICT (ai_agent_version_oid, ai_skill_oid) DO UPDATE
SET sort_index = EXCLUDED.sort_index,
    modified_on = CURRENT_TIMESTAMP;

UPDATE exf_ai_agent_version version
SET config_uxon = (version.config_uxon::jsonb - 'skills')::text
FROM tmp_ai_agent_version_skill_cleanup cleanup
WHERE cleanup.ai_agent_version_oid = version.oid;

-- DOWN

-- Keep normalized skill assignments and skill configuration constraints.
-- Deleting this configuration data during rollback would be destructive.