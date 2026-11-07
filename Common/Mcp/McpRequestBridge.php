<?php
namespace axenox\GenAI\Common\Mcp;

use Mcp\Schema\JsonRpc\Request;

/**
 * Keeps scalar persistence identifiers keyed by actual SDK request identity.
 *
 * Identity keys work across SDK-created Fibers without a shared "current request".
 */
final class McpRequestBridge
{
    private \WeakMap $operations;

    public function __construct()
    {
        $this->operations = new \WeakMap();
    }

    public function put(Request $request, array $identifiers) : void
    {
        foreach ($identifiers as $identifier) {
            if (!is_scalar($identifier) && $identifier !== null) {
                throw new \InvalidArgumentException('MCP correlation values must be scalar.');
            }
        }
        $this->operations[$request] = $identifiers;
    }

    public function get(Request $request) : ?array
    {
        return $this->operations[$request] ?? null;
    }

    public function remove(Request $request) : void
    {
        unset($this->operations[$request]);
    }
}
