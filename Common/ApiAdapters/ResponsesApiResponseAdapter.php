<?php
namespace axenox\GenAI\Common\ApiAdapters;

use axenox\GenAI\Common\AiToolCall;
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
        $texts = [];

        foreach (($this->json['output'] ?? []) as $outputItem) {
            if (($outputItem['type'] ?? null) !== 'message') {
                continue;
            }

            foreach (($outputItem['content'] ?? []) as $contentItem) {
                if (($contentItem['type'] ?? null) === 'output_text') {
                    $texts[] = $contentItem['text'] ?? '';
                }
            }
        }

        return implode("\n", $texts);
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
        return ($this->json['status'] ?? null) === 'completed' && $this->hasToolCalls() === false;
    }

    /**
     * {@inheritDoc}
     * @see \axenox\GenAI\Interfaces\HttpResponseAdapterInterface
     */
    public function getTokensInPrompt() : int
    {
        return (int) ($this->json['usage']['input_tokens'] ?? 0);
    }

    /**
     * {@inheritDoc}
     * @see \axenox\GenAI\Interfaces\HttpResponseAdapterInterface
     */
    public function getTokensInAnswer() : int
    {
        return (int) ($this->json['usage']['output_tokens'] ?? 0);
    }

    /**
     * {@inheritDoc}
     * @see \axenox\GenAI\Interfaces\HttpResponseAdapterInterface::hasToolCalls()
     */
    public function hasToolCalls() : bool
    {
        foreach (($this->json['output'] ?? []) as $outputItem) {
            if (($outputItem['type'] ?? null) === 'function_call') {
                return true;
            }
        }

        return false;
    }

    /**
     * {@inheritDoc}
     * @see \axenox\GenAI\Interfaces\HttpResponseAdapterInterface::getToolCalls()
     */
    public function getToolCalls() : array
    {
        $result = [];

        foreach (($this->json['output'] ?? []) as $call) {
            if (($call['type'] ?? null) !== 'function_call') {
                continue;
            }

            $result[] = new AiToolCall(
                $call['name'] ?? '',
                $call['call_id'] ?? '',
                json_decode($call['arguments'] ?? '{}', true) ?? []
            );
        }

        return $result;
    }

    /**
     * {@inheritDoc}
     * @see \axenox\GenAI\Interfaces\HttpResponseAdapterInterface
     */
    public function getResponseMessage() : array
    {
        $content = [];

        foreach (($this->json['output'] ?? []) as $outputItem) {
            if (($outputItem['type'] ?? null) !== 'message') {
                continue;
            }

            foreach (($outputItem['content'] ?? []) as $contentItem) {
                if (($contentItem['type'] ?? null) === 'output_text') {
                    $content[] = [
                        'type' => 'text',
                        'text' => $contentItem['text'] ?? ''
                    ];
                }
            }
        }

        $message = [
            'role' => 'assistant',
            'content' => $this->getFullAnswer()
        ];

        if ($this->hasToolCalls()) {
            $toolCalls = [];

            foreach (($this->json['output'] ?? []) as $outputItem) {
                if (($outputItem['type'] ?? null) !== 'function_call') {
                    continue;
                }

                $toolCalls[] = [
                    'id' => $outputItem['call_id'] ?? '',
                    'type' => 'function',
                    'function' => [
                        'name' => $outputItem['name'] ?? '',
                        'arguments' => $outputItem['arguments'] ?? '{}'
                    ]
                ];
            }

            $message['tool_calls'] = $toolCalls;
        }

        return $message;
    }

    /**
     * {@inheritDoc}
     * @see \axenox\GenAI\Interfaces\HttpResponseAdapterInterface
     */
    public function getFinishReason() : string
    {
        if ($this->hasToolCalls()) {
            return 'tool_calls';
        }

        return ($this->json['status'] ?? 'unknown') === 'completed'
            ? 'stop'
            : (string) ($this->json['status'] ?? 'unknown');
    }
}