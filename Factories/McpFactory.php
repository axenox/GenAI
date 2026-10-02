<?php
namespace axenox\GenAI\Factories;

use axenox\GenAI\Common\Mcp\McpCapabilityRegistry;
use axenox\GenAI\Common\Mcp\McpWorkbenchScope;
use Mcp\Server;

/**
 * Creates MCP protocol components behind a GenAI-owned boundary.
 */
abstract class McpFactory
{
    /**
     * Creates a server from tools configured on a versioned AI task handler.
     */
    public static function createServer(string $selector) : Server
    {
        return static::createTaskHandlerServer($selector);
    }

    /**
     * Creates a server from tools configured on a versioned AI task handler.
     */
    public static function createTaskHandlerServer(string $handlerSelector) : Server
    {
        $workbench = McpWorkbenchScope::start();
        try {
            $handler = AiFactory::createTaskHandlerFromString($workbench, $handlerSelector);
            $exactSelector = $handler->getSelector()->toString();
            $builder = Server::builder()->setServerInfo(
                $handler->getName(),
                $handler->getVersion(),
                'Exposes tools configured on the selected ExFace MCP endpoint.'
            );

            (new McpCapabilityRegistry($exactSelector))->registerTools($builder, $handler);

            return $builder->build();
        } finally {
            $workbench->stop();
        }
    }
}