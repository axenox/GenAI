<?php
namespace axenox\GenAI\Tests\Unit\Mcp;

use axenox\GenAI\Common\Mcp\AiMcpTask;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\CommonLogic\Workbench;
use exface\Core\Exceptions\UxonMapError;
use PHPUnit\Framework\TestCase;

/**
 * Verifies MCP task UXON conversion without starting a Workbench.
 */
class AiMcpTaskTest extends TestCase
{
    /**
     * Optional invocation metadata is absent unless configured.
     * 
     * @return void
     */
    public function testDefaults() : void
    {
        $task = new AiMcpTask(new Workbench(), 'app.endpoint:1.0');

        $this->assertSame('app.endpoint:1.0', $task->getEndpointSelector());
        $this->assertNull($task->getConversationId());
        $this->assertNull($task->getMessageId());
        $this->assertNull($task->getCallId());
        $this->assertNull($task->getMcpSessionId());
        $this->assertNull($task->getMcpRequestId());
        $this->assertNull($task->getMcpChatId());
        $this->assertSame(['endpoint_selector' => 'app.endpoint:1.0'], $task->exportUxonObject()->toArray());
    }

    /**
     * All MCP properties and generic task parameters survive export and import.
     * 
     * @return void
     */
    public function testUxonRoundTrip() : void
    {
        $workbench = new Workbench();
        $configuration = [
            'endpoint_selector' => 'app.endpoint:2.0',
            'conversation_id' => 'conversation',
            'message_id' => 'message',
            'call_id' => 'execution',
            'mcp_session_id' => 'session',
            'mcp_request_id' => '7',
            'mcp_chat_id' => 'chat',
            'parameters' => ['example' => 'value']
        ];
        $task = new AiMcpTask($workbench, 'app.endpoint:1.0', new UxonObject($configuration));
        $restored = new AiMcpTask($workbench, 'app.endpoint:1.0', $task->exportUxonObject());

        $this->assertSame('app.endpoint:2.0', $restored->getEndpointSelector());
        $this->assertSame('conversation', $restored->getConversationId());
        $this->assertSame('message', $restored->getMessageId());
        $this->assertSame('execution', $restored->getCallId());
        $this->assertSame('session', $restored->getMcpSessionId());
        $this->assertSame('7', $restored->getMcpRequestId());
        $this->assertSame('chat', $restored->getMcpChatId());
        $this->assertSame('value', $restored->getParameter('example'));
        $this->assertEquals($configuration, $restored->exportUxonObject()->toArray());
    }

    /**
     * Numeric-looking strings, integers, zero and null retain their request ID types.
     * 
     * @return void
     */
    public function testRequestIdTypes() : void
    {
        $workbench = new Workbench();
        foreach ([7, '7', 0, '0', null] as $id) {
            $task = new AiMcpTask($workbench, 'app.endpoint:1.0', new UxonObject(['mcp_request_id' => $id]));
            $restored = new AiMcpTask($workbench, 'app.endpoint:1.0', $task->exportUxonObject());

            $this->assertSame($id, $task->getMcpRequestId());
            $this->assertSame($id, $restored->getMcpRequestId());
        }
    }

    /**
     * Reimporting explicit null clears optional invocation metadata.
     * 
     * @return void
     */
    public function testOptionalMetadataCanBeCleared() : void
    {
        $task = new AiMcpTask(new Workbench(), 'app.endpoint:1.0', new UxonObject([
            'conversation_id' => 'conversation',
            'message_id' => 'message',
            'call_id' => 'execution',
            'mcp_session_id' => 'session',
            'mcp_request_id' => 7,
            'mcp_chat_id' => 'chat'
        ]));
        $task->importUxonObject(new UxonObject([
            'conversation_id' => null,
            'message_id' => null,
            'call_id' => null,
            'mcp_session_id' => null,
            'mcp_request_id' => null,
            'mcp_chat_id' => null
        ]));

        $this->assertSame(['endpoint_selector' => 'app.endpoint:1.0'], $task->exportUxonObject()->toArray());
    }

    /**
     * Unknown bridge fields cannot silently become task properties.
     * 
     * @return void
     */
    public function testUnknownPropertiesAreRejected() : void
    {
        $this->expectException(UxonMapError::class);
        new AiMcpTask(new Workbench(), 'app.endpoint:1.0', new UxonObject(['unknown_context' => true]));
    }

    /**
     * Structured values are rejected for scalar request IDs.
     * 
     * @return void
     */
    public function testInvalidRequestIdIsRejected() : void
    {
        $this->expectException(\TypeError::class);
        new AiMcpTask(new Workbench(), 'app.endpoint:1.0', new UxonObject(['mcp_request_id' => ['invalid']]));
    }
}
