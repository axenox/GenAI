<?php
namespace axenox\GenAI\Common;

use axenox\GenAI\Interfaces\AiToolCallInterface;
use JsonSerializable;

/**
 * Base class for AI tools
 */
class AiToolCall implements AiToolCallInterface, JsonSerializable
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

    public function jsonSerialize() {
        return get_object_vars($this);
    }
    
    public function getToolName() : string
    {
        return $this->toolName;
    }

    public function getCallId() : string
    {
        return $this->callId;
    }
    
    public function getArguments() : array
    {
        return $this->arguments;
    }
}
