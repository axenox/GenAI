<?php
namespace axenox\GenAI\Common;
use exface\Core\CommonLogic\Tasks\ResultMessage;
use axenox\GenAI\Interfaces\AiResponseInterface;
use exface\Core\Interfaces\Tasks\TaskInterface;

class AiResponse extends ResultMessage implements AiResponseInterface
{
    private $json = null;
    public function __construct(TaskInterface $prompt, array $json = [])
    {
        parent::__construct($prompt);
        $this->json = $json;
    }

    public function getChoices() : array
    {
        return $this->json['choices'];
    }

    public function toArray() : array
    {
        return $this->json ?? [];
    }
}