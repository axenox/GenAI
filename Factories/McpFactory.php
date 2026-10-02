<?php
namespace axenox\GenAI\Factories;

use axenox\GenAI\Common\Mcp\DiagnosticTool;
use axenox\GenAI\Common\Mcp\McpCapabilityRegistry;
use axenox\GenAI\Common\Mcp\McpWorkbenchScope;
use Mcp\Server;

/**
 * Creates MCP protocol components behind a GenAI-owned boundary.
 */
abstract class McpFactory
{
    /**
    * Creates either the diagnostic server or a configured task-handler server.
     */
    public static function createServer(string $selector) : Server
    {
        if ($selector === 'axenox.genai:diagnostics') {
            return static::createDiagnosticServer($selector);
        }

        return static::createTaskHandlerServer($selector);
    }

    /**
     * Creates the Phase 1 server containing only the hard-coded diagnostic tool.
     *
     * @param string $endpointSelector Endpoint selector supplied to the CLI process.
     * @return Server
     */
    public static function createDiagnosticServer(string $endpointSelector) : Server
    {
        $diagnosticTool = new DiagnosticTool($endpointSelector);

        return Server::builder()
            ->setServerInfo(
                'ExFace MCP diagnostic server',
                '0.1.0',
                'Verifies the GenAI MCP STDIO protocol boundary.'
            )
            ->addTool(
                [$diagnosticTool, 'getStatus'],
                name: 'diagnostics',
                title: 'Diagnostics',
                description: 'Returns basic information about the running ExFace MCP process.',
                inputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'emitWarning' => [
                            'type' => 'boolean',
                            'description' => 'Emits a test warning to STDERR before returning.'
                        ]
                    ],
                    'additionalProperties' => false
                ],
                outputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'status' => ['type' => 'string'],
                        'endpoint' => ['type' => 'string'],
                        'php_version' => ['type' => 'string'],
                        'transport' => ['type' => 'string']
                    ],
                    'required' => ['status', 'endpoint', 'php_version', 'transport'],
                    'additionalProperties' => false
                ]
            )
            ->build();
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