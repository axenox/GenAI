<?php
namespace axenox\GenAI\DataTypes;

use exface\Core\CommonLogic\DataTypes\EnumStaticDataTypeTrait;
use exface\Core\DataTypes\StringDataType;
use exface\Core\Interfaces\DataTypes\EnumDataTypeInterface;

/**
 * Provider-independent reasons, why an LLM stopped generating its response.
 * 
 * Every response adapter maps the native finish reasons or states of its API to these values.
 * Conversations persist them as-is, so agents, monitoring and designers never need to know
 * API-specific values.
 * 
 * - `completed` - the answer is complete
 * - `tool_calls` - the LLM requested tool calls and waits for their results
 * - `truncated` - the answer is unfinished, e.g. because of a token limit or a paused turn.
 * The produced text is kept.
 * - `refused` - the LLM refused to answer (e.g. content filter)
 * - `error` - the response is unusable or the API reported an unknown state
 * 
 * @method static AiFinishReasonDataType COMPLETED(\exface\Core\CommonLogic\Workbench $workbench)
 * @method static AiFinishReasonDataType TOOL_CALLS(\exface\Core\CommonLogic\Workbench $workbench)
 * @method static AiFinishReasonDataType TRUNCATED(\exface\Core\CommonLogic\Workbench $workbench)
 * @method static AiFinishReasonDataType REFUSED(\exface\Core\CommonLogic\Workbench $workbench)
 * @method static AiFinishReasonDataType ERROR(\exface\Core\CommonLogic\Workbench $workbench)
 * 
 * @author Andrej Kabachnik
 */
class AiFinishReasonDataType extends StringDataType implements EnumDataTypeInterface
{
    use EnumStaticDataTypeTrait;

    const COMPLETED = 'completed';
    const TOOL_CALLS = 'tool_calls';
    const TRUNCATED = 'truncated';
    const REFUSED = 'refused';
    const ERROR = 'error';

    /**
     * {@inheritDoc}
     * @see \exface\Core\Interfaces\DataTypes\EnumDataTypeInterface::getLabels()
     */
    public function getLabels()
    {
        return [
            self::COMPLETED => 'Completed',
            self::TOOL_CALLS => 'Tool calls',
            self::TRUNCATED => 'Truncated',
            self::REFUSED => 'Refused',
            self::ERROR => 'Error'
        ];
    }
}
