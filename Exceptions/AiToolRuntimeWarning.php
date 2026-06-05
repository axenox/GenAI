<?php
namespace axenox\GenAI\Exceptions;

use exface\Core\Interfaces\Log\LoggerInterface;

/**
 * Exception thrown when an AI tool encounters a minor error, which still allow to return meaningful results to the LLM
 * 
 * @author Andrej Kabachnik
 */
class AiToolRuntimeWarning extends AiToolCriticalError
{
    /**
     * {@inheritDoc}
     * @see \exface\Core\Exceptions\RuntimeException::getDefaultLogLevel()
     */
    public function getDefaultLogLevel()
    {
        return LoggerInterface::WARNING;
    }
}