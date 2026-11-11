<?php
namespace axenox\GenAI\DataTypes;

use exface\Core\CommonLogic\DataTypes\EnumStaticDataTypeTrait;
use exface\Core\DataTypes\StringDataType;
use exface\Core\Interfaces\DataTypes\EnumDataTypeInterface;

/**
 * Types of messages in an AI conversation
 * 
 * The type is meant for designers, filtering and persistence. API adapters never use it: each
 * message tells them its context role via `isInstructions()`, `isInput()` and `isOutput()`.
 * Adding a type therefore requires a constant and a label here and a message class, that the
 * conversation creates for it - but no changes to API adapters.
 * 
 * Current types and their context roles:
 * 
 * - `system` - instructions
 * - `user` - input
 * - `assistant` and `tool` - output
 * - `warning` and `error` - designers only, never sent to an LLM
 * 
 * @method static AiMessageTypeDataType SYSTEM(\exface\Core\CommonLogic\Workbench $workbench)
 * @method static AiMessageTypeDataType USER(\exface\Core\CommonLogic\Workbench $workbench)
 * @method static AiMessageTypeDataType ASSISTANT(\exface\Core\CommonLogic\Workbench $workbench)
 * @method static AiMessageTypeDataType TOOL(\exface\Core\CommonLogic\Workbench $workbench)
 * @method static AiMessageTypeDataType WARNING(\exface\Core\CommonLogic\Workbench $workbench)
 * @method static AiMessageTypeDataType ERROR(\exface\Core\CommonLogic\Workbench $workbench)
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
    const TOOL = "tool";
    const WARNING = "warning";
    const ERROR = "error";
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\Interfaces\DataTypes\EnumDataTypeInterface::getLabels()
     */
    public function getLabels()
    {
        return [
            self::SYSTEM => 'System',
            self::USER => 'User',
            self::ASSISTANT => 'Assistant',
            self::TOOL => 'Tools',
            self::WARNING => 'Warning',
            self::ERROR => 'Error'
        ];
    }
}
