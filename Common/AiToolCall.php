<?php
namespace axenox\GenAI\Common;

use axenox\GenAI\Interfaces\AiToolCallInterface;

/**
 * Base class for AI tools
 */
class AiToolCall implements AiToolCallInterface
{
    private $toolName = null;

    private $callId = null;

    private $arguments = [];

    public function __construct(string $toolName, string $callId, array $arguments)
    {
        $this->toolName = $toolName;
        $this->callId = $callId;
        $this->arguments = $arguments;
    }

    public function getToolName() : string
    {
        return $this->toolName;
    }

    public function getCallId() : string
    {
        return $this->callId;
    }
}
