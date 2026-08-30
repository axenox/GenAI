/*
 * Add note type to exf_ai_note
 *
 * Existing notes are classified as memories.
 *
 * @author GitHub Copilot
 */
-- UP

ALTER TABLE dbo.exf_ai_note
    ADD type nvarchar(20) NOT NULL DEFAULT 'memory';

-- DOWN

-- Preserve note classifications for possible recovery.
EXEC sp_rename 'dbo.exf_ai_note.type', 'trash_type', 'COLUMN';