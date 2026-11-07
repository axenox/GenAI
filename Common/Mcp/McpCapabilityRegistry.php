<?php
namespace axenox\GenAI\Common\Mcp;

use axenox\GenAI\Interfaces\AiTaskHandlerInterface;
use Mcp\Server\Builder;
use Mcp\Server\RequestContext;

/**
 * Registers only the tools exposed by one exact-version MCP task handler.
 */
class McpCapabilityRegistry
{
    private string $handlerSelector;

    /**
     * @param string $handlerSelector Exact versioned selector retained for all tool calls.
     */
    public function __construct(string $handlerSelector, private ?McpRequestBridge $bridge = null)
    {
        $this->handlerSelector = $handlerSelector;
    }

    /**
     * Adds the handler's configured tools and their isolated per-call handlers.
     */
    public function registerTools(Builder $builder, AiTaskHandlerInterface $handler) : Builder
    {
        foreach ($handler->getTools() as $tool) {
            $definition = (new AiToolMcpAdapter($tool))->createDefinition();
            $operation = new AiToolMcpHandler($this->handlerSelector, $tool->getName(), $this->bridge);
            $builder->addTool(
                static function (RequestContext $context) use ($operation) {
                    $request = $context->getRequest();
                    return $operation->executeRequest($request->arguments, $context->getClientGateway(), $request);
                },
                name: $definition->name,
                title: $definition->title,
                description: $definition->description,
                inputSchema: $definition->inputSchema,
                annotations: $definition->annotations
            );
        }

        return $builder;
    }
}
