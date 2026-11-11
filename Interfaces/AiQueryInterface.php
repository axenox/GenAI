<?php
namespace axenox\GenAI\Interfaces;

use exface\Core\Interfaces\DataSources\DataQueryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * One exchange with an LLM: what the agent sends and what came back.
 * 
 * The agent creates a new query for every submission, including each continuation after tool calls.
 * A query contains:
 * 
 * - the messages selected for the model context (typically `AiConversationInterface::getMessagesActive()`),
 * - request settings: tools, response JSON schema, temperature,
 * - after the response: the generic response message, its finish reason, usage and warnings.
 * 
 * Queries know nothing about persistence. The conversation saves their response messages, usage and
 * warnings. Request adapters derive their API request from the messages and settings of a query,
 * response adapters derive the generic response message from the API response.
 * 
 * @author Andrej Kabachnik
 *
 */
interface AiQueryInterface extends DataQueryInterface
{
    /**
     * Returns the messages selected for the model context
     * 
     * @return AiMessageInterface[]
     */
    public function getMessages() : array;

    /**
     * Returns a copy with the selected model context, preserving all other query settings.
     *
     * @param AiMessageInterface[] $messages
     * @return AiQueryInterface
     */
    public function withMessages(array $messages) : AiQueryInterface;

    /**
     * Returns the conversation, this query belongs to
     * 
     * @throws \LogicException if the query is not bound to a conversation
     * @return AiConversationInterface
     */
    public function getConversation() : AiConversationInterface;

    /**
     * Returns the query-specific sampling temperature.
     * 
     * @return float|null
     */
    public function getTemperature() : ?float;

    /**
     * Returns the JSON schema requested for the response.
     * 
     * @return array<string,mixed>|null
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
     * Returns a copy with additional warnings - existing warnings are kept
     * 
     * @param array<array-key,\Throwable|string> $warnings
     * @return AiQueryInterface
     */
    public function withWarnings(array $warnings) : AiQueryInterface;

    /**
     * Adds a warning to this query - e.g. when a request adapter had to degrade the context
     * 
     * The conversation saves all warnings of a query together with its response.
     * 
     * @param \Throwable|string $warning
     * @return AiQueryInterface
     */
    public function addWarning(\Throwable|string $warning) : AiQueryInterface;

    /**
     * Returns all warnings of this exchange: from building the request, the connector and the response adapter
     * 
     * @return array<array-key,\Throwable|string>
     */
    public function getWarnings() : array;

    /**
     * Returns the generic response message - not yet saved in the conversation
     * 
     * If the LLM requested tools, this is an `AiToolCallMessageInterface`.
     * 
     * @return AiMessageInterface
     */
    public function getResponseMessage() : AiMessageInterface;

    /**
     * Returns the answer of the LLM as text (raw) - the text of the response message
     * 
     * @return string
     */
    public function getAnswerRaw() : string;

    /**
     * Returns the structured data returned by an LLM if it runs in JSON mode
     * 
     * @return array<array-key,mixed>|null
     */
    public function getAnswerJson() : ?array;

    /**
     * Returns the answer for display - taking the response JSON schema and answer path into account
     * 
     * @return string
     */
    public function getAnswerMarkdown() : string;
    
    /**
     * Returns TRUE if the LLM completed its answer and does not wait for tool results
     * 
     * @return bool
     */
    public function isFinished() : bool;

    /**
     * Returns the reason, why the LLM stopped generating - one of the AiFinishReasonDataType values
     * 
     * @return string
     */
    public function getFinishReason() : string;

    /**
     * Returns TRUE if the LLM requested tool calls
     * 
     * @return bool
     */
    public function hasToolCalls() : bool;

    /**
     * Returns the tool calls requested by the LLM in their original order
     * 
     * @return AiToolCallInterface[]
     */
    public function getToolCalls() : array;

    /**
     * Returns the costs of this exchange if they can be calculated
     * 
     * @return float|null
     */
    public function getCosts() : ?float;

    /**
     * Returns the number of tokens in the prompt as reported by the provider
     * 
     * @return int
     */
    public function getTokensInPrompt() : int;
    
    /**
     * Returns the number of tokens in the answer as reported by the provider
     * 
     * @return int
     */
    public function getTokensInAnswer() : int;

    /**
     * Returns the name of the model, that produced the response, if reported by the provider
     * 
     * @return string|null
     */
    public function getModelName() : ?string;

    /**
     * Returns the number of messages in the query
     * 
     * @return int
     */
    public function getSequenceNumber() : int;

    /**
     * @param string $jsonPath
     * @return AiQueryInterface
     */
    public function setResponseAnswerPath(string $jsonPath) : AiQueryInterface;

    /**
     * Returns the JSONpath to find the text answer in the response JSON if a response_json_schema was provided
     * 
     * @return string|null
     */
    public function getResponseAnswerPath() : ?string;
}
