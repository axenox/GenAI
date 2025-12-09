<?php
namespace axenox\GenAI\Exceptions;

use axenox\GenAI\Interfaces\AiPromptInterface;
use axenox\GenAI\Interfaces\AiToolInterface;
use exface\Core\Exceptions\RuntimeException;

class AiToolRuntimeError extends RuntimeException
{
    private AiToolInterface $tool;
    private AiPromptInterface $prompt;
    
    public function __construct(AiToolInterface $tool, AiPromptInterface $prompt, string $message, string $alias, \Throwable $previous = null)
    {
        parent::__construct($message, $alias, $previous);
        $this->tool = $tool;
        $this->prompt = $prompt;
    }
    
    public function getTool(): AiToolInterface
    {
        return $this->tool;
    }
    
    public function getPrompt(): AiPromptInterface
    {
        return $this->prompt;
    }
}