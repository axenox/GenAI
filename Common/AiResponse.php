<?php
namespace axenox\GenAI\Common;
use exface\Core\CommonLogic\Tasks\ResultMessage;
use axenox\GenAI\Interfaces\AiResponseInterface;
use exface\Core\Interfaces\Tasks\TaskInterface;

class AiResponse extends ResultMessage implements AiResponseInterface
{
    private $message = null;
    public function __construct(TaskInterface $prompt, string $answer = null)
    {
        parent::__construct($prompt);
        $this->message['Message'] = $answer;
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
        return $this->message['Message'];
    }
}