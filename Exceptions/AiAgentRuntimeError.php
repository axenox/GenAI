<?php
namespace axenox\GenAI\Exceptions;

use axenox\GenAI\Interfaces\AiAgentInterface;
use exface\Core\Exceptions\RuntimeException;

class AiAgentRuntimeError extends RuntimeException
{
    private AiAgentInterface $agent;
    
    public function __construct(AiAgentInterface $agent, string $message, string $alias, \Throwable $previous = null)
    {
        parent::__construct($message, $alias, $previous);
        $this->agent = $agent;
    }
    
    public function getAgent(): AiAgentInterface
    {
        return $this->agent;
    }
}