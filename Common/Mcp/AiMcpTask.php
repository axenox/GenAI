<?php
namespace axenox\GenAI\Common\Mcp;

use exface\Core\CommonLogic\Tasks\GenericTask;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\Interfaces\WorkbenchInterface;

/**
 * Carries MCP invocation context through the generic task contract used by AI tools.
 * 
 * Use UXON properties to associate a tool invocation with its conversation,
 * message, execution and MCP request. The MCP boundary supplies these properties
 * independently of client tool arguments. Generic task input remains available:
 * 
 * ```json
 * {
 *     "conversation_id": "0x1234",
 *     "call_id": "execution-uuid",
 *     "mcp_request_id": 7,
 *     "parameters": {"example": "value"}
 * }
 * 
 * ```
 * 
 * @author Andrej Kabachnik
 */
class AiMcpTask extends GenericTask
{
    private string $endpointSelector;

    private ?string $conversationId = null;

    private ?string $messageId = null;

    private ?string $callId = null;

    private ?string $mcpSessionId = null;

    private string|int|null $mcpRequestId = null;

    private ?string $mcpChatId = null;

    /**
     * Creates an MCP task for one capability operation.
     * 
     * @param WorkbenchInterface $workbench Workbench scope owned by the operation.
     * @param string $endpointSelector Selector of the MCP endpoint.
     * @param UxonObject|null $uxon Task configuration and MCP invocation context.
     * @return void
     */
    public function __construct(WorkbenchInterface $workbench, string $endpointSelector, ?UxonObject $uxon = null)
    {
        parent::__construct($workbench);
        $this->setEndpointSelector($endpointSelector);
        if ($uxon !== null) {
            $this->importUxonObject($uxon);
        }
    }

    /**
     * Returns the selector that owns the advertised capability.
     * 
     * @return string
     */
    public function getEndpointSelector() : string
    {
        return $this->endpointSelector;
    }

    /**
     * Select the versioned MCP endpoint that owns this operation.
     * 
     * @uxon-property endpoint_selector
     * @uxon-type string
     * 
     * @param string $selector
     * @return AiMcpTask
     */
    protected function setEndpointSelector(string $selector) : AiMcpTask
    {
        $this->endpointSelector = $selector;
        return $this;
    }

    /**
     * Returns the authorized conversation associated with this operation.
     * 
     * @return string|null
     */
    public function getConversationId() : ?string
    {
        return $this->conversationId;
    }

    /**
     * Associate this operation with an authorized conversation.
     * 
     * @uxon-property conversation_id
     * @uxon-type string
     * 
     * @param string|null $id
     * @return AiMcpTask
     */
    protected function setConversationId(?string $id) : AiMcpTask
    {
        $this->conversationId = $id;
        return $this;
    }

    /**
     * Returns the committed batch message identity.
     * 
     * @return string|null
     */
    public function getMessageId() : ?string
    {
        return $this->messageId;
    }

    /**
     * Identify the persisted tool message containing this operation.
     * 
     * @uxon-property message_id
     * @uxon-type string
     * 
     * @param string|null $id
     * @return AiMcpTask
     */
    protected function setMessageId(?string $id) : AiMcpTask
    {
        $this->messageId = $id;
        return $this;
    }

    /**
     * Returns the generated execution identity, independent of the client request ID.
     * 
     * @return string|null
     */
    public function getCallId() : ?string
    {
        return $this->callId;
    }

    /**
     * Identify this tool execution independently of the client's request ID.
     * 
     * @uxon-property call_id
     * @uxon-type string
     * 
     * @param string|null $id
     * @return AiMcpTask
     */
    protected function setCallId(?string $id) : AiMcpTask
    {
        $this->callId = $id;
        return $this;
    }

    /**
     * Returns the session grouping boundary selected by the MCP transport.
     * 
     * @return string|null
     */
    public function getMcpSessionId() : ?string
    {
        return $this->mcpSessionId;
    }

    /**
     * Identify the MCP session used to group operations into conversations.
     * 
     * @uxon-property mcp_session_id
     * @uxon-type string
     * 
     * @param string|null $id
     * @return AiMcpTask
     */
    protected function setMcpSessionId(?string $id) : AiMcpTask
    {
        $this->mcpSessionId = $id;
        return $this;
    }

    /**
     * Returns the original typed JSON-RPC request identifier.
     * 
     * @return string|int|null
     */
    public function getMcpRequestId() : string|int|null
    {
        return $this->mcpRequestId;
    }

    /**
     * Retain the original JSON-RPC request ID, including its string or integer type.
     * 
     * @uxon-property mcp_request_id
     * @uxon-type string|integer
     * 
     * @param string|int|null $id
     * @return AiMcpTask
     */
    protected function setMcpRequestId(string|int|null $id) : AiMcpTask
    {
        $this->mcpRequestId = $id;
        return $this;
    }

    /**
     * Returns the explicit client chat identifier, when supplied.
     * 
     * @return string|null
     */
    public function getMcpChatId() : ?string
    {
        return $this->mcpChatId;
    }

    /**
     * Identify the client's explicit chat, when supplied.
     * 
     * @uxon-property mcp_chat_id
     * @uxon-type string
     * 
     * @param string|null $id
     * @return AiMcpTask
     */
    protected function setMcpChatId(?string $id) : AiMcpTask
    {
        $this->mcpChatId = $id;
        return $this;
    }

    /**
     * {@inheritDoc}
     * @see GenericTask::exportUxonObject()
     */
    public function exportUxonObject() : UxonObject
    {
        $uxon = parent::exportUxonObject();
        $uxon->setProperty('endpoint_selector', $this->getEndpointSelector());
        foreach ([
            'conversation_id' => $this->getConversationId(),
            'message_id' => $this->getMessageId(),
            'call_id' => $this->getCallId(),
            'mcp_session_id' => $this->getMcpSessionId(),
            'mcp_request_id' => $this->getMcpRequestId(),
            'mcp_chat_id' => $this->getMcpChatId()
        ] as $property => $value) {
            if ($value !== null) {
                $uxon->setProperty($property, $value);
            }
        }
        return $uxon;
    }
}