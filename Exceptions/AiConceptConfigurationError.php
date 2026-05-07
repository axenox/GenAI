<?php
namespace axenox\GenAI\Exceptions;

use axenox\GenAI\Interfaces\AiPromptInterface;
use axenox\GenAI\Interfaces\AiConceptInterface;
use exface\Core\Exceptions\RuntimeException;

/**
 * Exception thrown if the UXON configuration for an AI concept is broken or incomplete
 * 
 * @author Andrej Kabachnik
 */
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