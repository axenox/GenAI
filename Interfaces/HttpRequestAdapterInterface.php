<?php
namespace axenox\GenAI\Interfaces;

use Psr\Http\Message\ResponseInterface;

/**
 * Builds the native request body of one LLM API from an `AiQueryInterface`
 * 
 * Request adapters derive their request from the messages and settings of a query only - using the
 * context role issers, texts, attachments and the `AiToolCallMessageInterface` subtype. They never use
 * message types, persistence formats or data of other adapters.
 * 
 * They degrade instead of refusing and add a warning to the query:
 * 
 * - attachments the API cannot accept and missing historical attachments become short text notes,
 * - incomplete tool batches (calls without results) are omitted - calls and results together,
 * - call IDs the API does not accept are mapped to safe unique IDs within the request.
 * 
 * Hard errors remain only for programming errors, not for history. Provider-independent helpers are
 * available in the `ConversationApiAdapterTrait`.
 * 
 * @author Andrej Kabachnik
 */
interface HttpRequestAdapterInterface
{
    /**
     * Returns the JSON body of the API request for the given query
     * 
     * @param AiQueryInterface $query
     * @return string
     */
    public function buildBody(AiQueryInterface $query): string;

    /**
     * Returns a fake API response for dry runs
     * 
     * @param array $requestJson
     * @param string $response
     * @return ResponseInterface
     */
    public function getDryrunResponse(array $requestJson, string $response) : ResponseInterface;
}