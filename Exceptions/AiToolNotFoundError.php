<?php
namespace axenox\GenAI\Exceptions;

use exface\Core\Exceptions\NotFoundError;

class AiToolNotFoundError extends NotFoundError
{
    private string $conversationId = '';

    public function setConversationId(string $conId) : AiToolNotFoundError
    {
        $this->conversationId = $conId;
        return $this;
    }

    public function getConversationId() : string
    {
        return $this->conversationId;
    }
}