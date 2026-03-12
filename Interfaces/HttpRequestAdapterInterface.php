<?php
namespace axenox\GenAI\Interfaces;

use axenox\GenAI\Common\DataQueries\OpenAiApiDataQuery;
use Psr\Http\Message\ResponseInterface;

interface HttpRequestAdapterInterface
{
    public function buildBody(OpenAiApiDataQuery $query): string;

    public function getDryrunResponse(array $requestJson) : ResponseInterface;
}