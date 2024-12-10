<?php
namespace axenox\GenAI\Common;
use exface\Core\CommonLogic\Tasks\HttpTask;
use axenox\GenAI\Interfaces\AiPromptInterface;

class AiPrompt extends HttpTask implements AiPromptInterface
{
    public function getMessages() : array
    {
        $params = $this->getParameters();
        return ($params['messages'] ?? $params['prompt']) ?? [];
    }

    public function getUserPrompt() : string
    {
        return implode(PHP_EOL, $this->getUserMessages());
    }

    /**
     * 
     * @see \axenox\GenAI\Interfaces\AiPromptInterface::getConversationUid()
     */
    public function getConversationUid() : ?string
    {
        $params = $this->getParameters();
        return ($params['conversation']);
    }

    public function getUserMessages() : array
    {
        $array = array_filter($this->getMessages(), function($msg) {
            if (is_string($msg)) {
                return true;
            } else {
                return $msg['role'] === 'user';
            }
        });
        $result = [];
        foreach ($array as $msg) {
            $result[] = $msg['text'];
        }
        return $result;
    }

    public function getSystemMessages() : array
    {
        return array_filter($this->getMessages(), function($msg) {
            if (is_string($msg)) {
                return false;
            } else {
                return $msg['role'] === 'system';
            }
        });
    }
}