-- UP
SET @fk_name := (
    SELECT CONSTRAINT_NAME
    FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'exf_ai_conversation'
      AND COLUMN_NAME = 'connection_oid'
      AND REFERENCED_TABLE_NAME = 'exf_data_connection'
      AND REFERENCED_COLUMN_NAME = 'oid'
    LIMIT 1
);

SET @drop_sql := IF(
    @fk_name IS NOT NULL,
    CONCAT('ALTER TABLE exf_ai_conversation DROP FOREIGN KEY `', @fk_name, '`'),
    'SELECT 1'
);

PREPARE stmt FROM @drop_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

ALTER TABLE exf_ai_conversation
    MODIFY connection_oid BINARY(16) NULL;

/* Verwaiste Referenzen bereinigen */
UPDATE exf_ai_conversation c
    LEFT JOIN exf_data_connection d
ON c.connection_oid = d.oid
    SET c.connection_oid = NULL
WHERE c.connection_oid IS NOT NULL
  AND d.oid IS NULL;

SET @create_sql := (
    SELECT IF(
        NOT EXISTS (
            SELECT 1
            FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
            WHERE CONSTRAINT_SCHEMA = DATABASE()
              AND TABLE_NAME = 'exf_ai_conversation'
              AND CONSTRAINT_NAME = 'fk_exf_ai_conversation_connection_oid'
              AND CONSTRAINT_TYPE = 'FOREIGN KEY'
        ),
        'ALTER TABLE exf_ai_conversation ADD CONSTRAINT fk_exf_ai_conversation_connection_oid FOREIGN KEY (connection_oid) REFERENCES exf_data_connection(oid) ON DELETE SET NULL',
        'SELECT 1'
    )
);

PREPARE stmt2 FROM @create_sql;
EXECUTE stmt2;
DEALLOCATE PREPARE stmt2;

-- DOWN
SET @fk_name := (
    SELECT CONSTRAINT_NAME
    FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'exf_ai_conversation'
      AND COLUMN_NAME = 'connection_oid'
      AND REFERENCED_TABLE_NAME = 'exf_data_connection'
      AND REFERENCED_COLUMN_NAME = 'oid'
    LIMIT 1
);

SET @drop_sql := IF(
    @fk_name IS NOT NULL,
    CONCAT('ALTER TABLE exf_ai_conversation DROP FOREIGN KEY `', @fk_name, '`'),
    'SELECT 1'
);

PREPARE stmt3 FROM @drop_sql;
EXECUTE stmt3;
DEALLOCATE PREPARE stmt3;