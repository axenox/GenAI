<?php
namespace axenox\GenAI\Common\Mcp;

use axenox\GenAI\Interfaces\AiTaskHandlerInterface;
use Mcp\Server\Builder;

/**
 * Registers only the tools exposed by one exact-version MCP task handler.
 */
class McpCapabilityRegistry
{
    private string $handlerSelector;

    /**
     * @param string $handlerSelector Exact versioned selector retained for all tool calls.
     */
    public function __construct(string $handlerSelector)
    {
        $this->handlerSelector = $handlerSelector;
    }

    /**
     * Adds the handler's configured tools and their isolated per-call handlers.
     */
    public function registerTools(Builder $builder, AiTaskHandlerInterface $handler) : Builder
    {
        foreach ($handler->getTools() as $tool) {
            $builder->add(
                (new AiToolMcpAdapter($tool))->createDefinition(),
                new AiToolMcpHandler($this->handlerSelector, $tool->getName())
            );
        }

        return $builder;
    }
}
