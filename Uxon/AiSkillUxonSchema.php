<?php
namespace axenox\GenAI\Uxon;

use axenox\GenAI\AI\Skills\GenericSkill;
use exface\Core\Uxon\UxonSchema;

/**
 * UXON schema for persisted AI skill configurations.
 */
class AiSkillUxonSchema extends UxonSchema
{
    /**
     * Returns the schema's display name.
     */
    public static function getSchemaName() : string
    {
        return 'AI skill';
    }

    /**
     * Returns the standard configurable skill prototype.
     */
    protected function getDefaultPrototypeClass() : string
    {
        return '\\' . GenericSkill::class;
    }
}