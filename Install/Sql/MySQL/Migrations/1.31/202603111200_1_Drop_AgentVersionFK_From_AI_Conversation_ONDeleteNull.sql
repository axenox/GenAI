-- UP
SET @fk_name := (
    SELECT CONSTRAINT_NAME
    FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'exf_ai_agent_version'
      AND COLUMN_NAME = 'data_connection_default_oid'
      AND REFERENCED_TABLE_NAME = 'exf_data_connection'
      AND REFERENCED_COLUMN_NAME = 'oid'
    LIMIT 1
);

SET @drop_sql := IF(
    @fk_name IS NOT NULL,
    CONCAT('ALTER TABLE exf_ai_agent_version DROP FOREIGN KEY `', @fk_name, '`'),
    'SELECT 1'
);

PREPARE stmt FROM @drop_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

ALTER TABLE exf_ai_agent_version
    MODIFY data_connection_default_oid BINARY(16) NULL;

/* Verwaiste Referenzen bereinigen */
UPDATE exf_ai_agent_version v
    LEFT JOIN exf_data_connection d
ON v.data_connection_default_oid = d.oid
    SET v.data_connection_default_oid = NULL
WHERE v.data_connection_default_oid IS NOT NULL
  AND d.oid IS NULL;

SET @create_sql := (
    SELECT IF(
        NOT EXISTS (
            SELECT 1
            FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
            WHERE CONSTRAINT_SCHEMA = DATABASE()
              AND TABLE_NAME = 'exf_ai_agent_version'
              AND CONSTRAINT_NAME = 'fk_exf_ai_agent_version_data_connection_default_oid'
              AND CONSTRAINT_TYPE = 'FOREIGN KEY'
        ),
        'ALTER TABLE exf_ai_agent_version ADD CONSTRAINT fk_exf_ai_agent_version_data_connection_default_oid FOREIGN KEY (data_connection_default_oid) REFERENCES exf_data_connection(oid) ON DELETE SET NULL',
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
      AND TABLE_NAME = 'exf_ai_agent_version'
      AND COLUMN_NAME = 'data_connection_default_oid'
      AND REFERENCED_TABLE_NAME = 'exf_data_connection'
      AND REFERENCED_COLUMN_NAME = 'oid'
    LIMIT 1
);

SET @drop_sql := IF(
    @fk_name IS NOT NULL,
    CONCAT('ALTER TABLE exf_ai_agent_version DROP FOREIGN KEY `', @fk_name, '`'),
    'SELECT 1'
);

PREPARE stmt3 FROM @drop_sql;
EXECUTE stmt3;
DEALLOCATE PREPARE stmt3;