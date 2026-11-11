<?php
namespace axenox\GenAI\Factories;

use axenox\GenAI\Common\Mcp\McpCapabilityRegistry;
use axenox\GenAI\AI\Agents\McpServer;
use axenox\GenAI\Common\Mcp\McpCallToolHandler;
use axenox\GenAI\Common\Mcp\McpConversationEvents;
use axenox\GenAI\Common\Mcp\McpRequestBridge;
use axenox\GenAI\Common\Mcp\McpWorkbenchScope;
use Mcp\Capability\Registry;
use Mcp\Capability\Registry\ReferenceHandler;
use Mcp\Server\Handler\Request\CallToolHandler;
use Mcp\Server;
use Symfony\Component\Uid\Uuid;

/**
 * Creates MCP protocol components behind a GenAI-owned boundary.
 * 
 * @author GitHub Copilot
 */
abstract class McpFactory
{
    /**
     * Creates a server from tools configured on a versioned AI task handler.
     * 
     * @param string $selector
     * @return Server
     */
    public static function createServer(string $selector) : Server
    {
        return static::createTaskHandlerServer($selector);
    }

    /**
     * Creates a server from tools configured on a versioned AI task handler.
     * 
     * @throws \InvalidArgumentException
     * @param string $handlerSelector
     * @return Server
     */
    public static function createTaskHandlerServer(string $handlerSelector) : Server
    {
        $workbench = McpWorkbenchScope::start();
        try {
            $handler = AiFactory::createTaskHandlerFromString($workbench, $handlerSelector);
            if (!$handler instanceof McpServer) {
                throw new \InvalidArgumentException('An MCP endpoint must use the McpServer prototype.');
            }
            $exactSelector = $handler->getSelector()->toString();
            $builder = Server::builder()->setServerInfo(
                $handler->getName(),
                $handler->getVersion(),
                'Exposes tools configured on the selected ExFace MCP endpoint.'
            );

            $bridge = new McpRequestBridge();
            $events = new McpConversationEvents($exactSelector, Uuid::v4()->toRfc4122(), $bridge);
            $registry = new Registry($events);
            $builder->setRegistry($registry)->setEventDispatcher($events)->addRequestHandler(
                new McpCallToolHandler(new CallToolHandler($registry, new ReferenceHandler()), $bridge)
            );
            (new McpCapabilityRegistry($exactSelector, $bridge))->registerTools($builder, $handler);

            return $builder->build();
        } finally {
            McpWorkbenchScope::stop($workbench);
        }
    }
}