<?php
namespace axenox\GenAI\Tests\Unit\Mcp;

use axenox\GenAI\AI\Agents\McpServer;
use axenox\GenAI\Common\Mcp\AiToolMcpAdapter;
use axenox\GenAI\Common\Mcp\McpCallToolHandler;
use axenox\GenAI\Common\Mcp\McpConversationResolver;
use axenox\GenAI\Common\Mcp\McpConversationEvents;
use axenox\GenAI\Common\Mcp\McpRequestBridge;
use axenox\GenAI\Interfaces\AiToolResultInterface;
use Mcp\Capability\Registry;
use Mcp\Capability\Registry\ReferenceHandler;
use Mcp\Event\ErrorEvent;
use Mcp\Event\ResponseEvent;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\Tool;
use Mcp\Server;
use Mcp\Server\ClientGateway;
use Mcp\Server\Handler\Request\CallToolHandler;
use Mcp\Server\Handler\ToolHandlerInterface;
use Mcp\Server\Session\InMemorySessionStore;
use Mcp\Server\Session\Session;
use PHPUnit\Framework\TestCase;

/**
 * Verifies MCP conversation grouping and operation-local correlation contracts.
 * 
 * @author GitHub Copilot
 */
class McpConversationContractTest extends TestCase
{
    /**
     * Verifies grouping separates user version scope session and explicit chat.
     * 
     * @return void
     */
    public function testGroupingSeparatesUserVersionScopeSessionAndExplicitChat() : void
    {
        $key = McpConversationResolver::groupKey('user', 'endpoint:1.0', 'session', 'session', null);
        $this->assertNotSame($key, McpConversationResolver::groupKey('other', 'endpoint:1.0', 'session', 'session', null));
        $this->assertNotSame($key, McpConversationResolver::groupKey('user', 'endpoint:2.0', 'session', 'session', null));
        $this->assertNotSame($key, McpConversationResolver::groupKey('user', 'endpoint:1.0', 'session', 'other', null));
        $this->assertNotSame($key, McpConversationResolver::groupKey('user', 'endpoint:1.0', 'session', 'session', 'chat'));
        $this->assertSame(
            McpConversationResolver::groupKey('user', 'endpoint:1.0', 'user', 'first', 'chat'),
            McpConversationResolver::groupKey('user', 'endpoint:1.0', 'user', 'second', 'chat')
        );
        $this->assertNotSame(
            McpConversationResolver::groupKey('user', 'endpoint:1.0', 'user', 'first', 'chat'),
            McpConversationResolver::groupKey('user', 'endpoint:1.0', 'user', 'first', 'other-chat')
        );
        $this->assertNotSame(
            McpConversationResolver::groupKey('a|b', 'c', 'user', 'first', null),
            McpConversationResolver::groupKey('a', 'b|c', 'user', 'first', null)
        );
    }

    /**
     * Verifies sliding window expires exactly at timeout but explicit chat does not.
     * 
     * @return void
     */
    public function testSlidingWindowExpiresExactlyAtTimeoutButExplicitChatDoesNot() : void
    {
        $this->assertTrue(McpConversationResolver::isReusable(['last_activity_at' => 1000], 1299.99, 300, null));
        $this->assertFalse(McpConversationResolver::isReusable(['last_activity_at' => 1000], 1300, 300, null));
        $this->assertTrue(McpConversationResolver::isReusable(['last_activity_at' => 1240], 1480, 300, null));
        $this->assertTrue(McpConversationResolver::isReusable(['last_activity_at' => 1000], 999999, 300, 'chat'));
    }

    /**
     * Verifies missing grouping activity is not silently reused.
     * 
     * @return void
     */
    public function testMissingGroupingActivityIsNotSilentlyReused() : void
    {
        $this->expectException(\UnexpectedValueException::class);
        McpConversationResolver::isReusable([], 1300, 300, null);
    }

    /**
     * Verifies configuration defaults and validation without workbench.
     * 
     * @return void
     */
    public function testConfigurationDefaultsAndValidationWithoutWorkbench() : void
    {
        $handler = (new \ReflectionClass(McpServer::class))->newInstanceWithoutConstructor();
        $this->assertSame(300, $handler->getConversationInactivityTimeoutSeconds());
        $this->assertSame('session', $handler->getConversationGroupingScope());
        $this->assertFalse($handler->getDevmode());
        $handler->setConversationInactivityTimeoutSeconds(60)->setConversationGroupingScope('user')->setDevmode(true);
        $this->assertSame(60, $handler->getConversationInactivityTimeoutSeconds());
        $this->assertSame('user', $handler->getConversationGroupingScope());
        $this->assertTrue($handler->getDevmode());
    }

    /**
     * Verifies invalid timeout is rejected.
     * 
     * @return void
     */
    public function testInvalidTimeoutIsRejected() : void
    {
        $handler = (new \ReflectionClass(McpServer::class))->newInstanceWithoutConstructor();
        $this->expectException(\exface\Core\Exceptions\InvalidArgumentException::class);
        $handler->setConversationInactivityTimeoutSeconds(0);
    }

    /**
     * Verifies invalid grouping scope is rejected.
     * 
     * @return void
     */
    public function testInvalidGroupingScopeIsRejected() : void
    {
        $handler = (new \ReflectionClass(McpServer::class))->newInstanceWithoutConstructor();
        $this->expectException(\exface\Core\Exceptions\InvalidArgumentException::class);
        $handler->setConversationGroupingScope('global');
    }

    /**
     * Verifies conversion uses durable diagnostics not transient exceptions.
     * 
     * @return void
     */
    public function testConversionUsesDurableDiagnosticsNotTransientExceptions() : void
    {
        $result = $this->createMock(AiToolResultInterface::class);
        $result->expects($this->never())->method('getExceptions');
        $result->method('getErrors')->willReturn(['error one', 'error two']);
        $result->method('getWarnings')->willReturn(['warning one']);
        $result->method('getAppendix')->willReturn(['instructions']);
        $result->method('getValueWithMetadata')->willReturn('retained output');
        $result->method('isFailed')->willReturn(true);
        $response = AiToolMcpAdapter::convertResult($result);
        $this->assertTrue($response->isError);
        $this->assertSame("retained output\n\ninstructions", $response->content[0]->text);
        $this->assertSame([
            ['level' => 'error', 'message' => 'error one'],
            ['level' => 'error', 'message' => 'error two'],
            ['level' => 'warning', 'message' => 'warning one']
        ], $response->meta['diagnostics']);
        $this->assertSame(['instructions'], $response->meta['appendix']);
    }

    /**
     * Verifies bridge keeps concurrent fibers and reused request ids separate.
     * 
     * @return void
     */
    public function testBridgeKeepsConcurrentFibersAndReusedRequestIdsSeparate() : void
    {
        $bridge = new McpRequestBridge();
        $session = new Session(new InMemorySessionStore());
        $first = (new CallToolRequest('tool', []))->withId(7);
        $second = (new CallToolRequest('tool', []))->withId(7);
        $typed = (new CallToolRequest('tool', []))->withId('7');
        $one = $bridge->key($first, $session);
        $two = $bridge->key($second, $session);
        $this->assertNotSame($one, $two);
        $this->assertNotSame($one, $bridge->key($typed, $session));
        $bridge->put($one, ['call_id' => 'one']);
        $bridge->put($two, ['call_id' => 'two']);
        $fiber = new \Fiber(function () use ($bridge, $one) {
            $bridge->bind($one);
            \Fiber::suspend($bridge->current()['call_id']);
            $this->assertSame('one', $bridge->current()['call_id']);
            $bridge->markHandled();
            $bridge->unbind();
        });
        $this->assertSame('one', $fiber->start());
        $other = new \Fiber(function () use ($bridge, $two) {
            $bridge->bind($two);
            $this->assertSame('two', $bridge->current()['call_id']);
            $bridge->unbind();
        });
        $other->start();
        $fiber->resume();
        $this->assertTrue($bridge->get($one)['handled']);
        $bridge->remove($one);
        $this->assertNull($bridge->get($one));
        $this->assertSame('two', $bridge->get($two)['call_id']);
        $this->expectException(\LogicException::class);
        $bridge->current();
    }

    /**
     * Verifies official sdk rejects unknown tool and schema before invocation.
     * 
     * @return void
     */
    public function testOfficialSdkRejectsUnknownToolAndSchemaBeforeInvocation() : void
    {
        $bridge = new McpRequestBridge();
        $registry = new Registry();
        $calls = new class implements ToolHandlerInterface {
            public int $count = 0;
            /**
             * Executes the SDK-visible tool request through the configured handler.
             * 
             * @param array<array-key,mixed> $arguments
             * @param ClientGateway $gateway
             * @return mixed
             */
            public function execute(array $arguments, ClientGateway $gateway) : mixed
            {
                $this->count++;
                return new CallToolResult([]);
            }
        };
        Server::builder()->setServerInfo('test', '1.0')->setRegistry($registry)->add(
            new Tool('tool', null, ['type' => 'object', 'properties' => ['x' => ['type' => 'integer']], 'required' => ['x']], null, null),
            $calls
        )->build();
        $handler = new McpCallToolHandler(new CallToolHandler($registry, new ReferenceHandler()), $bridge);
        $session = new Session(new InMemorySessionStore());
        foreach ([
            (new CallToolRequest('missing', []))->withId(1),
            (new CallToolRequest('tool', ['x' => 'wrong']))->withId(2)
        ] as $request) {
            $key = $bridge->key($request, $session);
            $bridge->put($key, ['blocked' => false]);
            $response = (new \Fiber(fn () => $handler->handle($request, $session)));
            $response->start();
            $this->assertInstanceOf(Error::class, $response->getReturn());
            $this->assertSame(-32602, $response->getReturn()->code);
            $bridge->remove($key);
        }
        $this->assertSame(0, $calls->count);
        $request = (new CallToolRequest('tool', ['x' => 1]))->withId(3);
        $key = $bridge->key($request, $session);
        $bridge->put($key, ['blocked' => true]);
        $this->assertSame(-32603, $handler->handle($request, $session)->code);
        $this->assertSame(0, $calls->count);
        $bridge->put($key, ['blocked' => false]);
        $this->assertInstanceOf(CallToolResult::class, $handler->handle($request, $session)->result);
        $this->assertSame(1, $calls->count);
    }

    /**
     * Verifies response events clean handled and blocked operations without opening workbench.
     * 
     * @return void
     */
    public function testResponseEventsCleanHandledAndBlockedOperationsWithoutOpeningWorkbench() : void
    {
        $bridge = new McpRequestBridge();
        $events = new McpConversationEvents('endpoint:1.0', 'connection', $bridge);
        $session = new Session(new InMemorySessionStore());
        $request = (new CallToolRequest('tool', []))->withId('00123');
        $key = $bridge->key($request, $session);
        $bridge->put($key, ['blocked' => false, 'handled' => true]);
        $event = new ResponseEvent(new Response('00123', new CallToolResult([])), $request, $session);
        $this->assertSame($event, $events->dispatch($event));
        $this->assertNull($bridge->get($key));
        $bridge->put($key, ['blocked' => true, 'handled' => false]);
        $error = new ErrorEvent(Error::forInternalError('Persistence unavailable', '00123'), $request, $session, null);
        $this->assertSame($error, $events->dispatch($error));
        $this->assertNull($bridge->get($key));
    }
}
