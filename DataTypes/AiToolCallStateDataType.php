<?php
namespace axenox\GenAI\DataTypes;

use exface\Core\CommonLogic\DataTypes\EnumStaticDataTypeTrait;
use exface\Core\DataTypes\StringDataType;
use exface\Core\Interfaces\DataTypes\EnumDataTypeInterface;

/**
 * Persisted execution state, not a guarantee that a process is still alive.
 */
class AiToolCallStateDataType extends StringDataType implements EnumDataTypeInterface
{
    use EnumStaticDataTypeTrait;

    const IN_PROGRESS = 'IN_PROGRESS';
    const SUCCEEDED = 'SUCCEEDED';
    const FAILED = 'FAILED';

    public function getLabels()
    {
        return [
            self::IN_PROGRESS => 'In progress',
            self::SUCCEEDED => 'Succeeded',
            self::FAILED => 'Failed'
        ];
    }
}
