<?php
namespace axenox\GenAI\Exceptions;

use exface\Core\Exceptions\RuntimeException;
use exface\Core\Interfaces\Log\LoggerInterface;

/**
 * Exception thrown in AI tools on regular errors - non-critical for the conversation, but visible to the UI as errors.
 * 
 * Typical examples: file or any other asset not found, invalid argument, etc. These errors only affect one particular
 * tool call. The LLM can still use this tool with other arguments.
 * 
 * @author Andrej Kabachnik
 */
class AiToolRuntimeError extends AiToolCriticalError
{
    /**
     * {@inheritDoc}
     * @see RuntimeException::getDefaultLogLevel()
     */
    public function getDefaultLogLevel()
    {
        return LoggerInterface::ERROR;
    }
}