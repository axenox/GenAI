<?php
namespace axenox\GenAI\AI\Agents;

use axenox\GenAI\Common\AbstractAiTaskHandler;
use axenox\GenAI\Interfaces\AiTaskHandlerWithoutConnectionInterface;

/**
 * Exposes explicitly configured AI tools as an MCP endpoint without using an LLM.
 *
 * Configure tools directly or through assigned skills. Prompt instructions, concepts and model
 * connections are not used. Conversation logging groups calls by session inactivity by default.
 *
 * ## Example
 *
 * ```
 * {
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

    /**
     * Starts a new conversation after this many seconds without a request (unless a chat ID is supplied).
     *
     * @uxon-property conversation_inactivity_timeout_seconds
     * @uxon-type integer
     * @uxon-default 300
     */
    protected function setConversationInactivityTimeoutSeconds(mixed $seconds) : self
    {
        \axenox\GenAI\Common\Mcp\McpRequestContext::validateGrouping($seconds, $this->conversationGroupingScope);
        $this->conversationInactivityTimeoutSeconds = $seconds;
        return $this;
    }

    public function getConversationInactivityTimeoutSeconds() : int
    {
        return $this->conversationInactivityTimeoutSeconds;
    }

    /**
     * Groups within one MCP session, or across sessions belonging to the same user and endpoint version.
     *
     * @uxon-property conversation_grouping_scope
     * @uxon-type string
     * @uxon-default session
     */
    protected function setConversationGroupingScope(mixed $scope) : self
    {
        \axenox\GenAI\Common\Mcp\McpRequestContext::validateGrouping($this->conversationInactivityTimeoutSeconds, $scope);
        $this->conversationGroupingScope = $scope;
        return $this;
    }

    public function getConversationGroupingScope() : string
    {
        return $this->conversationGroupingScope;
    }
}