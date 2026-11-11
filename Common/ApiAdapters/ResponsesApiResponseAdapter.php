<?php
namespace axenox\GenAI\Common\ApiAdapters;

use axenox\GenAI\Common\AiToolCall;
use axenox\GenAI\DataTypes\AiFinishReasonDataType;
use axenox\GenAI\DataTypes\AiMessageTypeDataType;
use axenox\GenAI\Exceptions\AiProviderDataQueryError;
use axenox\GenAI\Factories\AiFactory;
use axenox\GenAI\Interfaces\AiMessageInterface;
use axenox\GenAI\Interfaces\AiQueryInterface;
use axenox\GenAI\Interfaces\HttpResponseAdapterInterface;
use exface\Core\CommonLogic\Filesystem\InMemoryFile;
use exface\Core\DataTypes\JsonDataType;
use Psr\Http\Message\ResponseInterface;

/**
 * Derives a generic response message from an OpenAI Responses API response
 * 
 * - Refusals become text, URL citations are appended as a list of sources.
 * - Generated images (`image_generation_call`) are attached as files.
 * - Outcomes of tools executed by OpenAI itself (e.g. web search) are already part of the answer text.
 * - Unfinished answers (`incomplete`) keep their text and report `AiFinishReasonDataType::TRUNCATED`.
 * 
 * If the response contains reasoning items, all output items are kept as optional adapter data under
 * `ADAPTER_DATA_KEY`. The request adapter replays them only when the same model continues and the
 * items still match the generic message.
 * 
 * @author Andrej Kabachnik
 */
class ResponsesApiResponseAdapter implements HttpResponseAdapterInterface
{
    const ADAPTER_DATA_KEY = 'openai.responses';

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
        $status = $this->getNativeStatus();
        $model = $this->getModelName();
        switch (true) {
            case in_array($status, ['failed', 'cancelled', 'incomplete'], true):
                return new AiProviderDataQueryError($query, 'Responses API returned a non-success status: ' . $status, $e, null, $model);
            default:
                return new AiProviderDataQueryError($query, 'Error in LLM response.', $e, null, $model);
        }
    }

    /**
     * {@inheritDoc}
     * @see HttpResponseAdapterInterface::isError()
     */
    public function isError() : bool
    {
        return in_array($this->getNativeStatus(), ['completed', 'in_progress', 'queued', 'incomplete'], true) === false;
    }

    /**
     * Returns the native status of the response
     * 
     * @return string
     */
    protected function getNativeStatus() : string
    {
        return (string) ($this->json['status'] ?? 'unknown');
    }

    /**
     * {@inheritDoc}
     * @see HttpResponseAdapterInterface::getFinishReason()
     */
    public function getFinishReason() : string
    {
        switch ($this->getNativeStatus()) {
            case 'completed':
                foreach (($this->json['output'] ?? []) as $item) {
                    if (($item['type'] ?? null) === 'function_call') {
                        return AiFinishReasonDataType::TOOL_CALLS;
                    }
                }
                return $this->isRefusalOnly() ? AiFinishReasonDataType::REFUSED : AiFinishReasonDataType::COMPLETED;
            case 'incomplete':
            case 'in_progress':
            case 'queued':
                return AiFinishReasonDataType::TRUNCATED;
        }
        return AiFinishReasonDataType::ERROR;
    }

    /**
     * Returns TRUE if the model only refused to answer
     * 
     * @return bool
     */
    private function isRefusalOnly() : bool
    {
        $refused = false;
        foreach (($this->json['output'] ?? []) as $item) {
            foreach ((($item['type'] ?? null) === 'message' ? ($item['content'] ?? []) : []) as $part) {
                switch ($part['type'] ?? null) {
                    case 'refusal': $refused = true; break;
                    case 'output_text': return false;
                }
            }
        }
        return $refused;
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
        $items = is_array($this->wire->output ?? null) ? $this->wire->output : [];
        $sources = [];
        $attachments = [];
        $calls = [];
        $hasReasoning = false;
        foreach ($items as $item) {
            switch ($item->type ?? null) {
                case 'message':
                    // Text is extracted by getText() to make sure the request adapter compares the same way
                    foreach ((is_array($item->content ?? null) ? $item->content : []) as $part) {
                        if (($part->type ?? null) === 'output_text') {
                            $this->collectAnnotations($part->annotations ?? [], $sources);
                        } elseif (($part->type ?? null) !== 'refusal') {
                            $this->warnings[] = 'Dropped unsupported response content "' . ($part->type ?? 'unknown') . '".';
                        }
                    }
                    break;
                case 'function_call':
                    $calls[] = $this->buildToolCall($item);
                    break;
                case 'image_generation_call':
                    if (is_string($item->result ?? null) && false !== $binary = base64_decode($item->result, true)) {
                        $format = is_string($item->output_format ?? null) ? $item->output_format : 'png';
                        $attachments[] = new InMemoryFile($binary, 'image_' . (count($attachments) + 1) . '.' . $format, 'image/' . $format);
                    }
                    break;
                case 'reasoning':
                    $hasReasoning = true;
                    break;
                case 'web_search_call':
                case 'file_search_call':
                case 'code_interpreter_call':
                    // Tools executed by OpenAI itself - their outcome is part of the answer text
                    break;
                default:
                    $this->warnings[] = 'Dropped unsupported response item "' . ($item->type ?? 'unknown') . '".';
            }
        }

        $text = static::getText($items);
        if ($sources !== []) {
            $text .= "\n\nSources:\n- " . implode("\n- ", array_unique($sources));
        }
        $adapterData = [];
        // Keep native items only for completed responses - an unfinished response may end with a reasoning
        // item without its required following item, which the API would reject when replayed
        if ($hasReasoning && $this->getNativeStatus() === 'completed') {
            $adapterData[self::ADAPTER_DATA_KEY] = ['model' => $this->getModelName(), 'output' => $items];
        }

        if ($calls !== []) {
            if ($attachments !== []) {
                $this->warnings[] = 'Dropped ' . count($attachments) . ' generated file(s) from a tool call response.';
            }
            $this->message = AiFactory::createToolCallMessage($text, $calls, $adapterData);
        } else {
            $this->message = AiFactory::createMessage(AiMessageTypeDataType::ASSISTANT, $text, $attachments, $adapterData);
        }
        return $this->message;
    }

    /**
     * Extracts the answer text from native output items - texts and refusals of all message items
     * 
     * Used by the request adapter too, to check if stored adapter data still matches a generic message.
     * 
     * @param array<int,object> $items
     * @return string
     */
    public static function getText(array $items) : string
    {
        $texts = [];
        foreach ($items as $item) {
            $item = (object) $item;
            if (($item->type ?? null) !== 'message') {
                continue;
            }
            foreach ((is_array($item->content ?? null) ? $item->content : []) as $part) {
                $part = (object) $part;
                switch ($part->type ?? null) {
                    case 'output_text': $texts[] = (string) ($part->text ?? ''); break;
                    case 'refusal': $texts[] = (string) ($part->refusal ?? ''); break;
                }
            }
        }
        return implode("\n", $texts);
    }

    /**
     * Collects URL citations as markdown links and adds warnings for other annotations
     * 
     * @param mixed $annotations
     * @param string[] $sources
     * @return void
     */
    private function collectAnnotations(mixed $annotations, array &$sources) : void
    {
        foreach (is_array($annotations) ? $annotations : [] as $annotation) {
            if (($annotation->type ?? null) === 'url_citation' && is_string($annotation->url ?? null)) {
                $sources[] = '[' . ($annotation->title ?? $annotation->url) . '](' . $annotation->url . ')';
            } else {
                $this->warnings[] = 'Dropped unsupported response annotation "' . ($annotation->type ?? 'unknown') . '".';
            }
        }
    }

    /**
     * Creates a tool call with object arguments from a native `function_call` item
     * 
     * @param object $item
     * @return AiToolCall
     */
    private function buildToolCall(object $item) : AiToolCall
    {
        $raw = is_string($item->arguments ?? null) ? $item->arguments : '{}';
        try {
            $arguments = json_decode($raw, false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $this->warnings[] = 'Arguments of tool call "' . ($item->name ?? '') . '" are not valid JSON.';
            $arguments = new \stdClass();
        }
        if (! $arguments instanceof \stdClass) {
            $arguments = (object) (is_array($arguments) ? $arguments : []);
        }
        return new AiToolCall((string) ($item->name ?? ''), (string) ($item->call_id ?? ''), $arguments, $raw);
    }
}
