<?php
namespace axenox\GenAI\Common\ApiAdapters;

use axenox\GenAI\Common\AiToolCall;
use axenox\GenAI\Common\DataQueries\OpenAiApiDataQuery;
use axenox\GenAI\Exceptions\AiInvalidRequestError;
use axenox\GenAI\Exceptions\AiMaxTokensExceededError;
use axenox\GenAI\Exceptions\AiModelRefusalError;
use axenox\GenAI\Exceptions\AiOverloadError;
use axenox\GenAI\Exceptions\AiProviderDataQueryError;
use axenox\GenAI\Interfaces\HttpResponseAdapterInterface;
use exface\Core\DataTypes\JsonDataType;
use Psr\Http\Message\ResponseInterface;

class ClaudeMessagesApiResponseAdapter implements HttpResponseAdapterInterface
{
    private array $json;

    public function enrichError(OpenAiApiDataQuery $query, \Exception $e) : \Exception
    {
        $model = isset($this->json['model']) ? (string) $this->json['model'] : null;

        if (($this->json['type'] ?? null) === 'error') {
            $errorType = (string) ($this->json['error']['type'] ?? 'unknown_error');
            $message = (string) ($this->json['error']['message'] ?? $e->getMessage());
            switch (true) {
                case $errorType === 'overloaded_error':
                    return new AiOverloadError($query, '', $e, true, $model, 'claude');
                case $errorType === 'invalid_request_error':
                    if (stripos($message, 'max_tokens') !== false && stripos($message, 'maximum allowed') !== false) {
                        $requested = null;
                        $allowed = null;
                        if (preg_match('/max_tokens:\s*(\d+)\s*>\s*(\d+)/i', $message, $matches)) {
                            $requested = (int) $matches[1];
                            $allowed = (int) $matches[2];
                        }
                        return new AiMaxTokensExceededError($query, $message, $e, false, $model, 'claude', $requested, $allowed);
                    }
                    return new AiInvalidRequestError($query, $message, $e, false, $model, 'claude');
                default:
                    return new AiProviderDataQueryError($query, $message, $e, null, $model);
            }
        }

        if (($this->json['stop_reason'] ?? null) === 'refusal') {
            $category = (string) ($this->json['stop_details']['category'] ?? 'unknown');
            return new AiModelRefusalError($query, '', $e, false, $model, 'claude', $category);
        }

        return new AiProviderDataQueryError($query, 'Error in LLM response.', $e, null, $model);
    }

    public function isError() : bool
    {
        $finishReason = $this->getFinishReason();
        if ($finishReason === 'refusal') {
            return true;
        }

        return in_array($finishReason, ['tool_calls', 'end_turn', 'max_tokens', 'stop_sequence', 'pause_turn'], true) === false;
    }

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

    public function getAnswerRaw() : string
    {
        $parts = [];
        foreach (($this->json['content'] ?? []) as $block) {
            if (($block['type'] ?? null) === 'text') {
                $parts[] = (string) ($block['text'] ?? '');
            }
        }

        return implode("\n", $parts);
    }

    public function getAnswerJson() : ?array
    {
        return JsonDataType::decodeJson($this->getAnswerRaw());
    }

    public function isFinished() : bool
    {
        return $this->hasToolCalls() === false && ($this->getFinishReason() !== 'pause_turn');
    }

    public function getTokensInPrompt() : int
    {
        return (int) ($this->json['usage']['input_tokens'] ?? 0);
    }

    public function getTokensInAnswer() : int
    {
        return (int) ($this->json['usage']['output_tokens'] ?? 0);
    }

    public function getFinishReason() : string
    {
        if ($this->hasToolCalls()) {
            return 'tool_calls';
        }

        return (string) ($this->json['stop_reason'] ?? 'unknown');
    }

    public function hasToolCalls() : bool
    {
        foreach (($this->json['content'] ?? []) as $block) {
            if (($block['type'] ?? null) === 'tool_use') {
                return true;
            }
        }

        return false;
    }

    public function getResponseMessage() : array
    {
        $message = [
            'role' => 'assistant',
            'content' => $this->getAnswerRaw(),
        ];

        if ($this->hasToolCalls()) {
            $toolCalls = [];
            foreach (($this->json['content'] ?? []) as $block) {
                if (($block['type'] ?? null) !== 'tool_use') {
                    continue;
                }

                $toolCalls[] = [
                    'id' => $block['id'] ?? '',
                    'type' => 'function',
                    'function' => [
                        'name' => $block['name'] ?? '',
                        'arguments' => json_encode(
                            $this->normalizeToolCallInputObject($block['input'] ?? null),
                            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                        ),
                    ],
                ];
            }

            $message['tool_calls'] = $toolCalls;
        }

        return $message;
    }

    /**
     * @param mixed $input
     * @return object
     */
    protected function normalizeToolCallInputObject($input): object
    {
        if ($input instanceof \stdClass) {
            return $input;
        }

        if (is_array($input)) {
            if (empty($input)) {
                return new \stdClass();
            }
            return (object) $input;
        }

        if (is_object($input)) {
            return $input;
        }

        return new \stdClass();
    }

    public function getToolCalls() : array
    {
        $result = [];

        foreach (($this->json['content'] ?? []) as $block) {
            if (($block['type'] ?? null) !== 'tool_use') {
                continue;
            }

            $result[] = new AiToolCall(
                $block['name'] ?? '',
                $block['id'] ?? '',
                is_array($block['input'] ?? null) ? $block['input'] : []
            );
        }

        return $result;
    }
}