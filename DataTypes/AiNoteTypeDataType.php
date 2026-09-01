<?php
namespace axenox\GenAI\DataTypes;

use exface\Core\CommonLogic\DataTypes\EnumStaticDataTypeTrait;
use exface\Core\DataTypes\StringDataType;
use exface\Core\Interfaces\DataTypes\EnumDataTypeInterface;

/**
 * Enumeration for AI note types
 * 
 * // TODO
 * @method AiNoteTypeDataType MEMORY(\exface\Core\CommonLogic\Workbench $workbench)
 * @method AiNoteTypeDataType SUGGESTION(\exface\Core\CommonLogic\Workbench $workbench)
 * 
 * @author Andrej Kabachnik
 *
 */
class AiNoteTypeDataType extends StringDataType implements EnumDataTypeInterface
{
    use EnumStaticDataTypeTrait;
    
    const MEMORY = "memory";
    const SUGGESTION = "suggestion";
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\Interfaces\DataTypes\EnumDataTypeInterface::getLabels()
     */
    public function getLabels()
    {
        $translator = $this->getWorkbench()->getCoreApp()->getTranslator();
        return [
            self::MEMORY => 'Memory',
            self::SUGGESTION => 'Suggestion'
        ];
    }
}