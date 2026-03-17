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
  AND tc.table_name = 'exf_ai_conversation'
  AND kcu.column_name = 'connection_oid'
  AND ccu.table_name = 'exf_data_connection'
  AND ccu.column_name = 'oid'
    LIMIT 1;

IF fk_name IS NOT NULL THEN
        EXECUTE format(
            'ALTER TABLE exf_ai_conversation DROP CONSTRAINT %I',
            fk_name
        );
END IF;
END $$;

/* Verwaiste Referenzen bereinigen */
UPDATE exf_ai_conversation c
SET connection_oid = NULL
WHERE c.connection_oid IS NOT NULL
  AND NOT EXISTS (
    SELECT 1
    FROM exf_data_connection d
    WHERE d.oid = c.connection_oid
);

ALTER TABLE exf_ai_conversation
    ALTER COLUMN connection_oid DROP NOT NULL;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.table_constraints
        WHERE table_name = 'exf_ai_conversation'
          AND constraint_name = 'fk_exf_ai_conversation_connection_oid'
          AND constraint_type = 'FOREIGN KEY'
    ) THEN
ALTER TABLE exf_ai_conversation
    ADD CONSTRAINT fk_exf_ai_conversation_connection_oid
        FOREIGN KEY (connection_oid)
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
  AND tc.table_name = 'exf_ai_conversation'
  AND kcu.column_name = 'connection_oid'
  AND ccu.table_name = 'exf_data_connection'
  AND ccu.column_name = 'oid'
    LIMIT 1;

IF fk_name IS NOT NULL THEN
        EXECUTE format(
            'ALTER TABLE exf_ai_conversation DROP CONSTRAINT %I',
            fk_name
        );
END IF;
END $$;