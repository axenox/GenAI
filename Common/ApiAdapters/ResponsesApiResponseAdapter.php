<?php
namespace axenox\GenAI\Common\ApiAdapters;

use axenox\GenAI\Interfaces\HttpResponseAdapterInterface;
use exface\Core\DataTypes\JsonDataType;
use Psr\Http\Message\ResponseInterface;

class ResponsesApiResponseAdapter implements HttpResponseAdapterInterface
{
    private array $json;
    
    public function __construct(ResponseInterface $response)
    {
        $this->json = JsonDataType::decodeJson($response->getBody()->__toString(), true);    
    }

    public function getUsage() : array
    {
        return $this->json['usage'] ?? [];
    }
}