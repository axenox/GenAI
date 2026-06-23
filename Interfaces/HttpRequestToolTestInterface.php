<?php
namespace axenox\GenAI\Interfaces;

use Psr\Http\Message\ResponseInterface;

/**
 * Optional interface for HTTP request adapters that support faking tool calls for testing.
 * 
 * Request adapters implementing this interface allow connectors like the `OpenAiToolTester`
 * to extract the user prompt and the available tools from a request and to build a faked
 * response containing tool calls - all without contacting an LLM.
 * 
 * Adapters are not required to implement this interface. They only need to do so if they
 * should support tool testing.
 * 
 * @author Andrej Kabachnik
 */
interface HttpRequestToolTestInterface
{
    /**
     * Returns the text of the latest user message in the given request body.
     * 
     * @param array $requestJson
     * @return string|null
     */
    public function getUserPromptFromRequest(array $requestJson) : ?string;

    /**
     * Returns the names of all tools available in the given request body.
     * 
     * @param array $requestJson
     * @return string[]
     */
    public function getToolNamesFromRequest(array $requestJson) : array;

    /**
     * Returns the ordered list of argument names defined for a tool in the request body.
     * 
     * Returns NULL if the tool is not available in the request.
     * 
     * @param array $requestJson
     * @param string $toolName
     * @return string[]|null
     */
    public function getToolArgumentNames(array $requestJson, string $toolName) : ?array;

    /**
     * Returns TRUE if the request already contains results of previous tool calls.
     * 
     * This is used to detect whether the tools to test have already been called in a previous
     * iteration - so the connector can return a final answer instead of faking the same tool
     * call again and again.
     * 
     * @param array $requestJson
     * @return bool
     */
    public function hasToolCallResultsInRequest(array $requestJson) : bool;

    /**
     * Returns the output texts of all previous tool calls contained in the request body.
     * 
     * @param array $requestJson
     * @return string[]
     */
    public function getToolCallResultsFromRequest(array $requestJson) : array;

    /**
     * Builds a response containing a plain text answer without contacting an LLM.
     * 
     * @param array $requestJson
     * @param string $text
     * @return ResponseInterface
     */
    public function buildTextResponse(array $requestJson, string $text) : ResponseInterface;

    /**
     * Builds a response faking the given tool calls without contacting an LLM.
     * 
     * Each tool call is an associative array with the keys `name` (string), `call_id` (string)
     * and `arguments` (associative array of argument name to value).
     * 
     * @param array $requestJson
     * @param array $toolCalls
     * @return ResponseInterface
     */
    public function buildToolCallResponse(array $requestJson, array $toolCalls) : ResponseInterface;
}
