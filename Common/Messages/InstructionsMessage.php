<?php
namespace axenox\GenAI\Common\Messages;

use axenox\GenAI\DataTypes\AiMessageTypeDataType;

/**
 * Instructions for the model - e.g. the system prompt of an agent
 * 
 * API adapters send instructions in the way their API expects it: e.g. as `developer` messages
 * or top-level `instructions` for OpenAI or as top-level `system` for Claude.
 * 
 * @author Andrej Kabachnik
 */
class InstructionsMessage extends AbstractAiMessage
{
    /**
     * @param string $text
     * @param array<string,array<array-key,mixed>> $adapterData
     * @param string $type AiMessageTypeDataType value for subclasses with other instruction types
     */
    public function __construct(string $text, array $adapterData = [], string $type = AiMessageTypeDataType::SYSTEM)
    {
        parent::__construct($type, $text, [], $adapterData);
    }

    /**
     * {@inheritDoc}
     * @see AbstractAiMessage::isInstructions()
     */
    public function isInstructions() : bool
    {
        return true;
    }
}
