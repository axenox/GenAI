/*
 * Add note type to exf_ai_note
 *
 * Existing notes are classified as memories.
 *
 * @author GitHub Copilot
 */
-- UP

ALTER TABLE exf_ai_note
    ADD COLUMN type varchar(20) NOT NULL DEFAULT 'memory';

-- DOWN

-- Preserve note classifications for possible recovery.
ALTER TABLE exf_ai_note
    RENAME COLUMN type TO trash_type;