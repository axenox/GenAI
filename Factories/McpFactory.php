<?php
namespace axenox\GenAI\Factories;

use axenox\GenAI\Common\Mcp\McpCapabilityRegistry;
use axenox\GenAI\Common\Mcp\McpWorkbenchScope;
use axenox\GenAI\Common\Mcp\McpRequestBridge;
use axenox\GenAI\Common\Mcp\McpRequestContext;
use axenox\GenAI\Common\Mcp\McpConversationObserver;
use axenox\GenAI\Common\Mcp\McpPersistenceGuard;
use axenox\GenAI\AI\Agents\McpServer;
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
            $bridge = new McpRequestBridge();
            $observer = new McpConversationObserver(
                $exactSelector,
                McpRequestContext::newId(),
                $bridge,
                $handler instanceof McpServer ? $handler->getConversationInactivityTimeoutSeconds() : 300,
                $handler instanceof McpServer ? $handler->getConversationGroupingScope() : 'session'
            );
            $builder = Server::builder()->setServerInfo(
                $handler->getName(),
                $handler->getVersion(),
                'Exposes tools configured on the selected ExFace MCP endpoint.'
            );
            $builder->setEventDispatcher($observer);
            $builder->addRequestHandler(new McpPersistenceGuard($bridge));

            (new McpCapabilityRegistry($exactSelector, $bridge))->registerTools($builder, $handler);

            return $builder->build();
        } finally {
            $workbench->stop();
        }
    }
}