<?php
namespace axenox\GenAI\Common\ApiAdapters;

use axenox\GenAI\DataTypes\AiMessageTypeDataType;
use axenox\GenAI\Factories\AiFactory;
use axenox\GenAI\Interfaces\AiMessageInterface;
use axenox\GenAI\Interfaces\AiQueryInterface;
use axenox\GenAI\Interfaces\AiToolCallInterface;
use axenox\GenAI\Interfaces\AiToolCallMessageInterface;
use axenox\GenAI\Interfaces\AiToolResultInterface;
use exface\Core\Interfaces\Filesystem\FileInfoInterface;
use exface\Core\Interfaces\Filesystem\FileInterface;

/**
 * Provider-independent helpers for API adapters
 * 
 * Contains only logic, that every API needs in the same way:
 * 
 * - grouping messages by context role (skipping designer-only messages, omitting incomplete tool batches),
 * - mapping call IDs to safe unique IDs,
 * - rendering a tool result as text,
 * - checking raw vs. original tool arguments,
 * - reading attachments and replacing those, that cannot be sent, by short notes.
 * 
 * This trait must never contain API identifiers, provider fields or persistence fields. Each adapter owns
 * its native request and response formats.
 * 
 * @author Andrej Kabachnik
 */
trait ConversationApiAdapterTrait
{
    /**
     * Splits the messages of a query into instructions and the dialog (input and output) - in their original order
     * 
     * - Designer-only messages are skipped.
     * - Incomplete tool batches (calls without results) are omitted entirely - calls and results together -
     * because APIs require matching pairs. A warning is added to the query.
     * 
     * @param AiQueryInterface $query
     * @return array{0: AiMessageInterface[], 1: AiMessageInterface[]} [instructions, dialog]
     */
    protected function groupContextMessages(AiQueryInterface $query) : array
    {
        $instructions = [];
        $dialog = [];
        foreach ($query->getMessages() as $message) {
            switch (true) {
                case $message->isInstructions():
                    $instructions[] = $message;
                    break;
                case $message->isInput():
                    $dialog[] = $message;
                    break;
                case $message->isOutput():
                    if ($message instanceof AiToolCallMessageInterface && ! $message->isComplete()) {
                        $query->addWarning('Omitted ' . count($message->getCalls()) . ' tool call(s) without results from the model context' . ($message->getUid() !== '' ? ' (message ' . $message->getUid() . ')' : '') . '.');
                        // Keep what the LLM said, but not the incomplete calls
                        if ($message->getText() !== '') {
                            $dialog[] = AiFactory::createMessage(AiMessageTypeDataType::ASSISTANT, $message->getText());
                        }
                        break;
                    }
                    $dialog[] = $message;
                    break;
                default:
                    // Designer-only messages are never sent to the LLM
                    break;
            }
        }
        return [$instructions, $dialog];
    }

    /**
     * Returns the tool calls of a message if it is a (complete) tool call message or an empty array otherwise
     * 
     * @param AiMessageInterface $message
     * @return AiToolCallInterface[]
     */
    protected function getContextCalls(AiMessageInterface $message) : array
    {
        return $message instanceof AiToolCallMessageInterface ? $message->getCalls() : [];
    }

    /**
     * Maps a call ID to an ID, that is safe for APIs and unique within the request
     * 
     * IDs are kept as-is if they are already safe and unique. Otherwise a stable hash-based ID is generated.
     * The same call of the same message always gets the same ID within one request. IDs are limited to
     * 40 characters - the strictest limit of the supported APIs (OpenAI Chat Completions).
     * 
     * @param AiMessageInterface $message
     * @param string $callId
     * @param array<string,mixed> $state Mapping state of the current request - pass the same variable for all calls
     * @return string
     */
    protected function mapCallId(AiMessageInterface $message, string $callId, array &$state) : string
    {
        $scope = spl_object_id($message) . ':' . $callId;
        if (isset($state['map'][$scope])) {
            return $state['map'][$scope];
        }
        $safeId = $callId;
        if ($safeId === '' || isset($state['used'][$safeId]) || strlen($safeId) > 40 || ! preg_match('/^[a-zA-Z0-9_-]+$/D', $safeId)) {
            $seed = $message->getUid() . ':' . $callId . ':' . count($state['used'] ?? []);
            $suffix = 0;
            do {
                $safeId = 'call_' . substr(hash('sha256', $seed . ':' . $suffix++), 0, 32);
            } while (isset($state['used'][$safeId]));
        }
        $state['used'][$safeId] = true;
        $state['map'][$scope] = $safeId;
        return $safeId;
    }

    /**
     * Returns the JSON arguments of a call - the raw text if it matches the original arguments
     * 
     * If the raw text is invalid or conflicts with the original arguments, the original arguments are used.
     * 
     * @param AiToolCallInterface $call
     * @return string
     */
    protected function buildToolArgumentsJson(AiToolCallInterface $call) : string
    {
        $arguments = json_encode($this->buildToolArgumentsObject($call), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $raw = $call->getRawArguments();
        if ($raw !== null) {
            try {
                $decoded = json_decode($raw, false, 512, JSON_THROW_ON_ERROR);
                if (json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) === $arguments) {
                    return $raw;
                }
            } catch (\JsonException $e) {
                // Use the original arguments
            }
        }
        return $arguments;
    }

    /**
     * Returns the arguments of a call as a JSON object - argument lists are converted to objects with numeric keys
     * 
     * @param AiToolCallInterface $call
     * @return \stdClass
     */
    protected function buildToolArgumentsObject(AiToolCallInterface $call) : \stdClass
    {
        $arguments = $call->getOriginalArguments();
        return $arguments instanceof \stdClass ? $arguments : (object) $arguments;
    }

    /**
     * Renders a tool result as text using only the tool-result contract
     * 
     * Appendix items, that were already sent with another result of the same request, are not repeated.
     * 
     * @param AiToolResultInterface $result
     * @param array<string,bool> $appendixSeen Appendix items already sent in this request
     * @return string
     */
    protected function buildToolResultText(AiToolResultInterface $result, array &$appendixSeen) : string
    {
        $value = $result->getValue();
        if (! is_scalar($value) && $value !== null && ! is_array($value) && ! $value instanceof \stdClass) {
            $value = $value instanceof \Stringable ? (string) $value : $result->getValueAsMarkdown();
        }
        $appendix = [];
        foreach ($result->getAppendix() as $item) {
            $item = is_string($item) ? $item : json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (! isset($appendixSeen[$item])) {
                $appendixSeen[$item] = true;
                $appendix[] = $item;
            }
        }
        if ($result->getErrors() === [] && $result->getWarnings() === [] && $appendix === [] && ! $result->isFailed()) {
            return is_string($value) ? $value : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        return json_encode([
            'value' => $value, 'failed' => $result->isFailed(), 'errors' => $result->getErrors(),
            'warnings' => $result->getWarnings(), 'appendix' => $appendix
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Returns the MIME type of an attachment or `application/octet-stream` if unknown
     * 
     * @param FileInfoInterface $file
     * @return string
     */
    protected function getAttachmentMimeType(FileInfoInterface $file) : string
    {
        try {
            return $file->getMimetype() ?? 'application/octet-stream';
        } catch (\Throwable $e) {
            return 'application/octet-stream';
        }
    }

    /**
     * Returns the contents of an attachment or NULL if it cannot be sent - adding a warning to the query in that case
     * 
     * Supported MIME types may contain wildcards for subtypes - e.g. `text/*`.
     * 
     * @param AiQueryInterface $query
     * @param FileInfoInterface $file
     * @param string[]|null $supportedMimeTypes NULL to accept any type
     * @return string|null
     */
    protected function readAttachment(AiQueryInterface $query, FileInfoInterface $file, ?array $supportedMimeTypes = null) : ?string
    {
        if ($supportedMimeTypes !== null && ! $this->isMimeTypeSupported($this->getAttachmentMimeType($file), $supportedMimeTypes)) {
            $query->addWarning('Attachment "' . $file->getFilename() . '" (' . $this->getAttachmentMimeType($file) . ') cannot be sent to this model - replaced by a note.');
            return null;
        }
        try {
            if (! $file->exists()) {
                throw new \RuntimeException('File not available');
            }
            return $file instanceof FileInterface ? $file->read() : $file->openFile()->read();
        } catch (\Throwable $e) {
            $query->addWarning('Attachment "' . $file->getFilename() . '" is not available anymore - replaced by a note.');
            return null;
        }
    }

    /**
     * Returns TRUE if the MIME type matches one of the supported types - wildcards like `text/*` match all subtypes
     * 
     * MIME parameters (e.g. `; charset=utf-8`) are ignored.
     * 
     * @param string $mimeType
     * @param string[] $supportedMimeTypes
     * @return bool
     */
    protected function isMimeTypeSupported(string $mimeType, array $supportedMimeTypes) : bool
    {
        $mimeType = strtolower(trim(explode(';', $mimeType)[0]));
        foreach ($supportedMimeTypes as $supported) {
            $supported = strtolower($supported);
            if ($supported === $mimeType || (str_ends_with($supported, '/*') && str_starts_with($mimeType, substr($supported, 0, -1)))) {
                return true;
            }
        }
        return false;
    }

    /**
     * Returns a short note to send instead of an attachment, that cannot be sent
     * 
     * @param FileInfoInterface $file
     * @return string
     */
    protected function buildAttachmentNote(FileInfoInterface $file) : string
    {
        return '[' . $file->getFilename() . ' was attached but cannot be sent to this model]';
    }

    /**
     * Appends a note to a text - separated by a blank line if the text is not empty
     * 
     * @param string $text
     * @param string $note
     * @return string
     */
    protected function appendNote(string $text, string $note) : string
    {
        return $text === '' ? $note : $text . "\n\n" . $note;
    }
}
