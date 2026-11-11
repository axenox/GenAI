<?php
namespace axenox\GenAI\AI\Agents;

use axenox\GenAI\Common\AbstractAiTaskHandler;
use axenox\GenAI\Interfaces\AiTaskHandlerWithoutConnectionInterface;
use exface\Core\Exceptions\InvalidArgumentException;

/**
 * Exposes explicitly configured AI tools as an MCP endpoint without using an LLM.
 *
 * Configure tools directly or assign skills. Skill instructions, prompt-dependent concepts and
 * model connections are not used. Calls are logged as MCP sessions, not external AI chats.
 * Use session grouping by default; user grouping can mix separate clients and chats.
 *
 * ## Example
 *
 * ```json
 * {
 *   "conversation_inactivity_timeout_seconds": 300,
 *   "conversation_grouping_scope": "session",
 *   "tools": {
 *     "search_model": {
 *       "alias": "axenox.GenAI.ModelSearchTool",
 *       "description": "Searches model components and their usages."
 *     }
 *   }
 * }
 *
 * ```
 *
 * @author Andrej Kabachnik
 */
class McpServer extends AbstractAiTaskHandler implements AiTaskHandlerWithoutConnectionInterface
{
    private int $conversationInactivityTimeoutSeconds = 300;

    private string $conversationGroupingScope = 'session';

    private bool $devmode = false;

    /**
     * Exclude this endpoint's new conversations from production statistics during testing.
     * Existing conversations retain their original development classification.
     * 
     * @uxon-property devmode
     * @uxon-type boolean
     * @uxon-default false
     * 
     * @param bool $enabled
     * @return self
     */
    public function setDevmode(bool $enabled) : self
    {
        $this->devmode = $enabled;
        return $this;
    }

    /**
     * Get Devmode.
     * 
     * @return bool
     */
    public function getDevmode() : bool
    {
        return $this->devmode;
    }

    /**
     * Start a new MCP conversation after this many seconds without a request.
     * Each request extends the window. An explicit client chat identifier disables expiry.
     * 
     * @uxon-property conversation_inactivity_timeout_seconds
     * @uxon-type integer
     * @uxon-default 300
     * 
     * @throws InvalidArgumentException
     * @param mixed $seconds
     * @return self
     */
    public function setConversationInactivityTimeoutSeconds($seconds) : self
    {
        if (filter_var($seconds, FILTER_VALIDATE_INT) === false || is_bool($seconds) || (int) $seconds <= 0) {
            throw new InvalidArgumentException('conversation_inactivity_timeout_seconds must be a positive integer.');
        }
        $this->conversationInactivityTimeoutSeconds = (int) $seconds;
        return $this;
    }

    /**
     * Get Conversation Inactivity Timeout Seconds.
     * 
     * @return int
     */
    public function getConversationInactivityTimeoutSeconds() : int
    {
        return $this->conversationInactivityTimeoutSeconds;
    }

    /**
     * Group calls within one MCP session, or across sessions for the authenticated user.
     * Exact endpoint version and user always separate conversations. User grouping can combine
     * concurrent clients and must not be interpreted as an exact external chat boundary.
     * 
     * @uxon-property conversation_grouping_scope
     * @uxon-type [session,user]
     * @uxon-default session
     * 
     * @throws InvalidArgumentException
     * @param string $scope
     * @return self
     */
    public function setConversationGroupingScope(string $scope) : self
    {
        if (!in_array($scope, ['session', 'user'], true)) {
            throw new InvalidArgumentException('conversation_grouping_scope must be session or user.');
        }
        $this->conversationGroupingScope = $scope;
        return $this;
    }

    /**
     * Get Conversation Grouping Scope.
     * 
     * @return string
     */
    public function getConversationGroupingScope() : string
    {
        return $this->conversationGroupingScope;
    }
}