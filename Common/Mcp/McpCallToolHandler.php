<?php
namespace axenox\GenAI\Common\Mcp;

use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Server\Handler\Request\CallToolHandler;
use Mcp\Server\Handler\Request\RequestHandlerInterface;
use Mcp\Server\Session\SessionInterface;

/**
 * Binds the SDK's actual request inside its execution Fiber, preserving official validation.
 * 
 * @author GitHub Copilot
 */
class McpCallToolHandler implements RequestHandlerInterface
{
    /**
     * Wraps official SDK validation with operation-local persistence correlation.
     * 
     * @param CallToolHandler $handler
     * @param McpRequestBridge $bridge
     * @return void
     */
    public function __construct(private CallToolHandler $handler, private McpRequestBridge $bridge)
    {
    }

    /**
     * Delegates request selection to the official call-tool handler.
     * 
     * @param Request $request
     * @return bool
     */
    public function supports(Request $request) : bool
    {
        return $this->handler->supports($request);
    }

    /**
     * Prevents invocation when authoritative initial persistence failed.
     * 
     * @param Request $request
     * @param SessionInterface $session
     * @return Response|Error
     */
    public function handle(Request $request, SessionInterface $session) : Response|Error
    {
        $key = $this->bridge->key($request, $session);
        $context = $this->bridge->get($key);
        if ($context === null || ($context['blocked'] ?? false)) {
            return Error::forInternalError('Unable to persist the MCP request; no tool was executed.', $request->getId());
        }
        $this->bridge->bind($key);
        try {
            return $this->handler->handle($request, $session);
        } finally {
            $this->bridge->unbind();
        }
    }
}
