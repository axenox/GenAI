<?php
namespace axenox\GenAI\Common\Mcp;

use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Server\Handler\Request\RequestHandlerInterface;
use Mcp\Server\Session\SessionInterface;

/**
 * Prevents execution after failed initial logging through the official SDK handler path.
 */
final class McpPersistenceGuard implements RequestHandlerInterface
{
    public function __construct(private McpRequestBridge $bridge)
    {
    }

    public function supports(Request $request) : bool
    {
        return ($this->bridge->get($request)['blocked'] ?? false) === true;
    }

    public function handle(Request $request, SessionInterface $session) : Response|Error
    {
        return Error::forInternalError('The MCP request could not be recorded. No tool was invoked.', $request->getId());
    }
}
