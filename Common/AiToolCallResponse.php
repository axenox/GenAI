<?php
namespace axenox\GenAI\Common;

use axenox\GenAI\Interfaces\AiToolResultInterface;
use JsonSerializable;

/**
 * 
 */
class AiToolCallResponse implements JsonSerializable
{
    private $toolName = null;
    private $callId = null;
    private $arguments = [];
    private $toolResult = null;

    public function __construct(string $toolName, string $callId, array $arguments, AiToolResultInterface $toolResult)
    {
        $this->toolName = $toolName;
        $this->callId = $callId;
        $this->arguments = $arguments;
        $this->toolResult = $toolResult;
    }

    public function jsonSerialize() {
        return get_object_vars($this);
    }

    public function getCallId(): string {
        return $this->callId;
    }

    public function getArguments(): array {
        return $this->arguments;
    }

    public function getToolName(): string {
        return $this->toolName;
    }

    public function getToolResult(): AiToolResultInterface
    {
        return $this->toolResult;
    }
}