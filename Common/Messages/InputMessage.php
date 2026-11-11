<?php
namespace axenox\GenAI\Common\Messages;

use axenox\GenAI\DataTypes\AiMessageTypeDataType;
use exface\Core\Interfaces\Filesystem\FileInfoInterface;

/**
 * Something the user said - e.g. a user prompt with optional attachments
 * 
 * @author Andrej Kabachnik
 */
class InputMessage extends AbstractAiMessage
{
    /**
     * @param string $text
     * @param FileInfoInterface[] $attachments
     * @param array<string,array<array-key,mixed>> $adapterData
     * @param string $type AiMessageTypeDataType value for subclasses with other input types
     */
    public function __construct(string $text, array $attachments = [], array $adapterData = [], string $type = AiMessageTypeDataType::USER)
    {
        parent::__construct($type, $text, $attachments, $adapterData);
    }

    /**
     * {@inheritDoc}
     * @see AbstractAiMessage::isInput()
     */
    public function isInput() : bool
    {
        return true;
    }
}
