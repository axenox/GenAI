<?php
namespace axenox\GenAI\Common;
use exface\Core\CommonLogic\Tasks\ResultMessage;
use axenox\GenAI\Interfaces\AiResponseInterface;
use exface\Core\Interfaces\Tasks\TaskInterface;

class AiResponse extends ResultMessage implements AiResponseInterface
{
    private $message = null;
    private $conversationId = null;
    public function __construct(TaskInterface $prompt, string $answer = null, ?string $conversationId = null)
    {
        parent::__construct($prompt);
        $this->message = $answer;
        $this->conversationId = $conversationId;
    }

    public function toArray() : array
    {
        return $this->message ?? [];
    }

    /**
     * {@inheritDoc}
     * @see \exface\Core\CommonLogic\Tasks\ResultMessage::getMessage()
     */
    public function getMessage() : string 
    {
        return $this->message;
    }
    
    public function getConversationId() : string 
    {
        return $this->conversationId;
    }
}