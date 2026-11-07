<?php
namespace axenox\GenAI\Tests\PHPUnit\Unit\Mcp;

use axenox\GenAI\Common\Mcp\McpPersistenceGuard;
use axenox\GenAI\Common\Mcp\McpRequestBridge;
use axenox\GenAI\Common\Mcp\McpRequestContext;
use axenox\GenAI\Common\Mcp\McpConversationObserver;
use axenox\GenAI\Common\Mcp\AiToolMcpAdapter;
use axenox\GenAI\Interfaces\AiToolInterface;
use axenox\GenAI\Interfaces\AiToolResultInterface;
use Mcp\Event\ErrorEvent;
use exface\Core\Interfaces\Actions\ServiceParameterInterface;
use Mcp\Capability\Registry;
use Mcp\Capability\Registry\ReferenceHandler;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Schema\Tool;
use Mcp\Server\Handler\Request\CallToolHandler;
use Mcp\Server\Session\Session;
use PHPUnit\Framework\TestCase;
use Mcp\Server\Session\InMemorySessionStore;

final class McpLoggingBoundaryTest extends TestCase
{
    private function request(string|int $id = 1, array $meta = [], array $arguments = [], string $name = 'search') : CallToolRequest
    {
        return (new CallToolRequest($name, $arguments))->withId($id)->withMeta($meta);
    }

    public function testRequestIdentityAndFibersDoNotShareReusedRpcIds() : void
    {
        $bridge = new McpRequestBridge();
        $first = $this->request(1);
        $second = $this->request(1);
        $typed = $this->request('1');
        $bridge->put($first, ['call_id' => 'first']);
        $bridge->put($second, ['call_id' => 'second']);
        $fiber = new \Fiber(function () use ($bridge, $first, $second) {
            self::assertSame('first', $bridge->get($first)['call_id']);
            \Fiber::suspend();
            self::assertSame('second', $bridge->get($second)['call_id']);
        });
        $fiber->start();
        self::assertNull($bridge->get($typed));
        $fiber->resume();
        $bridge->remove($first);
        self::assertNull($bridge->get($first));
        self::assertSame('second', $bridge->get($second)['call_id']);
    }

    public function testBridgeRejectsLiveObjects() : void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new McpRequestBridge())->put($this->request(), ['handler' => new \stdClass()]);
    }

    public function testModernSessionsUseStableConnectionAndPreserveNativeSessionAndTypedId() : void
    {
        $meta = ['io.modelcontextprotocol/protocolVersion' => '2026-07-28', 'axenox.GenAI/chat_id' => 'chat'];
        $context = McpRequestContext::create($this->request('1', $meta, ['nested' => []]), 'app.endpoint:1', 'connection', 'native-one');
        $next = McpRequestContext::create($this->request(1, $meta), 'app.endpoint:1', 'connection', 'native-two');
        self::assertSame('connection', $context['session_id']);
        self::assertSame($context['session_id'], $next['session_id']);
        self::assertSame('native-one', $context['native_session_id']);
        self::assertSame('chat', $context['chat_id']);
        self::assertSame('1', $context['request_id']);
        self::assertSame(1, $next['request_id']);
        self::assertSame(['nested' => []], $context['sdk_arguments']);
        self::assertSame('sdk-visible', $context['argument_fidelity']);
        self::assertFalse($context['devmode']);
    }

    public function testHandshakeUsesNativeSessionAndInvalidChatMetadataIsNotInferred() : void
    {
        foreach ([null, '', ' ', 1, [], true] as $chatId) {
            $context = McpRequestContext::create($this->request(1, [
                'axenox.GenAI/chat_id' => $chatId, 'progressToken' => 'not-a-chat'
            ]), 'endpoint', 'connection', 'handshake');
            self::assertSame('handshake', $context['session_id']);
            self::assertArrayNotHasKey('chat_id', $context);
        }
    }

    public function testGroupingConfigurationRejectsNonPositiveOrCoercedTimeoutsAndUnknownScopes() : void
    {
        foreach ([[0, 'session'], [-1, 'user'], ['300', 'session'], [300.0, 'session'], [300, 'chat']] as [$timeout, $scope]) {
            try {
                McpRequestContext::validateGrouping($timeout, $scope);
                self::fail('Invalid configuration was accepted.');
            } catch (\InvalidArgumentException $error) {
                self::assertNotSame('', $error->getMessage());
            }
        }
        McpRequestContext::validateGrouping(300, 'session');
        McpRequestContext::validateGrouping(1, 'user');
    }

    public function testFailedInitialPersistenceIsHandledBySdkErrorWithoutInvokingTool() : void
    {
        $request = $this->request('rpc');
        $bridge = new McpRequestBridge();
        $guard = new McpPersistenceGuard($bridge);
        self::assertFalse($guard->supports($request));
        $bridge->put($request, ['blocked' => true]);
        self::assertTrue($guard->supports($request));
        self::assertFalse($guard->supports($this->request('rpc')));
        $error = $guard->handle($request, new Session(new InMemorySessionStore()));
        self::assertSame(Error::INTERNAL_ERROR, $error->code);
        self::assertSame('rpc', $error->id);
    }

    public function testOfficialSdkRejectsUnknownToolsAndSchemaFailuresBeforeInvocation() : void
    {
        $registry = new Registry();
        $invoked = false;
        $registry->registerTool(new Tool(name: 'search', title: null, description: null, annotations: null, inputSchema: [
            'type' => 'object', 'required' => ['query'],
            'properties' => ['query' => ['type' => 'string']],
            'additionalProperties' => false
        ]), static function () use (&$invoked) { $invoked = true; });
        $handler = new CallToolHandler($registry, new ReferenceHandler());
        foreach ([$this->request(), $this->request(2, [], ['query' => 123]), $this->request(3, [], [], 'unknown')] as $request) {
            $error = $handler->handle($request, new Session(new InMemorySessionStore()));
            self::assertInstanceOf(Error::class, $error);
            self::assertSame(Error::INVALID_PARAMS, $error->code);
            self::assertSame($request->getId(), $error->id);
        }
        self::assertFalse($invoked);
    }

    public function testObserverDoesNotRewriteHandledOrBlockedSdkErrorsAndClearsCorrelation() : void
    {
        foreach ([['handled' => true], ['blocked' => true]] as $operation) {
            $request = $this->request();
            $bridge = new McpRequestBridge();
            $bridge->put($request, $operation);
            $error = Error::forInvalidParams('Official rejection', $request->getId());
            $event = new ErrorEvent($error, $request, new Session(new InMemorySessionStore()), null);
            $observer = new McpConversationObserver('endpoint', 'connection', $bridge);
            self::assertSame($event, $observer->dispatch($event));
            self::assertSame($error, $event->getError());
            self::assertNull($bridge->get($request));
        }
    }

    public function testRenderingConversionFailureDoesNotInvokeToolOrChangeResultState() : void
    {
        $tool = $this->createMock(AiToolInterface::class);
        $tool->expects($this->never())->method('invoke');
        $result = $this->createMock(AiToolResultInterface::class);
        $result->method('isFailed')->willReturn(false);
        $result->method('getExceptions')->willReturn([]);
        $result->method('getAppendix')->willReturn([]);
        $result->method('getValueWithMetadata')->willReturn("\xB1");
        $result->expects($this->never())->method('setFailed');
        try {
            (new AiToolMcpAdapter($tool))->renderResult($result);
            self::fail('Invalid UTF-8 must be rejected at the conversion boundary.');
        } catch (\JsonException $error) {
            self::assertSame(JSON_ERROR_UTF8, $error->getCode());
            self::assertFalse($result->isFailed());
        }
    }

    public function testEmptyRequiredArgumentIsAnInvalidUseRatherThanUnexpectedPlatformError() : void
    {
        $parameter = $this->createMock(ServiceParameterInterface::class);
        $parameter->method('getName')->willReturn('search_query');
        $parameter->method('isRequired')->willReturn(true);
        $parameter->expects($this->once())->method('parseValue')->with('')
            ->willThrowException(new \exface\Core\Exceptions\UnexpectedValueException('Service parameter cannot be empty.'));
        $tool = $this->createMock(AiToolInterface::class);
        $tool->method('getArguments')->willReturn([$parameter]);
        $tool->expects($this->never())->method('invoke');
        $this->expectException(\exface\Core\Exceptions\InvalidArgumentException::class);
        (new AiToolMcpAdapter($tool))->normalizeArguments(['search_query' => '']);
    }
}
