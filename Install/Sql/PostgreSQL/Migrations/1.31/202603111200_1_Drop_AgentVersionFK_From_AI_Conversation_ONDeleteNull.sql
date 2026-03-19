-- UP
DO $$
DECLARE
fk_name text;
BEGIN
SELECT tc.constraint_name
INTO fk_name
FROM information_schema.table_constraints tc
         JOIN information_schema.key_column_usage kcu
              ON tc.constraint_name = kcu.constraint_name
                  AND tc.table_schema = kcu.table_schema
         JOIN information_schema.constraint_column_usage ccu
              ON tc.constraint_name = ccu.constraint_name
                  AND tc.table_schema = ccu.table_schema
WHERE tc.constraint_type = 'FOREIGN KEY'
  AND tc.table_name = 'exf_ai_agent_version'
  AND kcu.column_name = 'data_connection_default_oid'
  AND ccu.table_name = 'exf_data_connection'
  AND ccu.column_name = 'oid'
    LIMIT 1;

IF fk_name IS NOT NULL THEN
        EXECUTE format(
            'ALTER TABLE exf_ai_agent_version DROP CONSTRAINT %I',
            fk_name
        );
END IF;
END $$;

/* Verwaiste Referenzen bereinigen */
UPDATE exf_ai_agent_version v
SET data_connection_default_oid = NULL
WHERE v.data_connection_default_oid IS NOT NULL
  AND NOT EXISTS (
    SELECT 1
    FROM exf_data_connection d
    WHERE d.oid = v.data_connection_default_oid
);

ALTER TABLE exf_ai_agent_version
    ALTER COLUMN data_connection_default_oid DROP NOT NULL;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.table_constraints
        WHERE table_name = 'exf_ai_agent_version'
          AND constraint_name = 'fk_exf_ai_agent_version_data_connection_default_oid'
          AND constraint_type = 'FOREIGN KEY'
    ) THEN
ALTER TABLE exf_ai_agent_version
    ADD CONSTRAINT fk_exf_ai_agent_version_data_connection_default_oid
        FOREIGN KEY (data_connection_default_oid)
            REFERENCES exf_data_connection(oid)
            ON DELETE SET NULL;
END IF;
END $$;

-- DOWN
DO $$
DECLARE
fk_name text;
BEGIN
SELECT tc.constraint_name
INTO fk_name
FROM information_schema.table_constraints tc
         JOIN information_schema.key_column_usage kcu
              ON tc.constraint_name = kcu.constraint_name
                  AND tc.table_schema = kcu.table_schema
         JOIN information_schema.constraint_column_usage ccu
              ON tc.constraint_name = ccu.constraint_name
                  AND tc.table_schema = ccu.table_schema
WHERE tc.constraint_type = 'FOREIGN KEY'
  AND tc.table_name = 'exf_ai_agent_version'
  AND kcu.column_name = 'data_connection_default_oid'
  AND ccu.table_name = 'exf_data_connection'
  AND ccu.column_name = 'oid'
    LIMIT 1;

IF fk_name IS NOT NULL THEN
        EXECUTE format(
            'ALTER TABLE exf_ai_agent_version DROP CONSTRAINT %I',
            fk_name
        );
END IF;
END $$;