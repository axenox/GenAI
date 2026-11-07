<?php
namespace axenox\GenAI\Common\Mcp;

use axenox\GenAI\Common\AiToolCall;
use axenox\GenAI\Common\AiToolResultString;
use axenox\GenAI\Exceptions\AiToolConfigurationWarning;
use axenox\GenAI\Factories\AiFactory;
use Mcp\Event\ErrorEvent;
use Mcp\Event\RequestEvent;
use Mcp\Event\ResponseEvent;
use Mcp\Schema\Request\CallToolRequest;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Commits requests before SDK invocation and records observable SDK rejections.
 */
final class McpConversationObserver implements EventDispatcherInterface
{
    public function __construct(
        private string $endpoint,
        private string $connectionId,
        private McpRequestBridge $bridge,
        private int $timeoutSeconds = 300,
        private string $groupingScope = 'session'
    ) {
        McpRequestContext::validateGrouping($timeoutSeconds, $groupingScope);
    }

    public function dispatch(object $event) : object
    {
        if (!$event instanceof RequestEvent && !$event instanceof ResponseEvent && !$event instanceof ErrorEvent) {
            return $event;
        }
        $request = $event->getRequest();
        if (!$request instanceof CallToolRequest) {
            return $event;
        }
        if ($event instanceof RequestEvent) {
            $this->receive($event);
        } else {
            try {
                $operation = $this->bridge->get($request);
                if ($operation !== null && !($operation['handled'] ?? false) && !($operation['blocked'] ?? false)) {
                    $this->reject($event, $operation);
                }
            } finally {
                $this->bridge->remove($request);
            }
        }
        return $event;
    }

    private function receive(RequestEvent $event) : void
    {
        $request = $event->getRequest();
        $workbench = null;
        $conversation = null;
        try {
            $workbench = McpWorkbenchScope::start();
            $handler = AiFactory::createTaskHandlerFromString($workbench, $this->endpoint);
            $context = McpRequestContext::create(
                $request, $this->endpoint, $this->connectionId, $event->getSession()->getId()->toRfc4122()
            );
            $conversation = AiFactory::resolveMcpConversation($handler, $context, $this->timeoutSeconds, $this->groupingScope);
            $callId = McpRequestContext::newId();
            $messageId = $conversation->saveToolCalls(
                [new AiToolCall($request->name, $callId, $request->arguments)],
                ['mcp' => $context]
            );
            $this->bridge->put($request, [
                'conversation_id' => $conversation->getConversationId(),
                'message_id' => $messageId,
                'call_id' => $callId,
                'blocked' => false,
                'handled' => false
            ]);
        } catch (\Throwable $error) {
            $this->bridge->put($request, ['blocked' => true]);
            $this->diagnose($workbench, $conversation, $error);
        } finally {
            if ($workbench !== null) {
                $workbench->stop();
            }
        }
    }

    private function reject(ResponseEvent|ErrorEvent $event, array $operation) : void
    {
        $workbench = null;
        $conversation = null;
        try {
            $workbench = McpWorkbenchScope::start();
            $handler = AiFactory::createTaskHandlerFromString($workbench, $this->endpoint);
            $conversation = AiFactory::createConversation($handler, $operation['conversation_id'], ['type' => 'mcp', 'devmode' => false]);
            $request = $event->getRequest();
            $call = new AiToolCall($request->name, $operation['call_id'], $request->arguments);
            $invalidUse = $event instanceof ErrorEvent
                && $event->getThrowable() === null
                && $event->getError()->code === -32602;
            $message = $invalidUse
                ? $event->getError()->message . ' Correct the tool name or arguments before retrying.'
                : 'The MCP request was rejected before tool execution.';
            $result = new AiToolResultString($workbench, '', null, [], [new AiToolConfigurationWarning($message)]);
            $result->setFailed(true);
            $recordId = $conversation->saveToolCallStart($operation['message_id'], $call);
            $conversation->saveToolCallEnd($recordId, $result, ['duration_ms' => 0]);
            $conversation->saveToolResults($operation['message_id'], [$operation['call_id'] => $result]);
            if (!$invalidUse && $event instanceof ErrorEvent) {
                $conversation->saveError($event->getThrowable() ?? new \RuntimeException('MCP request handling failed.'));
            }
        } catch (\Throwable $error) {
            // Logging must not replace the SDK's existing rejection response.
            $this->diagnose($workbench, $conversation, $error);
        } finally {
            if ($workbench !== null) {
                $workbench->stop();
            }
        }
    }

    private function diagnose($workbench, $conversation, \Throwable $error) : void
    {
        try {
            if ($conversation !== null) {
                $conversation->saveError($error);
            } elseif ($workbench !== null) {
                $workbench->getLogger()->logException($error);
            } else {
                error_log('MCP request logging failed before authentication.');
            }
        } catch (\Throwable $loggingError) {
            error_log('MCP request diagnostics could not be persisted.');
        }
    }
}
