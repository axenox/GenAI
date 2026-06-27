<?php
namespace axenox\GenAI\Interfaces;

use axenox\GenAI\Common\DataQueries\OpenAiApiDataQuery;
use Psr\Http\Message\ResponseInterface;

interface HttpRequestAdapterInterface
{
    /**
     * @param OpenAiApiDataQuery $query
     * @return string
     */
    public function buildBody(OpenAiApiDataQuery $query): string;

    /**
     * @param array $requestJson
     * @param string $response
     * @return ResponseInterface
     */
    public function getDryrunResponse(array $requestJson, string $response) : ResponseInterface;
}