<?php
namespace axenox\GenAI\DataTypes;

use exface\Core\CommonLogic\DataTypes\EnumStaticDataTypeTrait;
use exface\Core\DataTypes\StringDataType;
use exface\Core\Interfaces\DataTypes\EnumDataTypeInterface;

/**
 * Enumeration for AI message types
 * 
 * // TODO
 * @method SortingDirectionsDataType ASC(\exface\Core\CommonLogic\Workbench $workbench)
 * @method SortingDirectionsDataType DESC(\exface\Core\CommonLogic\Workbench $workbench)
 * 
 * @author Andrej Kabachnik
 *
 */
class AiMessageTypeDataType extends StringDataType implements EnumDataTypeInterface
{
    use EnumStaticDataTypeTrait;
    
    const SYSTEM = "system";
    const USER = "user";
    const ASSISTANT = "assistant";

    
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\Interfaces\DataTypes\EnumDataTypeInterface::getLabels()
     */
    public function getLabels()
    {
        $translator = $this->getWorkbench()->getCoreApp()->getTranslator();
        return [
            self::SYSTEM => 'System',
            self::USER => 'User',
            self::ASSISTANT => 'Assistant'
        ];
    }
}