<?php
namespace axenox\GenAI\Common\Mcp;

use axenox\GenAI\Common\Tasks\AiMcpTask;
use axenox\GenAI\Factories\AiFactory;
use exface\Core\Interfaces\Exceptions\ExceptionInterface;
use Mcp\Exception\ToolCallException;
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
    public function __construct(string $agentSelector, string $toolName)
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
        $workbench = McpWorkbenchScope::start();
        try {
            $handler = AiFactory::createTaskHandlerFromString($workbench, $this->agentSelector);
            $tool = $handler->getTool($this->toolName);
            $task = new AiMcpTask($workbench, $this->agentSelector);

            return (new AiToolMcpAdapter($tool))->invoke($handler, $task, $arguments);
        } catch (ExceptionInterface $exception) {
            $workbench->getLogger()->logException($exception);
            throw new ToolCallException($exception->getMessage(), 0, $exception);
        } catch (\Throwable $exception) {
            $workbench->getLogger()->logException($exception);
            throw $exception;
        } finally {
            $workbench->stop();
        }
    }
}