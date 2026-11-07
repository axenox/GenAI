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
    private ?string $rawArguments;
    private ?array $executionArguments;
    private ?string $toolAlias;

    public function __construct(string $toolName, string $callId, array $arguments, ?string $rawArguments = null, ?array $executionArguments = null, ?string $toolAlias = null)
    {
        $this->toolName = $toolName;
        $this->callId = $callId;
        $this->arguments = $arguments;
        $this->rawArguments = $rawArguments;
        $this->executionArguments = $executionArguments;
        $this->toolAlias = $toolAlias;
    }

    public function jsonSerialize() : array {
        return [
            'tool_name' => $this->toolName,
            'call_id' => $this->callId,
            'arguments' => (object) $this->arguments,
            'raw_arguments' => $this->rawArguments,
            'execution_arguments' => $this->executionArguments,
            'tool_alias' => $this->toolAlias
        ];
    }

    /** Returns original provider argument text, when actually available. */
    public function getRawArguments() : ?string { return $this->rawArguments; }

    /** Returns separately parsed positional arguments, when prepared by the caller. */
    public function getExecutionArguments() : ?array { return $this->executionArguments; }

    /** Returns the historical application alias without resolving a live tool. */
    public function getToolAlias() : ?string { return $this->toolAlias; }
    
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