<?php
namespace axenox\GenAI\Common;

use axenox\GenAI\Interfaces\AiToolCallInterface;
use exface\Core\DataTypes\StringDataType;
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
    
    public function __toString(): string
    {
        $args = [];
        $delim = ', ';
        foreach ($this->getArguments() as $arg) {
            switch (true) {
                case $arg instanceof \stdClass:
                case is_array($arg): 
                    $args[] = StringDataType::indent(json_encode($arg, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), "\t");
                    $delim = ",\n\t";
                    break;
                case is_bool($arg):
                    $args[] = $arg ? 'true' : 'false';
                    break;
                default:
                    $args[] = $arg;
                    break;
            }
        }
        return $this->getToolName() . '(' . implode($delim, $args) . ')';
    }
}