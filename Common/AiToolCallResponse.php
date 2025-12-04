<?php
namespace axenox\GenAI\Common;

use exface\Core\Factories\DataTypeFactory;
use exface\Core\Interfaces\DataTypes\DataTypeInterface;
use JsonSerializable;

/**
 * 
 */
class AiToolCallResponse implements JsonSerializable
{
    private $toolName = null;
    private $callId = null;
    private $arguments = [];
    private $toolResponse = null;
    private $dataTypeAlias = null;

    public function __construct(string $toolName, string $callId, array $arguments, string $toolResponse, string $dataTypeAlias)
    {
        $this->toolName = $toolName;
        $this->callId = $callId;
        $this->arguments = $arguments;
        $this->toolResponse = $toolResponse;
        $this->dataTypeAlias = $dataTypeAlias;
    }

    public function jsonSerialize() {
        return get_object_vars($this);
    }

    public function setCallId(string $callId): void {
        $this->callId = $callId;
    }

    public function getCallId(): string {
        return $this->callId;
    }

    public function setArguments(array $arguments): void {
        $this->parameters = $arguments;
    }

    public function getArguments(): array {
        return $this->arguments;
    }

    public function setToolName(string $toolName): void {
        $this->toolName = $toolName;
    }

    public function getToolName(): string {
        return $this->toolName;
    }

    public function setToolResponse(string $toolResponse): void {
        $this->toolResponse = $toolResponse;
    }

    public function getToolResponse(): string {
        return $this->toolResponse;
    }
    
    public function getDataTypeAlias(): string 
    {
        return $this->dataTypeAlias;    
    }
}