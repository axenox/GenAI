<?php
namespace axenox\GenAI\Common\Mcp;

use exface\Core\CommonLogic\Security\AuthenticationToken\CliEnvAuthToken;
use exface\Core\CommonLogic\Workbench;

/**
 * Starts an isolated Workbench authenticated as the current operating-system user.
 */
abstract class McpWorkbenchScope
{
    /**
     * Starts and authenticates a fresh MCP operation scope.
     */
    public static function start() : Workbench
    {
        $workbench = Workbench::startNewInstance();
        try {
            $workbench->getSecurity()->authenticate(new CliEnvAuthToken());
            return $workbench;
        } catch (\Throwable $exception) {
            $workbench->stop();
            throw $exception;
        }
    }
}
