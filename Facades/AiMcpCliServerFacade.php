<?php
namespace axenox\GenAI\Facades;

use axenox\GenAI\Factories\McpFactory;
use Mcp\Server\Transport\StdioTransport;

/**
 * Owns the CLI process boundary for an MCP server using STDIO transport.
 *
 * Endpoint loading and authenticated Workbench operation scopes remain independent of the
 * long-lived protocol transport.
 */
final class AiMcpCliServerFacade
{
    /**
    * Runs the selected MCP server until its input stream is closed.
     *
     * @param string $endpointSelector Endpoint selector supplied by the MCP client configuration.
     * @return int Process exit code returned by the SDK transport.
     */
    public function run(string $endpointSelector) : int
    {
        $server = McpFactory::createServer($endpointSelector);

        return $server->run(new StdioTransport());
    }
}