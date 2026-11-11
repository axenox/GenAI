<?php
namespace axenox\GenAI\Common\Mcp;

use axenox\GenAI\Common\AiToolCall;
use axenox\GenAI\Common\AiToolResultString;
use axenox\GenAI\DataTypes\AiConversationTypeDataType;
use axenox\GenAI\Factories\AiFactory;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\Interfaces\Exceptions\AuthorizationExceptionInterface;
use Mcp\Exception\ToolCallException;
use Mcp\Server\ClientGateway;
use Mcp\Server\Handler\ToolHandlerInterface;

/**
 * Recreates and invokes one configured AI tool inside a fresh Workbench scope.
 * 
 * @author GitHub Copilot
 */
class AiToolMcpHandler implements ToolHandlerInterface
{
    private string $agentSelector;

    private string $toolName;

    /**
     * Creates an immutable operation descriptor without retaining ExFace request state.
     * 
     * @param string $agentSelector
     * @param string $toolName
     * @param McpRequestBridge|null $bridge
     * @return void
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
     * 
     * @throws ToolCallException
     * @param ClientGateway $gateway
     * @return mixed
     */
    public function execute(array $arguments, ClientGateway $gateway): mixed
    {
        if ($this->bridge === null) {
            throw new ToolCallException('MCP request correlation is unavailable; no tool was executed.');
        }
        $context = $this->bridge->current();
        if ($context['blocked'] || $context['tool_name'] !== $this->toolName) {
            throw new ToolCallException('MCP request was not persisted; no tool was executed.');
        }
        $workbench = McpWorkbenchScope::start();
        try {
            $handler = AiFactory::createTaskHandlerFromString($workbench, $this->agentSelector);
            $conversation = AiFactory::createConversation($handler, $context['conversation_id'], ['TYPE' => AiConversationTypeDataType::MCP]);
            $this->bridge->markHandled();
            $task = new AiMcpTask($workbench, $this->agentSelector, new UxonObject([
                'conversation_id' => $context['conversation_id'],
                'message_id' => $context['message_id'],
                'call_id' => $context['call_id'],
                'mcp_session_id' => $context['session_id'],
                'mcp_request_id' => $context['request_id'],
                'mcp_chat_id' => $context['chat_id']
            ]));
            $call = new AiToolCall($this->toolName, $context['call_id'], $context['arguments']);
            $resolutionFailure = null;
            try {
                $tool = $handler->getTool($this->toolName);
                $call = new AiToolCall(
                    $this->toolName, $context['call_id'], $context['arguments'], null, null,
                    $tool->getAliasWithNamespace()
                );
            } catch (AuthorizationExceptionInterface $error) {
                if (AiToolMcpAdapter::getAuthorizationDenial($error) === null) {
                    $resolutionFailure = new AiToolResultString($workbench, '', null, [], [$error]);
                } else {
                    try {
                        $workbench->getLogger()->logException($error);
                    } catch (\Throwable $loggingError) {
                        $conversation->reportOperationalError($loggingError);
                    }
                    $resolutionFailure = AiToolMcpAdapter::createRejectedResult(
                        $workbench, 'You are not authorized to call this tool. Choose an allowed tool or request access.'
                    );
                }
            } catch (\Throwable $error) {
                $resolutionFailure = new AiToolResultString($workbench, '', null, [], [$error]);
            }

            $record = null;
            $started = hrtime(true);
            $startedOn = date('Y-m-d H:i:s');
            try {
                $record = $conversation->saveToolCallStart($context['message_id'], $call, ['started_on' => $startedOn]);
            } catch (\Throwable $error) {
                $conversation->reportOperationalError($error);
            }
            $result = $resolutionFailure ?? $handler->callTool($task, $call);
            [$result, $denials] = AiToolMcpAdapter::normalizeAuthorizationResult($result);
            foreach ($denials as $denial) {
                try {
                    $workbench->getLogger()->logException($denial);
                } catch (\Throwable $loggingError) {
                    $conversation->reportOperationalError($loggingError);
                }
            }
            if ($resolutionFailure === null) {
                foreach ($handler->getToolOperationalErrors() as $error) {
                    $conversation->reportOperationalError($error);
                }
            } else {
                foreach ($result->getOperationalErrors() as $error) {
                    $conversation->reportOperationalError($error);
                }
            }
            $timing = [
                'started_on' => $startedOn,
                'finished_on' => date('Y-m-d H:i:s'),
                'duration_ms' => $result->getDurationMs() ?? (hrtime(true) - $started) / 1000000,
                'execution_arguments' => $call->getExecutionArguments(),
                'tool_alias' => $call->getToolAlias()
            ];
            if ($result->getDurationMs() === null) {
                $result->setDurationMs($timing['duration_ms']);
            }
            if ($record !== null) {
                try {
                    $conversation->saveToolCallEnd($record, $result, $timing);
                } catch (\Throwable $error) {
                    $conversation->reportOperationalError($error);
                }
            }
            try {
                $conversation->saveToolResults($context['message_id'], [$context['call_id'] => $result], [
                    'finished_on' => $timing['finished_on'],
                    'duration_ms' => (hrtime(true) - $context['monotonic_start']) / 1000000,
                    'call_timing' => [$context['call_id'] => $timing]
                ]);
            } catch (\Throwable $error) {
                $conversation->reportOperationalError($error);
            }
            try {
                return AiToolMcpAdapter::convertResult($result);
            } catch (\Throwable $error) {
                $conversation->reportOperationalError($error);
                throw new ToolCallException('The tool completed, but its result could not be delivered. Do not retry side effects automatically.', 0, $error);
            }
        } catch (\Throwable $exception) {
            if ($exception instanceof ToolCallException) {
                throw $exception;
            }
            if (isset($conversation)) {
                $conversation->reportOperationalError($exception);
            } else {
                try {
                    $workbench->getLogger()->logException($exception);
                } catch (\Throwable $ignored) {
                }
            }
            throw new ToolCallException('Unable to handle the MCP tool request.', 0, $exception);
        } finally {
            McpWorkbenchScope::stop($workbench, $conversation ?? null);
        }
    }
}