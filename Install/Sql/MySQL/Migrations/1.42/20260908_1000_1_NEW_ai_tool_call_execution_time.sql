/*
 * Add execution time to exf_ai_tool_call.
 */
-- UP

SET @table_exists = (
  SELECT COUNT(*) FROM information_schema.tables
  WHERE table_schema = DATABASE() AND table_name = 'exf_ai_tool_call'
);
SET @column_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'exf_ai_tool_call'
    AND column_name = 'execution_time_ms'
);
SET @sql = IF(@table_exists = 1 AND @column_exists = 0,
  'ALTER TABLE `exf_ai_tool_call` ADD `execution_time_ms` int NULL AFTER `result_length_chars`',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- DOWN

-- Intentionally kept: dropping this column would discard stored data.