<?php
namespace axenox\GenAI\Common\ApiAdapters;

use axenox\GenAI\Common\AiToolCall;
use axenox\GenAI\DataTypes\AiFinishReasonDataType;
use axenox\GenAI\DataTypes\AiMessageTypeDataType;
use axenox\GenAI\Exceptions\AiModelRefusalError;
use axenox\GenAI\Exceptions\AiProviderDataQueryError;
use axenox\GenAI\Factories\AiFactory;
use axenox\GenAI\Interfaces\AiMessageInterface;
use axenox\GenAI\Interfaces\AiQueryInterface;
use axenox\GenAI\Interfaces\HttpResponseAdapterInterface;
use exface\Core\CommonLogic\Filesystem\InMemoryFile;
use exface\Core\DataTypes\JsonDataType;
use Psr\Http\Message\ResponseInterface;

/**
 * Derives a generic response message from an OpenAI Chat Completions response
 * 
 * - Refusals become text, URL citations are appended as a list of sources.
 * - Audio answers are attached as files, their transcript becomes the text if there is no other text.
 * - Unfinished answers (`length`) keep their text and report `AiFinishReasonDataType::TRUNCATED`.
 * 
 * This adapter keeps no adapter data: everything it needs to continue is in the generic message.
 * 
 * @author Andrej Kabachnik
 */
class CompletionsApiResponseAdapter implements HttpResponseAdapterInterface
{
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
        $finishReason = $this->getNativeFinishReason();
        $model = $this->getModelName();
        switch (true) {
            case $finishReason === 'content_filter':
                return new AiModelRefusalError($query, 'The model refused to answer due to content filtering.', $e, false, $model, 'completions', $finishReason);
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
        return in_array($this->getNativeFinishReason(), ['stop', 'tool_calls', 'length'], true) === false;
    }

    /**
     * Returns the native finish reason of the first choice
     * 
     * @return string
     */
    protected function getNativeFinishReason() : string
    {
        return (string) ($this->json['choices'][0]['finish_reason'] ?? 'unknown');
    }

    /**
     * {@inheritDoc}
     * @see HttpResponseAdapterInterface::getFinishReason()
     */
    public function getFinishReason() : string
    {
        switch ($this->getNativeFinishReason()) {
            case 'stop': return AiFinishReasonDataType::COMPLETED;
            case 'tool_calls':
            case 'function_call': return AiFinishReasonDataType::TOOL_CALLS;
            case 'length': return AiFinishReasonDataType::TRUNCATED;
            case 'content_filter': return AiFinishReasonDataType::REFUSED;
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
        return (int) ($this->json['usage']['prompt_tokens'] ?? 0);
    }

    /**
     * {@inheritDoc}
     * @see HttpResponseAdapterInterface::getTokensInAnswer()
     */
    public function getTokensInAnswer() : int
    {
        return (int) ($this->json['usage']['completion_tokens'] ?? 0);
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
        $native = $this->wire->choices[0]->message ?? new \stdClass();
        $texts = [];
        $sources = [];
        $attachments = [];

        $content = $native->content ?? null;
        if (is_string($content)) {
            $texts[] = $content;
        } elseif (is_array($content)) {
            foreach ($content as $block) {
                if (($block->type ?? null) === 'text') {
                    $texts[] = (string) ($block->text ?? '');
                    $this->collectAnnotations($block->annotations ?? [], $sources);
                } elseif (($block->type ?? null) === 'refusal') {
                    $texts[] = (string) ($block->refusal ?? '');
                } else {
                    $this->warnings[] = 'Dropped unsupported response content "' . ($block->type ?? 'unknown') . '".';
                }
            }
        }
        if (! empty($native->refusal) && is_string($native->refusal)) {
            $texts[] = $native->refusal;
        }
        $this->collectAnnotations($native->annotations ?? [], $sources);
        if (isset($native->audio) && is_object($native->audio)) {
            $audio = $native->audio;
            if (is_string($audio->data ?? null) && false !== $binary = base64_decode($audio->data, true)) {
                $attachments[] = new InMemoryFile($binary, 'audio_' . ($audio->id ?? '1') . '.wav', 'audio/wav');
            }
            if ($texts === [] && is_string($audio->transcript ?? null)) {
                $texts[] = $audio->transcript;
            }
        }

        $text = implode("\n", $texts);
        if ($sources !== []) {
            $text .= "\n\nSources:\n- " . implode("\n- ", array_unique($sources));
        }

        $calls = $this->buildToolCalls($native);
        if ($calls !== []) {
            if ($attachments !== []) {
                $this->warnings[] = 'Dropped ' . count($attachments) . ' generated file(s) from a tool call response.';
            }
            $this->message = AiFactory::createToolCallMessage($text, $calls);
        } else {
            $this->message = AiFactory::createMessage(AiMessageTypeDataType::ASSISTANT, $text, $attachments);
        }
        return $this->message;
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
            $citation = $annotation->url_citation ?? $annotation;
            if (($annotation->type ?? null) === 'url_citation' && is_string($citation->url ?? null)) {
                $sources[] = '[' . ($citation->title ?? $citation->url) . '](' . $citation->url . ')';
            } else {
                $this->warnings[] = 'Dropped unsupported response annotation "' . ($annotation->type ?? 'unknown') . '".';
            }
        }
    }

    /**
     * Creates tool calls with object arguments from the native message
     * 
     * @param object $native
     * @return AiToolCall[]
     */
    private function buildToolCalls(object $native) : array
    {
        $calls = [];
        foreach ((is_array($native->tool_calls ?? null) ? $native->tool_calls : []) as $call) {
            $function = $call->function ?? new \stdClass();
            $raw = is_string($function->arguments ?? null) ? $function->arguments : '{}';
            try {
                $arguments = json_decode($raw, false, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                $this->warnings[] = 'Arguments of tool call "' . ($function->name ?? '') . '" are not valid JSON.';
                $arguments = new \stdClass();
            }
            if (! $arguments instanceof \stdClass) {
                $arguments = (object) (is_array($arguments) ? $arguments : []);
            }
            $calls[] = new AiToolCall((string) ($function->name ?? ''), (string) ($call->id ?? ''), $arguments, $raw);
        }
        if (isset($native->function_call)) {
            $this->warnings[] = 'Dropped legacy function call from the response - use tools instead.';
        }
        return $calls;
    }
}
