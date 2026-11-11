<?php
namespace axenox\GenAI\Common\Mcp;

use axenox\GenAI\Interfaces\AiTaskHandlerInterface;
use Mcp\Server\Builder;

/**
 * Registers only the tools exposed by one exact-version MCP task handler.
 * 
 * @author GitHub Copilot
 */
class McpCapabilityRegistry
{
    private string $handlerSelector;

    /**
     * @param string $handlerSelector Exact versioned selector retained for all tool calls.
     * 
     * @param McpRequestBridge|null $bridge
     * @return void
     */
    public function __construct(string $handlerSelector, private ?McpRequestBridge $bridge = null)
    {
        $this->handlerSelector = $handlerSelector;
    }

    /**
     * Adds the handler's configured tools and their isolated per-call handlers.
     * 
     * @param Builder $builder
     * @param AiTaskHandlerInterface $handler
     * @return Builder
     */
    public function registerTools(Builder $builder, AiTaskHandlerInterface $handler) : Builder
    {
        foreach ($handler->getTools() as $tool) {
            $builder->add(
                (new AiToolMcpAdapter($tool))->createDefinition(),
                new AiToolMcpHandler($this->handlerSelector, $tool->getName(), $this->bridge)
            );
        }

        return $builder;
    }
}
