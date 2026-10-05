<?php
namespace axenox\GenAI\Interfaces;
use exface\Core\Interfaces\DataSources\DataQueryInterface;
use exface\Core\Interfaces\Filesystem\FileInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Common interface for queries to LLM connectors - stands for a single request/response cycle with the LLM.
 * 
 * The connector takes care of sending messages and receiving responses from a specific LLM.
 * Each type of LLM API requires a spearate AiQuery class, that will extract from its raw response
 * (typically a JSON) all information required by the agents.
 * 
 * @author Andrej Kabachnik
 *
 */
interface AiQueryInterface extends DataQueryInterface
{
    /**
     * Returns the messages to send to the LLM.
     *
     * @param bool $includeConversation
     * @return array
     */
    public function getMessages(bool $includeConversation = false) : array;

    /**
     * Returns the query-specific sampling temperature.
     *
     * @return float|null
     */
    public function getTemperature() : ?float;

    /**
     * Returns the JSON schema requested for the response.
     *
     * @return array|null
     */
    public function getResponseJsonSchema() : ?array;

    /**
     * Returns the tools available to the LLM.
     *
     * @return AiToolInterface[]
     */
    public function getTools() : array;

    /**
     * Returns a copy containing the HTTP request sent to the provider.
     *
     * @param RequestInterface $request
     * @return AiQueryInterface
     */
    public function withRequest(RequestInterface $request) : AiQueryInterface;

    /**
     * Returns a copy containing the provider response and its adapter.
     *
     * @param ResponseInterface $response
     * @param HttpResponseAdapterInterface $adapter
     * @param float|null $costs
     * @return AiQueryInterface
     */
    public function withResponse(ResponseInterface $response, HttpResponseAdapterInterface $adapter, float $costs = null) : AiQueryInterface;

    /**
     * Returns a copy containing warnings produced while processing the response.
     *
     * @param array $warnings
     * @return AiQueryInterface
     */
    public function withWarnings(array $warnings) : AiQueryInterface;

    /**
     * Appends an assistant tool call and its result for the next provider request.
     *
     * @param bool $existingCall
     * @param string $toolResponse
     * @param string $callId
     * @param array $requestMessage
     * @return AiQueryInterface
     */
    public function appendToolMessages(bool $existingCall, string $toolResponse, string $callId, array $requestMessage) : AiQueryInterface;

    /**
     * Returns the answer of the LLM as text (raw)
     * 
     * @return string
     */
    public function getAnswerRaw() : string;

    /**
     * Returns the structured data returned by an LLM if it runs in JSON mode
     * 
     * @return array|null
     */
    public function getAnswerJson() : ?array;

    /**
     * @return string|null
     */
    public function getAnswerMarkdown() : string;
    
    /**
     * Returns FALSE if the LLM is not done yet and this is just a partial response
     * 
     * @return bool
     */
    public function isFinished() : bool;

    /**
     *
     * @return float|null
     */
    public function getCosts() : ?float;

    /**
     * Warning payloads from the connector layer.
     *
     * @return \exface\Core\Interfaces\Exceptions\ExceptionInterface[]
     */
    public function getWarnings() : array;

    /**
     * 
     * @return int
     */
    public function getTokensInPrompt() : int;
    
    /**
     * 
     * @return int
     */
    public function getTokensInAnswer() : int;

    /**
     * 
     * @return int
     */
    public function getSequenceNumber() : int;

    /**
     * 
     * @return bool
     */
    public function hasResponse() : bool;

    /**
     * 
     * @return string
     */
    public function getUserPrompt() : string;

    /**
     * 
     * @return string|null
     */
    public function getSystemPrompt() : ?string;

    /**
     * 
     * @return string
     */
    public function getFinishReason() : string;

    /**
     * Checks if the request has tool calls
     * 
     * @return bool
     */
    public function hasToolCalls() : bool;

    /**
     * Full request for tool calling
     * 
     * @return array
     */
    public function getResponseMessage() : array;

    /**
     * Requested Tool Calls
     * 
     * @return AiToolCallInterface[]
     */
    public function getToolCalls() : array;

    /**
     * @return FileInterface[]
     */
    public function getFiles() : array;
    
    public function hasFiles() : bool;

    /**
     * @param string $jsonPath
     * @return AiAgentInterface
     */
    public function setResponseAnswerPath(string $jsonPath) : AiQueryInterface;

    /**
     * Returns the JSONpath to find the text answer in the response JSON if a response_json_schema was provided
     * 
     * @return string|null
     */
    public function getResponseAnswerPath() : ?string;
}