<?php
namespace axenox\GenAI\Common\Messages;

use axenox\GenAI\Common\AiToolCall;
use axenox\GenAI\DataTypes\AiMessageTypeDataType;
use axenox\GenAI\Factories\AiFactory;
use axenox\GenAI\Interfaces\AiToolCallInterface;
use axenox\GenAI\Interfaces\AiToolCallMessageInterface;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\Interfaces\Filesystem\FileInfoInterface;
use exface\Core\Interfaces\WorkbenchInterface;

/**
 * Converts messages to and from the persisted `DATA` of `axenox.GenAI.AI_MESSAGE` - used by `AiConversation` only
 * 
 * Nothing except the conversation may know this format. API adapters, agents and queries work with
 * message objects only.
 * 
 * ## Current `DATA` format
 * 
 * ```json
 * {
 *     "text": "I will check the current time.",
 *     "attachments": [{"uid": "", "path": "", "filename": "photo.png", "mime_type": "image/png", "sha256": ""}],
 *     "calls": [{"call_id": "call_1", "tool_name": "GetTime", "tool_alias": null, "arguments": {}, "raw_arguments": "{}", "execution_arguments": []}],
 *     "results": [{"call_id": "call_1", "class": "\\axenox\\GenAI\\Common\\AiToolResultString", "value": "2026-11-10 08:00:00"}],
 *     "adapters": {"<adapter key>": {}},
 *     "usage": {"model": null, "tokens_prompt": null, "tokens_completion": null, "cost": null, "finish_reason": "completed"}
 * }
 * 
 * ```
 * 
 * The conversation may add further metadata (e.g. `usage`, `diagnostics`, `mcp`, call timing). It is
 * kept when the message is updated, but it is not part of the message itself.
 * 
 * ## Legacy formats
 * 
 * When restoring, the following older formats are understood too:
 * 
 * - unreadable `DATA` - the `MESSAGE` column is used as text
 * - `content` lists with `text`, `json` and `attachment` blocks
 * - `fullJsonResponse` of structured answers
 * - `tool_calls` in the format of the OpenAI Chat Completions API (without results)
 * - the message types `tool calling` (now `tool`) and `developer` (now `system`)
 * - `provider` and `portable` entries are ignored - they are not converted to adapter data
 * 
 * @internal
 * @author Andrej Kabachnik
 */
final class AiMessageSerializer
{
    const LEGACY_TYPE_TOOL_CALLING = 'tool calling';
    const LEGACY_TYPE_DEVELOPER = 'developer';

    private WorkbenchInterface $workbench;

    /**
     * @param WorkbenchInterface $workbench
     */
    public function __construct(WorkbenchInterface $workbench)
    {
        $this->workbench = $workbench;
    }

    /**
     * Returns the persisted representation of the message itself (without conversation metadata)
     * 
     * Attachment references are not included - they are added by the conversation after it saved
     * the files.
     * 
     * @param AbstractAiMessage $message
     * @return array<string,mixed>
     */
    public function toData(AbstractAiMessage $message) : array
    {
        $data = ['text' => $message->getText()];
        if ($message instanceof AiToolCallMessageInterface) {
            $data['calls'] = [];
            foreach ($message->getCalls() as $call) {
                $data['calls'][] = $this->exportCall($call);
            }
            $data['results'] = [];
            foreach ($message->getToolResults() as $callId => $result) {
                $data['results'][] = ['call_id' => (string) $callId] + $result->exportUxonObject()->toArray();
            }
        }
        if ([] !== $adapterData = $message->getAdapterDataAll()) {
            $data['adapters'] = $adapterData;
        }
        return $data;
    }

    /**
     * Returns the persisted representation of a tool call
     * 
     * @param AiToolCallInterface $call
     * @return array<string,mixed>
     */
    public function exportCall(AiToolCallInterface $call) : array
    {
        return [
            'call_id' => $call->getCallId(),
            'tool_name' => $call->getToolName(),
            'tool_alias' => $call->getToolAlias(),
            'arguments' => $call->getOriginalArguments(),
            'raw_arguments' => $call->getRawArguments(),
            'execution_arguments' => $call->getExecutionArguments()
        ];
    }

    /**
     * Encodes `DATA` without coercing numeric strings or JSON objects/lists
     * 
     * @param array<string,mixed> $data
     * @throws \InvalidArgumentException
     * @return string
     */
    public function encode(array $data) : string
    {
        AbstractAiMessage::validateJsonValue($data);
        return json_encode((object) $data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Decodes `DATA` keeping nested JSON objects as `\stdClass`
     * 
     * Unreadable JSON is kept under `legacy.unreadable_data`, so that it is not lost when the message
     * is updated.
     * 
     * @param string|null $json
     * @return array<string,mixed>
     */
    public function decode(?string $json) : array
    {
        $json = $json ?? '';
        if (trim($json) === '') {
            return [];
        }
        try {
            $decoded = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return ['legacy' => (object) ['unreadable_data' => $json]];
        }
        return $decoded instanceof \stdClass ? (array) $decoded : ['legacy' => (object) ['unreadable_data' => $json]];
    }

    /**
     * Returns the message type of a row, normalizing legacy types
     * 
     * @param array<string,mixed> $row
     * @return string
     */
    public function getType(array $row) : string
    {
        $type = (string) ($row['ROLE'] ?? '');
        switch ($type) {
            case self::LEGACY_TYPE_TOOL_CALLING: return AiMessageTypeDataType::TOOL;
            case self::LEGACY_TYPE_DEVELOPER: return AiMessageTypeDataType::SYSTEM;
        }
        return $type;
    }

    /**
     * Returns references to stored attachments: `path`, `filename`, `mime_type` and optionally `sha256`
     * 
     * @param array<string,mixed> $data
     * @return array<int,array<string,mixed>>
     */
    public function getAttachmentReferences(array $data) : array
    {
        $refs = [];
        foreach ($this->toList($data['attachments'] ?? null) as $ref) {
            $refs[] = (array) $ref;
        }
        foreach ($this->toList($data['content'] ?? null) as $block) {
            $block = (array) $block;
            if (($block['type'] ?? null) === 'attachment') {
                $refs[] = $block;
            }
        }
        $result = [];
        foreach ($refs as $ref) {
            if (! is_string($ref['path'] ?? null) || $ref['path'] === '') {
                continue;
            }
            $result[] = [
                'uid' => $ref['uid'] ?? null,
                'path' => $ref['path'],
                'filename' => is_string($ref['filename'] ?? null) ? $ref['filename'] : basename($ref['path']),
                'mime_type' => $ref['mime_type'] ?? null,
                'sha256' => $ref['sha256'] ?? null
            ];
        }
        return $result;
    }

    /**
     * Returns TRUE if the data does not say anything about attachments - typical for very old user prompts
     * 
     * @param array<string,mixed> $data
     * @return bool
     */
    public function isMissingAttachmentInfo(array $data) : bool
    {
        return ! array_key_exists('attachments', $data) && ! array_key_exists('content', $data);
    }

    /**
     * Restores a message from a row of `axenox.GenAI.AI_MESSAGE` and its decoded `DATA`
     * 
     * Never fails for incomplete or malformed history: whatever cannot be restored is left out, so
     * the message remains visible to designers and API adapters can degrade gracefully.
     * 
     * @param array<string,mixed> $row
     * @param array<string,mixed> $data
     * @param FileInfoInterface[] $attachments
     * @return AbstractAiMessage
     */
    public function fromRow(array $row, array $data, array $attachments = []) : AbstractAiMessage
    {
        $type = $this->getType($row);
        $adapterData = $this->restoreAdapterData($data);
        switch ($type) {
            case AiMessageTypeDataType::SYSTEM:
                $message = new InstructionsMessage($this->restoreText($row, $data), $adapterData);
                break;
            case AiMessageTypeDataType::USER:
                $message = new InputMessage($this->restoreText($row, $data), $attachments, $adapterData);
                break;
            case AiMessageTypeDataType::ASSISTANT:
                $message = new OutputMessage($this->restoreText($row, $data), $attachments, $adapterData);
                break;
            case AiMessageTypeDataType::TOOL:
                [$calls, $results] = $this->restoreCalls($data);
                $message = new ToolCallMessage($this->restoreText($row, $data, ''), $calls, $results, $adapterData);
                break;
            default:
                $message = new DiagnosticMessage($type, (string) ($row['MESSAGE'] ?? ''));
                break;
        }
        return $message->withIdentity((string) ($row['UID'] ?? ''), (string) ($row['AI_CONVERSATION'] ?? ''), (int) ($row['SEQUENCE_NUMBER'] ?? 0));
    }

    /**
     * Restores what was said from the current or any legacy format
     * 
     * @param array<string,mixed> $row
     * @param array<string,mixed> $data
     * @param string|null $fallback Text to use if DATA contains no text - NULL to use the MESSAGE column
     * @return string
     */
    private function restoreText(array $row, array $data, ?string $fallback = null) : string
    {
        if (is_string($data['text'] ?? null)) {
            return $data['text'];
        }
        if (is_array($data['content'] ?? null)) {
            $parts = [];
            foreach ($data['content'] as $block) {
                $block = (array) $block;
                switch ($block['type'] ?? null) {
                    case 'text':
                        if (is_string($block['text'] ?? null)) {
                            $parts[] = $block['text'];
                        }
                        break;
                    case 'json':
                        if (array_key_exists('value', $block)) {
                            $parts[] = json_encode($block['value'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                        }
                        break;
                }
            }
            return implode("\n", $parts);
        }
        if (array_key_exists('fullJsonResponse', $data) && $data['fullJsonResponse'] !== null) {
            return json_encode($data['fullJsonResponse'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        return $fallback ?? (string) ($row['MESSAGE'] ?? '');
    }

    /**
     * Restores opaque adapter data - legacy `provider` entries are intentionally ignored
     * 
     * @param array<string,mixed> $data
     * @return array<string,array<array-key,mixed>>
     */
    private function restoreAdapterData(array $data) : array
    {
        $result = [];
        $adapters = $data['adapters'] ?? null;
        if (! $adapters instanceof \stdClass && ! is_array($adapters)) {
            return [];
        }
        foreach ($adapters as $key => $value) {
            if ($value instanceof \stdClass || is_array($value)) {
                $result[(string) $key] = (array) $value;
            }
        }
        return $result;
    }

    /**
     * Restores calls and results, skipping entries that cannot be restored
     * 
     * @param array<string,mixed> $data
     * @return array{0: AiToolCallInterface[], 1: array<string,\axenox\GenAI\Interfaces\AiToolResultInterface>}
     */
    private function restoreCalls(array $data) : array
    {
        $calls = [];
        $ids = [];
        if (is_array($data['calls'] ?? null)) {
            foreach ($data['calls'] as $entry) {
                $entry = (array) $entry;
                $id = $entry['call_id'] ?? null;
                $arguments = $entry['arguments'] ?? new \stdClass();
                if (! is_string($id) || $id === '' || isset($ids[$id]) || ! is_string($entry['tool_name'] ?? null)) {
                    continue;
                }
                if (! is_array($arguments) && ! $arguments instanceof \stdClass) {
                    continue;
                }
                $ids[$id] = true;
                $calls[] = new AiToolCall(
                    $entry['tool_name'], 
                    $id, 
                    $arguments, 
                    is_string($entry['raw_arguments'] ?? null) ? $entry['raw_arguments'] : null,
                    isset($entry['execution_arguments']) ? (array) $entry['execution_arguments'] : null,
                    is_string($entry['tool_alias'] ?? null) ? $entry['tool_alias'] : null
                );
            }
        } elseif (is_array($data['tool_calls'] ?? null)) {
            // Legacy OpenAI Chat Completions format - never had reliably retained results
            foreach ($data['tool_calls'] as $entry) {
                $entry = (array) $entry;
                $function = (array) ($entry['function'] ?? []);
                $id = (string) ($entry['id'] ?? '');
                try {
                    $raw = is_string($function['arguments'] ?? null) ? $function['arguments'] : '{}';
                    $arguments = json_decode($raw, false, 512, JSON_THROW_ON_ERROR);
                    if ($id === '' || isset($ids[$id]) || (! is_array($arguments) && ! $arguments instanceof \stdClass)) {
                        continue;
                    }
                    $ids[$id] = true;
                    $calls[] = new AiToolCall((string) ($function['name'] ?? ''), $id, $arguments, $raw);
                } catch (\Throwable $e) {
                    continue;
                }
            }
        }

        $results = [];
        foreach ((is_array($data['results'] ?? null) ? $data['results'] : []) as $entry) {
            $entry = (array) $entry;
            $id = $entry['call_id'] ?? null;
            if (! is_string($id) || ! isset($ids[$id]) || isset($results[$id])) {
                continue;
            }
            unset($entry['call_id']);
            foreach (['type', 'appendix', 'errors', 'warnings'] as $property) {
                if (isset($entry[$property])) {
                    $entry[$property] = (array) $entry[$property];
                }
            }
            try {
                $results[$id] = AiFactory::createToolResult($this->workbench, new UxonObject($entry));
            } catch (\Throwable $e) {
                continue;
            }
        }
        return [$calls, $results];
    }

    /**
     * Returns a list from a JSON list value or an empty array
     * 
     * @param mixed $value
     * @return array<int,mixed>
     */
    private function toList(mixed $value) : array
    {
        return is_array($value) && array_is_list($value) ? $value : [];
    }
}
