<?php
namespace axenox\GenAI\AI\Agents;

use axenox\GenAI\Common\AbstractAiTaskHandler;
use axenox\GenAI\Interfaces\AiTaskHandlerWithoutConnectionInterface;

/**
 * Exposes explicitly configured AI tools as an MCP endpoint without using an LLM.
 *
 * Configure the endpoint's tools directly. Prompt instructions, concepts, skills and model
 * connections are not used.
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
}