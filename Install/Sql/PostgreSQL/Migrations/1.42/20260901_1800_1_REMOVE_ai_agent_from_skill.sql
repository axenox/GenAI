/*
 * Move agent-owned AI skills to their agent app and remove agent ownership.
 *
 * Validation prevents unresolved owners and alias collisions before data changes.
 */
-- UP

DO $$
BEGIN
    IF EXISTS (
        SELECT 1
        FROM pg_attribute
        WHERE attrelid = 'exf_ai_skill'::regclass
          AND attname = 'ai_agent_oid'
          AND NOT attisdropped
    ) THEN
        IF EXISTS (
            SELECT 1
            FROM exf_ai_skill skill
            LEFT JOIN exf_ai_agent agent
                ON agent.oid = skill.ai_agent_oid
            WHERE skill.ai_agent_oid IS NOT NULL
              AND (agent.oid IS NULL OR agent.app_oid IS NULL)
        ) THEN
            RAISE EXCEPTION
                'Cannot remove AI skill agent ownership: an owner has no agent app';
        END IF;

        IF EXISTS (
            SELECT 1
            FROM exf_ai_skill skill
            INNER JOIN exf_ai_agent owner_agent
                ON owner_agent.oid = skill.ai_agent_oid
            INNER JOIN exf_ai_skill other_skill
                ON other_skill.oid <> skill.oid
               AND other_skill.alias = skill.alias
            LEFT JOIN exf_ai_agent other_agent
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
        ) THEN
            RAISE EXCEPTION
                'Cannot remove AI skill agent ownership: app alias collision detected';
        END IF;

        UPDATE exf_ai_skill skill
        SET app_oid = agent.app_oid,
            ai_agent_oid = NULL
        FROM exf_ai_agent agent
        WHERE agent.oid = skill.ai_agent_oid
          AND skill.ai_agent_oid IS NOT NULL;
    END IF;
END $$;

DO $$
DECLARE
    constraint_name text;
BEGIN
    FOR constraint_name IN
        SELECT constraint_row.conname
        FROM pg_constraint constraint_row
        LEFT JOIN pg_attribute skill_column
            ON skill_column.attrelid = constraint_row.conrelid
           AND skill_column.attname = 'ai_agent_oid'
        WHERE constraint_row.conrelid = 'exf_ai_skill'::regclass
          AND (
            skill_column.attnum = ANY(constraint_row.conkey)
            OR pg_get_constraintdef(constraint_row.oid) ILIKE '%ai_agent_oid%'
          )
    LOOP
        EXECUTE format(
            'ALTER TABLE exf_ai_skill DROP CONSTRAINT %I',
            constraint_name
        );
    END LOOP;
END $$;

DO $$
DECLARE
    index_row record;
BEGIN
    FOR index_row IN
        SELECT index_class.relname AS index_name,
               index_namespace.nspname AS schema_name
        FROM pg_index skill_index
        INNER JOIN pg_class index_class
            ON index_class.oid = skill_index.indexrelid
        INNER JOIN pg_namespace index_namespace
            ON index_namespace.oid = index_class.relnamespace
        LEFT JOIN pg_attribute skill_column
            ON skill_column.attrelid = skill_index.indrelid
           AND skill_column.attname = 'ai_agent_oid'
        WHERE skill_index.indrelid = 'exf_ai_skill'::regclass
          AND (
            skill_column.attnum = ANY(skill_index.indkey)
            OR pg_get_expr(
                skill_index.indpred,
                skill_index.indrelid
            ) ILIKE '%ai_agent_oid%'
          )
    LOOP
        EXECUTE format(
            'DROP INDEX %I.%I',
            index_row.schema_name,
            index_row.index_name
        );
    END LOOP;
END $$;

ALTER TABLE exf_ai_skill
    DROP COLUMN IF EXISTS ai_agent_oid;

CREATE UNIQUE INDEX IF NOT EXISTS uq_exf_ai_skill_alias_global
    ON exf_ai_skill (alias)
    WHERE app_oid IS NULL;

CREATE UNIQUE INDEX IF NOT EXISTS uq_exf_ai_skill_alias_app
    ON exf_ai_skill (app_oid, alias)
    WHERE app_oid IS NOT NULL;

-- DOWN

-- Keep app ownership and the app/global uniqueness model.
-- Reconstructing agent ownership would assign skills arbitrarily.