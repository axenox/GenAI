<?php
namespace axenox\GenAI\Common\ApiAdapters;

use axenox\GenAI\Common\AiToolCall;
use axenox\GenAI\Interfaces\HttpResponseAdapterInterface;
use exface\Core\DataTypes\JsonDataType;
use Psr\Http\Message\ResponseInterface;

class CompletionsApiResponseAdapter implements HttpResponseAdapterInterface
{
    private array $json;
    
    public function __construct(ResponseInterface $response)
    {
        $this->json = JsonDataType::decodeJson($response->getBody()->__toString(), true);
    }

    protected function getResponseData() : array
    {
        return $this->json;
    }

    public function getUsage() : array
    {
        return $this->json['usage'] ?? [];
    }
    
    /**
     * {@inheritDoc}
     * @see \axenox\GenAI\Interfaces\HttpResponseAdapterInterface::getFullAnswer()
     */
    public function getFullAnswer() : string
    {
        $fullAnswer = $this->json['choices'][0]['message']['content'];
        return $fullAnswer;
    }

    /**
     * {@inheritDoc}
     * @see \axenox\GenAI\Interfaces\HttpResponseAdapterInterface::getAnswerJson()
     */
    public function getAnswerJson() : ?array
    {
        return json_decode($this->getFullAnswer(), true);
    }

    /**
     * {@inheritDoc}
     * @see \axenox\GenAI\Interfaces\HttpResponseAdapterInterface
     */
    public function isFinished() : bool
    {
        return $this->json['choices'][0]['finish_reason'] === 'stop';
    }

    /**
     * {@inheritDoc}
     * @see \axenox\GenAI\Interfaces\HttpResponseAdapterInterface
     */
    public function getTokensInPrompt() : int
    {
        return $this->json['usage']['prompt_tokens'];
    }

    /**
     * {@inheritDoc}
     * @see \axenox\GenAI\Interfaces\HttpResponseAdapterInterface
     */
    public function getTokensInAnswer() : int
    {
        return $this->json['usage']['completion_tokens'];
    }

    /**
     * {@inheritDoc}
     * @see \axenox\GenAI\Interfaces\HttpResponseAdapterInterface::hasToolCalls()
     */
    public function hasToolCalls() : bool
    {
        return $this->json['choices'][0]['finish_reason'] === 'tool_calls';
    }

    /**
     * {@inheritDoc}
     * @see \axenox\GenAI\Interfaces\HttpResponseAdapterInterface::getToolCalls()
     */
    public function getToolCalls() : array
    {
        $result = [];
        foreach($this->json['choices'][0]['message']['tool_calls'] as $call) {
            $function = $call['function'];
            $result[] = new AiToolCall($function['name'], $call['id'], json_decode($function['arguments'], true));
        }
        return $result;
    }

    /**
     * {@inheritDoc}
     * @see \axenox\GenAI\Interfaces\HttpResponseAdapterInterface
     */
    public function getResponseMessage() : array
    {
        // TODO add a AiMessageInterface and return a wrapper class instead of the raw array. This is currently returned in the completions format. Other adapter will need to translate this format into theirs.
        return $this->json['choices'][0]['message'];
    }

    /**
     * {@inheritDoc}
     * @see \axenox\GenAI\Interfaces\HttpResponseAdapterInterface
     */
    public function getFinishReason() : string
    {
        return $this->getResponseData()['choices'][0]['finish_reason'];
    }
}