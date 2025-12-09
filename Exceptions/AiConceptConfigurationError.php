<?php
namespace axenox\GenAI\Exceptions;

use axenox\GenAI\Interfaces\AiPromptInterface;
use axenox\GenAI\Interfaces\AiConceptInterface;
use exface\Core\Exceptions\RuntimeException;

class AiConceptConfigurationError extends RuntimeException
{
    private AiConceptInterface $concept;
    
    public function __construct(AiConceptInterface $concept, string $message, string $alias, \Throwable $previous = null)
    {
        parent::__construct($message, $alias, $previous);
        $this->concept = $concept;
    }
    
    public function getTool(): AiConceptInterface
    {
        return $this->concept;
    }
}