<?php
namespace axenox\GenAI\Common\Mcp;

use axenox\GenAI\Common\Tasks\AiMcpTask;
use axenox\GenAI\Common\AiToolCall;
use axenox\GenAI\Common\AiToolExecutor;
use axenox\GenAI\Common\AiToolResultString;
use axenox\GenAI\Exceptions\AiToolConfigurationWarning;
use axenox\GenAI\Factories\AiFactory;
use exface\Core\Exceptions\Security\AccessDeniedError;
use exface\Core\Exceptions\DataTypes\DataTypeValidationError;
use exface\Core\Exceptions\InvalidArgumentException;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Server\ClientGateway;
use Mcp\Server\Handler\ToolHandlerInterface;

/**
 * Recreates and invokes one configured AI tool inside a fresh Workbench scope.
 */
class AiToolMcpHandler implements ToolHandlerInterface
{
    private string $agentSelector;

    private string $toolName;

    /**
     * Creates an immutable operation descriptor without retaining ExFace request state.
     */
    public function __construct(string $agentSelector, string $toolName, private ?McpRequestBridge $bridge = null)
    {
        $this->agentSelector = $agentSelector;
        $this->toolName = $toolName;
    }

    /**
     * Opens an isolated Workbench, recreates the configured tool and executes the MCP call.
     *
     * @param array<string, mixed> $arguments Named MCP arguments.
     */
    public function execute(array $arguments, ClientGateway $gateway): mixed
    {
        throw new ToolCallException('MCP logging requires the SDK request context. No tool was invoked.');
    }

    /**
     * Uses the SDK's public RequestContext identity, including across concurrent Fibers.
     */
    public function executeRequest(array $arguments, ClientGateway $gateway, Request $request): mixed
    {
        $operation = $this->bridge?->get($request);
        if ($operation === null || ($operation['blocked'] ?? false)) {
            throw new ToolCallException('The MCP request could not be recorded. No tool was invoked.');
        }
        $workbench = McpWorkbenchScope::start();
        $conversation = null;
        $executionStarted = false;
        $call = new AiToolCall($this->toolName, $operation['call_id'], $arguments);
        try {
            $handler = AiFactory::createTaskHandlerFromString($workbench, $this->agentSelector);
            $conversation = AiFactory::createConversation($handler, $operation['conversation_id'], ['type' => 'mcp', 'devmode' => false]);
            $operation['handled'] = true;
            $this->bridge->put($request, $operation);
            $task = new AiMcpTask($workbench, $this->agentSelector, [
                'conversation_id' => $operation['conversation_id'],
                'message_id' => $operation['message_id'],
                'call_id' => $operation['call_id'],
                'request_id' => $request->getId()
            ]);
            try {
                $tool = $handler->getTool($this->toolName);
                $adapter = new AiToolMcpAdapter($tool);
                $call = new AiToolCall(
                    $this->toolName, $operation['call_id'], $arguments, null,
                    $adapter->normalizeArguments($arguments), $tool->getAliasWithNamespace()
                );
            } catch (AccessDeniedError|DataTypeValidationError|InvalidArgumentException $error) {
                $message = $error instanceof AccessDeniedError
                    ? 'Tool access was denied. Use an authorized tool or request the required permission.'
                    : 'Tool arguments could not be parsed. Correct the arguments before retrying.';
                $result = (new AiToolResultString($workbench, '', null, [], [new AiToolConfigurationWarning($message)]))->setFailed(true);
                $recordId = $conversation->saveToolCallStart($operation['message_id'], $call);
                $conversation->saveToolCallEnd($recordId, $result, ['duration_ms' => 0]);
                $conversation->saveToolResults($operation['message_id'], [$operation['call_id'] => $result]);
                return new \Mcp\Schema\Result\CallToolResult([new \Mcp\Schema\Content\TextContent($message)], true);
            }
            $executionStarted = true;
            $results = (new AiToolExecutor())->executeBatch($conversation, $task, $operation['message_id'], [$call]);
            try {
                return $adapter->renderResult($results[$operation['call_id']]);
            } catch (\Throwable $error) {
                AiToolExecutor::reportError($conversation, $error, [
                    'source' => 'mcp_conversion', 'message_id' => $operation['message_id'], 'call_id' => $operation['call_id']
                ]);
                throw new ToolCallException('The tool completed, but its MCP response could not be delivered.', 0, $error);
            }
        } catch (ToolCallException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            if ($conversation !== null) {
                AiToolExecutor::reportError($conversation, $exception);
                if (!$executionStarted) {
                    try {
                        $result = (new AiToolResultString($workbench, 'Tool preparation failed. Consult the application diagnostics.'))->setFailed(true);
                        $recordId = $conversation->saveToolCallStart($operation['message_id'], $call);
                        $conversation->saveToolCallEnd($recordId, $result, ['duration_ms' => 0]);
                        $conversation->saveToolResults($operation['message_id'], [$operation['call_id'] => $result]);
                    } catch (\Throwable $persistenceError) {
                        AiToolExecutor::reportError($conversation, $persistenceError);
                    }
                }
            } else {
                $workbench->getLogger()->logException($exception);
            }
            throw new ToolCallException('MCP tool handling failed. Consult the application diagnostics.', 0, $exception);
        } finally {
            $workbench->stop();
        }
    }
}