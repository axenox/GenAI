/*
 * Add response length to exf_ai_tool_call
 *
 * Stores the result size in characters to make tool-call monitoring easier
 * and backfills the size for existing tool calls.
 * DOWN keeps the column, since dropping it would discard stored data.
 *
 * @author GitHub Copilot
 */
-- UP

SET @table_exists = (
  SELECT COUNT(*) FROM information_schema.tables
  WHERE table_schema = DATABASE() AND table_name = 'exf_ai_tool_call'
);
SET @column_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'exf_ai_tool_call'
    AND column_name = 'result_length_chars'
);
SET @sql = IF(@table_exists = 1 AND @column_exists = 0,
  'ALTER TABLE `exf_ai_tool_call` ADD `result_length_chars` int NULL AFTER `result`',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(@table_exists = 1,
  'UPDATE `exf_ai_tool_call` SET `result_length_chars` = CHAR_LENGTH(`result`) WHERE `result_length_chars` IS NULL',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- DOWN

-- Intentionally kept: dropping this column would discard stored data.