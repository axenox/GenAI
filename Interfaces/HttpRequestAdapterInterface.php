<?php
namespace axenox\GenAI\Interfaces;

use Psr\Http\Message\ResponseInterface;

interface HttpRequestAdapterInterface
{
    /**
     * @param AiQueryInterface $query
     * @return string
     */
    public function buildBody(AiQueryInterface $query): string;

    /**
     * @param array $requestJson
     * @param string $response
     * @return ResponseInterface
     */
    public function getDryrunResponse(array $requestJson, string $response) : ResponseInterface;
}