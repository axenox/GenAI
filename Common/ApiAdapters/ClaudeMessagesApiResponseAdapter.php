<?php
namespace axenox\GenAI\Common\ApiAdapters;

use axenox\GenAI\Common\AiToolCall;
use axenox\GenAI\DataTypes\AiFinishReasonDataType;
use axenox\GenAI\DataTypes\AiMessageTypeDataType;
use axenox\GenAI\Exceptions\AiInvalidRequestError;
use axenox\GenAI\Exceptions\AiMaxTokensExceededError;
use axenox\GenAI\Exceptions\AiModelRefusalError;
use axenox\GenAI\Exceptions\AiOverloadError;
use axenox\GenAI\Exceptions\AiProviderDataQueryError;
use axenox\GenAI\Factories\AiFactory;
use axenox\GenAI\Interfaces\AiMessageInterface;
use axenox\GenAI\Interfaces\AiQueryInterface;
use axenox\GenAI\Interfaces\HttpResponseAdapterInterface;
use exface\Core\DataTypes\JsonDataType;
use Psr\Http\Message\ResponseInterface;

/**
 * Derives a generic response message from an Anthropic Claude Messages API response
 * 
 * - Text blocks are joined into the answer text. Web citations are appended as a list of sources.
 * - Outcomes of tools executed by Anthropic itself (e.g. web search) are already part of the answer text.
 * - Unfinished answers (`max_tokens`, `pause_turn`) keep their text and report `AiFinishReasonDataType::TRUNCATED`.
 * 
 * If the response contains thinking blocks, all content blocks are kept as optional adapter data under
 * `ADAPTER_DATA_KEY`. The request adapter replays them only when the same model continues and the blocks
 * still match the generic message.
 * 
 * @author Andrej Kabachnik
 */
class ClaudeMessagesApiResponseAdapter implements HttpResponseAdapterInterface
{
    const ADAPTER_DATA_KEY = 'anthropic.messages';

    private array $json;
    private ?object $wire;
    private ?AiMessageInterface $message = null;
    private array $warnings = [];

    /**
     * Captures the wire payload without coercing JSON object/list distinctions.
     * 
     * @param ResponseInterface $response
     * @return void
     */
    public function __construct(ResponseInterface $response)
    {
        $body = (string) $response->getBody();
        $this->json = JsonDataType::decodeJson($body, true) ?? [];
        $wire = json_decode($body, false);
        $this->wire = $wire instanceof \stdClass ? $wire : null;
    }

    /**
     * {@inheritDoc}
     * @see HttpResponseAdapterInterface::enrichError()
     */
    public function enrichError(AiQueryInterface $query, \Exception $e) : \Exception
    {
        $model = $this->getModelName();

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

        if ($this->getNativeStopReason() === 'refusal') {
            $category = (string) ($this->json['stop_details']['category'] ?? 'unknown');
            return new AiModelRefusalError($query, '', $e, false, $model, 'claude', $category);
        }

        return new AiProviderDataQueryError($query, 'Error in LLM response.', $e, null, $model);
    }

    /**
     * {@inheritDoc}
     * @see HttpResponseAdapterInterface::isError()
     */
    public function isError() : bool
    {
        return in_array($this->getNativeStopReason(), ['tool_use', 'end_turn', 'max_tokens', 'stop_sequence', 'pause_turn', 'model_context_window_exceeded'], true) === false;
    }

    /**
     * Returns the native stop reason
     * 
     * @return string
     */
    protected function getNativeStopReason() : string
    {
        return (string) ($this->json['stop_reason'] ?? 'unknown');
    }

    /**
     * {@inheritDoc}
     * @see HttpResponseAdapterInterface::getFinishReason()
     */
    public function getFinishReason() : string
    {
        switch ($this->getNativeStopReason()) {
            case 'end_turn':
            case 'stop_sequence':
                return AiFinishReasonDataType::COMPLETED;
            case 'tool_use':
                return AiFinishReasonDataType::TOOL_CALLS;
            case 'max_tokens':
            case 'pause_turn':
            case 'model_context_window_exceeded':
                return AiFinishReasonDataType::TRUNCATED;
            case 'refusal':
                return AiFinishReasonDataType::REFUSED;
        }
        return AiFinishReasonDataType::ERROR;
    }

    /**
     * {@inheritDoc}
     * @see HttpResponseAdapterInterface::getModelName()
     */
    public function getModelName() : ?string
    {
        return isset($this->json['model']) ? (string) $this->json['model'] : null;
    }

    /**
     * {@inheritDoc}
     * @see HttpResponseAdapterInterface::getUsage()
     */
    public function getUsage() : array
    {
        return $this->json['usage'] ?? [];
    }

    /**
     * {@inheritDoc}
     * @see HttpResponseAdapterInterface::getTokensInPrompt()
     */
    public function getTokensInPrompt() : int
    {
        return (int) ($this->json['usage']['input_tokens'] ?? 0);
    }

    /**
     * {@inheritDoc}
     * @see HttpResponseAdapterInterface::getTokensInAnswer()
     */
    public function getTokensInAnswer() : int
    {
        return (int) ($this->json['usage']['output_tokens'] ?? 0);
    }

    /**
     * {@inheritDoc}
     * @see HttpResponseAdapterInterface::getWarnings()
     */
    public function getWarnings() : array
    {
        $this->getResponseMessage();
        return $this->warnings;
    }

    /**
     * {@inheritDoc}
     * @see HttpResponseAdapterInterface::getResponseMessage()
     */
    public function getResponseMessage() : AiMessageInterface
    {
        if ($this->message !== null) {
            return $this->message;
        }
        $blocks = is_array($this->wire->content ?? null) ? $this->wire->content : [];
        $sources = [];
        $calls = [];
        $hasThinking = false;
        foreach ($blocks as $block) {
            switch ($block->type ?? null) {
                case 'text':
                    // Text is extracted by getText() to make sure the request adapter compares the same way
                    $this->collectCitations($block->citations ?? [], $sources);
                    break;
                case 'tool_use':
                    $arguments = $block->input ?? new \stdClass();
                    if (! $arguments instanceof \stdClass) {
                        $arguments = (object) (is_array($arguments) ? $arguments : []);
                    }
                    $calls[] = new AiToolCall((string) ($block->name ?? ''), (string) ($block->id ?? ''), $arguments);
                    break;
                case 'thinking':
                case 'redacted_thinking':
                    $hasThinking = true;
                    break;
                case 'server_tool_use':
                case 'web_search_tool_result':
                case 'web_fetch_tool_result':
                case 'code_execution_tool_result':
                    // Tools executed by Anthropic itself - their outcome is part of the answer text
                    break;
                default:
                    $this->warnings[] = 'Dropped unsupported response content "' . ($block->type ?? 'unknown') . '".';
            }
        }

        $text = static::getText($blocks);
        if ($sources !== []) {
            $text .= "\n\nSources:\n- " . implode("\n- ", array_unique($sources));
        }
        $adapterData = [];
        if ($hasThinking) {
            $adapterData[self::ADAPTER_DATA_KEY] = ['model' => $this->getModelName(), 'content' => $blocks];
        }

        if ($calls !== []) {
            $this->message = AiFactory::createToolCallMessage($text, $calls, $adapterData);
        } else {
            $this->message = AiFactory::createMessage(AiMessageTypeDataType::ASSISTANT, $text, [], $adapterData);
        }
        return $this->message;
    }

    /**
     * Extracts the answer text from native content blocks
     * 
     * Claude splits answers with citations into many consecutive text blocks, so they are joined without
     * separators. Used by the request adapter too, to check if stored adapter data still matches a
     * generic message.
     * 
     * @param array<int,object> $blocks
     * @return string
     */
    public static function getText(array $blocks) : string
    {
        $text = '';
        foreach ($blocks as $block) {
            $block = (object) $block;
            if (($block->type ?? null) === 'text') {
                $text .= (string) ($block->text ?? '');
            }
        }
        return $text;
    }

    /**
     * Collects web citations as markdown links and adds warnings for other citations
     * 
     * @param mixed $citations
     * @param string[] $sources
     * @return void
     */
    private function collectCitations(mixed $citations, array &$sources) : void
    {
        foreach (is_array($citations) ? $citations : [] as $citation) {
            if (is_string($citation->url ?? null)) {
                $sources[] = '[' . ($citation->title ?? $citation->url) . '](' . $citation->url . ')';
            } else {
                $this->warnings[] = 'Dropped unsupported citation "' . ($citation->type ?? 'unknown') . '".';
            }
        }
    }
}
