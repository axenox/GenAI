<?php
namespace axenox\GenAI\DataTypes;

use exface\Core\CommonLogic\DataTypes\EnumStaticDataTypeTrait;
use exface\Core\DataTypes\StringDataType;
use exface\Core\Interfaces\DataTypes\EnumDataTypeInterface;

/**
 * Enumeration for AI conversation types.
 */
class AiConversationTypeDataType extends StringDataType implements EnumDataTypeInterface
{
    use EnumStaticDataTypeTrait;

    const AGENT = 'agent';
    const MCP = 'mcp';

    /**
     * {@inheritDoc}
     * @see EnumDataTypeInterface::getLabels()
     */
    public function getLabels()
    {
        return [
            self::AGENT => 'Agent',
            self::MCP => 'MCP'
        ];
    }
}
