<?php
namespace axenox\GenAI\Exceptions;

use axenox\GenAI\Interfaces\AiPromptInterface;
use axenox\GenAI\Interfaces\AiConceptInterface;
use exface\Core\Exceptions\RuntimeException;

class AiConceptRuntimeError extends RuntimeException
{
    private AiConceptInterface $concept;
    private AiPromptInterface $prompt;
    
    public function __construct(AiConceptInterface $concept, AiPromptInterface $prompt, string $message, string $alias, \Throwable $previous = null)
    {
        parent::__construct($message, $alias, $previous);
        $this->concept = $concept;
        $this->prompt = $prompt;
    }
    
    public function getTool(): AiConceptInterface
    {
        return $this->concept;
    }
    
    public function getPrompt(): AiPromptInterface
    {
        return $this->prompt;
    }
}