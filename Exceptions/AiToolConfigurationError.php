<?php
namespace axenox\GenAI\Exceptions;

use axenox\GenAI\Interfaces\AiToolInterface;
use exface\Core\Exceptions\RuntimeException;

class AiToolConfigurationError extends RuntimeException
{
    private AiToolInterface $tool;
    
    public function __construct(AiToolInterface $tool, string $message, string $alias, \Throwable $previous = null)
    {
        parent::__construct($message, $alias, $previous);
        $this->tool = $tool;
    }
    
    public function getTool(): AiToolInterface
    {
        return $this->tool;
    }
}