<?php
namespace axenox\GenAI\Common\Mcp;

use Mcp\Schema\JsonRpc\Request;
use Mcp\Server\Session\SessionInterface;

/**
 * Keeps only operation-local scalar correlation data, never live framework or SDK instances.
 * 
 * @author GitHub Copilot
 */
class McpRequestBridge
{
    private array $operations = [];
    private array $fibers = [];

    /**
     * Correlates the actual SDK request object and its typed request ID within a session.
     * 
     * @param Request $request
     * @param SessionInterface $session
     * @return string
     */
    public function key(Request $request, SessionInterface $session) : string
    {
        return json_encode([
            spl_object_id($request), $session->getId()->toRfc4122(), $request->getId()
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * Retains scalar operation context until the SDK sends its terminal event.
     * 
     * @param string $key
     * @param array<array-key,mixed> $context
     * @return void
     */
    public function put(string $key, array $context) : void
    {
        $this->operations[$key] = $context;
    }

    /**
     * Returns correlation context without loading framework services.
     * 
     * @param string $key
     * @return array<string,mixed>|null
     */
    public function get(string $key) : ?array
    {
        return $this->operations[$key] ?? null;
    }

    /**
     * Binds a persisted attempt to the current execution Fiber.
     * 
     * @throws \LogicException
     * @param string $key
     * @return void
     */
    public function bind(string $key) : void
    {
        if (!isset($this->operations[$key])) {
            throw new \LogicException('MCP request was not persisted before dispatch.');
        }
        $this->fibers[$this->fiberKey()] = $key;
    }

    /**
     * Returns the current Fiber's context or rejects uncorrelated execution.
     * 
     * @throws \LogicException
     * @return array<string,mixed>
     */
    public function current() : array
    {
        $key = $this->fibers[$this->fiberKey()] ?? null;
        if ($key === null || !isset($this->operations[$key])) {
            throw new \LogicException('No operation-local MCP correlation context.');
        }
        return $this->operations[$key];
    }

    /**
     * Prevents response events from completing an already handled attempt twice.
     * 
     * @return void
     */
    public function markHandled() : void
    {
        $this->operations[$this->fibers[$this->fiberKey()]]['handled'] = true;
    }

    /**
     * Releases only the current Fiber's binding.
     * 
     * @return void
     */
    public function unbind() : void
    {
        unset($this->fibers[$this->fiberKey()]);
    }

    /**
     * Discards completed operation context.
     * 
     * @param string $key
     * @return void
     */
    public function remove(string $key) : void
    {
        unset($this->operations[$key]);
    }

    /**
     * Separates concurrent execution from the synchronous dispatch context.
     * 
     * @return int
     */
    private function fiberKey() : int
    {
        $fiber = \Fiber::getCurrent();
        return $fiber === null ? 0 : spl_object_id($fiber);
    }
}
