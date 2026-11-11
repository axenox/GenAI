<?php
namespace axenox\GenAI\Common\Mcp;

use exface\Core\CommonLogic\Security\AuthenticationToken\CliEnvAuthToken;
use exface\Core\CommonLogic\Workbench;
use exface\Core\Interfaces\WorkbenchInterface;
use axenox\GenAI\Interfaces\AiConversationInterface;

/**
 * Starts an isolated Workbench authenticated as the current operating-system user.
 * 
 * @author GitHub Copilot
 */
abstract class McpWorkbenchScope
{
    /**
     * Starts and authenticates a fresh MCP operation scope.
     * 
     * @return Workbench
     */
    public static function start() : Workbench
    {
        $workbench = Workbench::startNewInstance();
        try {
            $workbench->getSecurity()->authenticate(new CliEnvAuthToken());
            return $workbench;
        } catch (\Throwable $exception) {
            self::stop($workbench);
            throw $exception;
        }
    }

    /**
     * Shutdown failures must not discard an already completed tool result or corrupt the protocol.
     * 
     * @param WorkbenchInterface $workbench
     * @param AiConversationInterface|null $conversation
     * @return void
     */
    public static function stop(WorkbenchInterface $workbench, ?AiConversationInterface $conversation = null) : void
    {
        try {
            $workbench->stop();
        } catch (\Throwable $error) {
            try {
                if ($conversation !== null) {
                    $conversation->reportOperationalError($error);
                } else {
                    $workbench->getLogger()->logException($error);
                }
            } catch (\Throwable $ignored) {
            }
        }
    }
}
