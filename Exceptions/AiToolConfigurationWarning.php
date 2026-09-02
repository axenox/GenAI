<?php
namespace axenox\GenAI\Exceptions;

use exface\Core\Exceptions\RuntimeException;
use exface\Core\Interfaces\Log\LoggerInterface;

/**
 * Warning raised when configured AI tool definitions collide.
 */
class AiToolConfigurationWarning extends RuntimeException
{
    /**
     * {@inheritDoc}
     */
    public function getDefaultLogLevel()
    {
        return LoggerInterface::WARNING;
    }
}