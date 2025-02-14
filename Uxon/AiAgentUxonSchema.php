<?php
namespace axenox\GenAI\Uxon;

use axenox\GenAI\AI\Agents\GenericAssistant;
use exface\Core\Uxon\UxonSchema;

/**
 * UXON-schema class for AI tools/functions
 *
 * @see UxonSchema for general information.
 *
 * @author Andrej Kabachnik
 *
 */
class AiAgentUxonSchema extends UxonSchema
{
    public static function getSchemaName() : string
    {
        return 'AI agent';
    }
    
    /**
     *
     * {@inheritdoc}
     * @see UxonSchemaInterface::getPropertiesTemplates()
     */
    public function getPropertiesTemplates(string $prototypeClass) : array
    {
        $tpls = parent::getPropertiesTemplates($prototypeClass);
        // TODO generate templates from tool classes here
        return $tpls;
    }
    
    /**
     *
     * {@inheritDoc}
     * @see \exface\Core\Uxon\UxonSchema::getDefaultPrototypeClass()
     */
    protected function getDefaultPrototypeClass() : string
    {
        return '\\' . GenericAssistant::class;
    }
}