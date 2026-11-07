<?php
namespace axenox\GenAI\Common\ApiAdapters;

use axenox\GenAI\Interfaces\AiQueryInterface;
use axenox\GenAI\Interfaces\AiToolResultInterface;
use axenox\GenAI\DataTypes\AiMessageTypeDataType;
use axenox\GenAI\Common\AiPayload;

/**
 * Shared semantic projection used inside provider adapters, never by persistence.
 */
final class AiContextRenderer
{
    /** Parses a function argument object while retaining nested objects and exact scalar types. */
    public static function arguments(string $raw) : array
    {
        $value = json_decode($raw, false, 512, JSON_THROW_ON_ERROR);
        if (!$value instanceof \stdClass) {
            throw new \UnexpectedValueException('Provider function arguments must be a JSON object.');
        }
        return (array) $value;
    }

    /** Selects eligible messages and retains typed call/result correlation. */
    public static function messages(AiQueryInterface $query, string $api) : array
    {
        $context = $query->getContextMessages();
        if ($context === []) {
            return $query->getMessages();
        }
        $messages = [];
        $typedBatches = $query->getToolResultBatches();
        foreach ($context as $message) {
            $role = $message->getRole();
            if (!in_array($role, [AiMessageTypeDataType::SYSTEM, AiMessageTypeDataType::USER, AiMessageTypeDataType::ASSISTANT, AiMessageTypeDataType::TOOLCALLING, AiMessageTypeDataType::TOOL], true)) {
                continue;
            }
            $data = $message->getData()->toArray();
            $calls = $message->getCalls();
            $results = $message->getToolResults();
            $typedResults = $typedBatches[$message->getUid()] ?? [];
            $hasFreshResults = $calls !== [];
            foreach ($calls as $call) {
                if (isset($typedResults[$call->getCallId()])) {
                    $results[$call->getCallId()] = $typedResults[$call->getCallId()];
                } else {
                    $hasFreshResults = false;
                }
            }
            $content = $data['content'] ?? ($calls !== [] ? [] : [['type' => 'text', 'text' =>
                array_key_exists('answer_json', $data) ? AiPayload::encode($data['answer_json']) : $message->getText()
            ]]);
            if (!is_array($content)) {
                throw new \LogicException('AI message content is not a normalized block list: ' . $message->getUid());
            }
            $provider = [];
            foreach ((array) ($data['provider'] ?? []) as $namespace => $extension) {
                $provider[$namespace] = (array) $extension;
            }
            if ($api !== 'responses' && isset($provider['responses']['status']) && $provider['responses']['status'] !== 'completed') {
                throw new \LogicException('Unfinished Responses API state cannot be resumed through ' . $api . '.');
            }
            if ($api !== 'claude' && ($provider['claude']['stop_reason'] ?? null) === 'pause_turn') {
                throw new \LogicException('Paused Claude state cannot be resumed through ' . $api . '.');
            }
            $text = '';
            $attachments = [];
            foreach ($content as $block) {
                $block = (array) $block;
                if (($block['type'] ?? null) === 'attachment') {
                    if (!empty($block['unavailable']) || !isset($block['contents_base64'])) {
                        throw new \LogicException('Retained attachment is unavailable for replay: ' . ($block['reason'] ?? $block['filename'] ?? $message->getUid()));
                    }
                    if ($api === 'completions') {
                        throw new \LogicException('Chat Completions does not support retained attachment replay; use Responses or Claude.');
                    }
                    $attachments[] = $block;
                    continue;
                }
                if (($block['type'] ?? null) !== 'text') {
                    throw new \LogicException('Unsupported AI content mapping to ' . $api . ': ' . ($block['type'] ?? 'unknown'));
                }
                $text .= ($text === '' ? '' : "\n") . ($block['text'] ?? '');
            }
            if ($message->isBroken() && !$hasFreshResults) {
                throw new \LogicException('Cannot replay incomplete AI message ' . $message->getUid() . '; ' . ($data['reason'] ?? 'no tool results were fabricated.'));
            }
            if ($calls !== []) {
                $wireCalls = [];
                foreach ($calls as $call) {
                    $id = $call->getCallId();
                    if (!isset($results[$id])) {
                        throw new \LogicException('Cannot replay unfinished tool call ' . $id . '; retained result is missing.');
                    }
                    $wireCalls[] = [
                        'id' => $id,
                        'type' => 'function',
                        'function' => [
                            'name' => $call->getToolName(),
                            'arguments' => $call->getRawArguments() ?? json_encode((object) $call->getArguments(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                        ],
                    ];
                }
                $messages[] = ['role' => 'assistant', 'content' => $text, 'tool_calls' => $wireCalls, '_provider' => $provider];
                $appendix = [];
                foreach ($results as $result) {
                    foreach ($result->getAppendix() as $item) {
                        if (!in_array($item, $appendix, true)) {
                            $appendix[] = $item;
                        }
                    }
                }
                $lastCallId = end($calls)->getCallId();
                foreach ($calls as $call) {
                    $result = $results[$call->getCallId()];
                    $messages[] = [
                        'role' => 'tool',
                        'tool_call_id' => $call->getCallId(),
                        'content' => self::resultText($result) . ($call->getCallId() === $lastCallId && $appendix !== [] ? "\n\n" . implode("\n\n", $appendix) : ''),
                        '_failed' => $result->isFailed(),
                    ];
                }
            } elseif ($role !== AiMessageTypeDataType::TOOL && $role !== AiMessageTypeDataType::TOOLCALLING) {
                $messages[] = ['role' => $role, 'content' => $text, '_provider' => $provider, '_attachments' => $attachments, '_content' => $content];
            }
        }
        return $messages;
    }

    /** Renders full retained values and diagnostics without mutating a result. */
    public static function resultText(AiToolResultInterface $result) : string
    {
        $value = $result->getValue();
        $text = is_string($value) ? $value : AiPayload::encode($value);
        $errors = $result->getErrors();
        $warnings = $result->getWarnings();
        if ($errors !== []) {
            $text .= "\n\nErrors:\n" . implode("\n", $errors);
        }
        if ($warnings !== []) {
            $text .= "\n\nWarnings:\n" . implode("\n", $warnings);
        }
        if ($result->isFailed()) {
            $text .= "\n\nTool execution failed. Do not repeat the same call unchanged.";
        }
        return $text;
    }
}
