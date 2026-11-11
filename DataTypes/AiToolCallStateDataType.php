<?php
namespace axenox\GenAI\DataTypes;

use exface\Core\CommonLogic\DataTypes\EnumStaticDataTypeTrait;
use exface\Core\DataTypes\StringDataType;
use exface\Core\Interfaces\DataTypes\EnumDataTypeInterface;

/**
 * Inspect the recorded execution outcome of an individual AI tool call.
 *
 * In progress means completion was not recorded. It does not prove that the
 * tool is still running, failed, or can safely be invoked again.
 * 
 * ```json
 * {"alias": "\\axenox\\GenAI\\DataTypes\\AiToolCallStateDataType"}
 * 
 * ```
 * 
 * @author GitHub Copilot
 */
class AiToolCallStateDataType extends StringDataType implements EnumDataTypeInterface
{
    use EnumStaticDataTypeTrait;

    const IN_PROGRESS = 'IN_PROGRESS';
    const SUCCEEDED = 'SUCCEEDED';
    const FAILED = 'FAILED';

    /**
     * Returns designer-facing labels for the recorded execution outcomes.
     * 
     * @return array<string,string>
     */
    public function getLabels()
    {
        return [
            self::IN_PROGRESS => 'Completion not recorded',
            self::SUCCEEDED => 'Succeeded',
            self::FAILED => 'Failed'
        ];
    }
}
