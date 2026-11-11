<?php
namespace axenox\GenAI\Common\Mcp;

use axenox\GenAI\AI\Agents\McpServer;
use axenox\GenAI\Common\AiToolCall;
use axenox\GenAI\DataTypes\AiConversationTypeDataType;
use axenox\GenAI\Factories\AiFactory;
use Mcp\Event\ErrorEvent;
use Mcp\Event\RequestEvent;
use Mcp\Event\ResponseEvent;
use Mcp\Schema\Request\CallToolRequest;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Persists SDK-visible attempts before validation, with no long-lived Workbench references.
 * 
 * @author GitHub Copilot
 */
class McpConversationEvents implements EventDispatcherInterface
{
    /**
     * Pins exact endpoint and transport identities without retaining a Workbench.
     * 
     * @param string $endpointSelector
     * @param string $connectionId
     * @param McpRequestBridge $bridge
     * @return void
     */
    public function __construct(
        private string $endpointSelector,
        private string $connectionId,
        private McpRequestBridge $bridge
    ) {
    }

    /**
     * Records observable call attempts and completes SDK-level rejections.
     * 
     * @param object $event
     * @return object
     */
    public function dispatch(object $event) : object
    {
        if ($event instanceof RequestEvent && $event->getRequest() instanceof CallToolRequest) {
            $this->start($event);
        } elseif (($event instanceof ResponseEvent || $event instanceof ErrorEvent)
            && $event->getRequest() instanceof CallToolRequest) {
            $this->finish($event);
        }
        return $event;
    }

    /**
     * Authorizes and commits a batch before handing control to SDK validation.
     * 
     * @throws \UnexpectedValueException
     * @param RequestEvent $event
     * @return void
     */
    private function start(RequestEvent $event) : void
    {
        $request = $event->getRequest();
        $nativeSession = $event->getSession()->getId()->toRfc4122();
        $meta = $request->getMeta() ?? [];
        $revision = $meta['io.modelcontextprotocol/protocolVersion'] ?? '';
        $modern = is_string($revision) && strcmp($revision, '2026-07-28') >= 0;
        $chat = $meta['axenox.GenAI/chat_id'] ?? null;
        $chat = is_string($chat) && trim($chat) !== '' ? $chat : null;
        $context = [
            'blocked' => true,
            'handled' => false,
            'session_id' => $modern ? $this->connectionId : $nativeSession,
            'native_session_id' => $nativeSession,
            'chat_id' => $chat,
            'request_id' => $request->getId(),
            'call_id' => Uuid::v4()->toRfc4122(),
            'tool_name' => $request->name,
            'arguments' => $request->arguments,
            'received_at' => microtime(true),
            'started_on' => date('Y-m-d H:i:s'),
            'monotonic_start' => hrtime(true)
        ];
        $key = $this->bridge->key($request, $event->getSession());
        $this->bridge->put($key, $context);
        $workbench = null;
        try {
            $workbench = McpWorkbenchScope::start();
            $handler = AiFactory::createTaskHandlerFromString($workbench, $this->endpointSelector);
            if (!$handler instanceof McpServer) {
                throw new \UnexpectedValueException('MCP logging requires an McpServer endpoint.');
            }
            $conversation = (new McpConversationResolver())->resolve($handler, $context);
            $context['conversation_id'] = $conversation->getConversationId();
            $context['message_id'] = $conversation->saveToolCalls(AiFactory::createToolCallMessage('', [
                new AiToolCall($request->name, $context['call_id'], $request->arguments)
            ]), null, [
                'started_on' => $context['started_on'],
                'mcp' => [
                    'request_id' => $request->getId(), 'session_id' => $context['session_id'],
                    'native_session_id' => $nativeSession, 'chat_id' => $chat,
                    'argument_fidelity' => 'sdk-visible'
                ]
            ])->getUid();
            $context['blocked'] = false;
        } catch (\Throwable $error) {
            if (isset($conversation)) {
                $conversation->reportOperationalError($error);
            } elseif ($workbench !== null) {
                try {
                    $workbench->getLogger()->logException($error);
                } catch (\Throwable $ignored) {
                }
            }
        } finally {
            $this->bridge->put($key, $context);
            if ($workbench !== null) {
                McpWorkbenchScope::stop($workbench, $conversation ?? null);
            }
        }
    }

    /**
     * Completes only requests rejected before the configured tool handler ran.
     * 
     * @param ResponseEvent|ErrorEvent $event
     * @return void
     */
    private function finish(ResponseEvent|ErrorEvent $event) : void
    {
        $key = $this->bridge->key($event->getRequest(), $event->getSession());
        $context = $this->bridge->get($key);
        $workbench = null;
        try {
            if ($context === null || $context['blocked'] || $context['handled']) {
                return;
            }
            $workbench = McpWorkbenchScope::start();
            $handler = AiFactory::createTaskHandlerFromString($workbench, $this->endpointSelector);
            $conversation = AiFactory::createConversation($handler, $context['conversation_id'], ['TYPE' => AiConversationTypeDataType::MCP]);
            $call = new AiToolCall($context['tool_name'], $context['call_id'], $context['arguments']);
            $record = null;
            try {
                $record = $conversation->saveToolCallStart($context['message_id'], $call, ['started_on' => $context['started_on']]);
            } catch (\Throwable $error) {
                $conversation->reportOperationalError($error);
            }
            $invalid = $event instanceof ErrorEvent && $event->getError()->code === -32602;
            $warning = 'The MCP request was rejected. Check the tool name, advertised input schema and your access permissions.';
            if ($invalid) {
                // SDK schema/name errors contain no protected tool output.
                $warning = $event->getError()->message;
            }
            $result = AiToolMcpAdapter::createRejectedResult($workbench, $warning);
            $result->setDurationMs((hrtime(true) - $context['monotonic_start']) / 1000000);
            if (!$invalid) {
                $conversation->reportOperationalError(
                    $event instanceof ErrorEvent && $event->getThrowable() !== null
                        ? $event->getThrowable() : new \RuntimeException('MCP request failed before tool invocation.')
                );
            }
            $timing = ['finished_on' => date('Y-m-d H:i:s'), 'duration_ms' => $result->getDurationMs()];
            if ($record !== null) {
                try {
                    $conversation->saveToolCallEnd($record, $result, $timing);
                } catch (\Throwable $error) {
                    $conversation->reportOperationalError($error);
                }
            }
            try {
                $conversation->saveToolResults($context['message_id'], [$context['call_id'] => $result], $timing + [
                    'call_timing' => [$context['call_id'] => $timing + ['started_on' => $context['started_on']]]
                ]);
            } catch (\Throwable $error) {
                $conversation->reportOperationalError($error);
            }
        } catch (\Throwable $error) {
            if ($workbench !== null) {
                try {
                    $workbench->getLogger()->logException($error);
                } catch (\Throwable $ignored) {
                }
            }
        } finally {
            $this->bridge->remove($key);
            if ($workbench !== null) {
                McpWorkbenchScope::stop($workbench, $conversation ?? null);
            }
        }
    }
}
