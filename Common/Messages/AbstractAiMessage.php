<?php
namespace axenox\GenAI\Common\Messages;

use axenox\GenAI\Interfaces\AiMessageInterface;
use exface\Core\Interfaces\Filesystem\FileInfoInterface;

/**
 * Base class for all conversation messages: type, text, attachments and opaque adapter data.
 * 
 * Messages are created in two ways:
 * 
 * - Unsaved messages are created from domain values by `AiFactory::createMessage()` or
 * `AiFactory::createToolCallMessage()` - e.g. by response adapters.
 * - Saved messages are created by the conversation only. It assigns identity and sequence via
 * `withIdentity()` when saving and restores messages from persisted data when loading.
 * 
 * Subclasses decide the context role of the message by overriding the issers `isInstructions()`,
 * `isInput()` and `isOutput()`. By default all of them return FALSE, which means designers only.
 * 
 * @author Andrej Kabachnik
 */
abstract class AbstractAiMessage implements AiMessageInterface
{
    private string $type;
    private string $text;
    private array $attachments;
    private array $adapterData = [];
    private string $uid = '';
    private string $conversationId = '';
    private int $sequenceNumber = 0;

    /**
     * Creates an unsaved message from domain values
     * 
     * @param string $type AiMessageTypeDataType value
     * @param string $text What was said
     * @param FileInfoInterface[] $attachments
     * @param array<string,array<array-key,mixed>> $adapterData Opaque adapter data indexed by the key of its owning adapter
     * @throws \InvalidArgumentException If an attachment is not a file or adapter data is not JSON-serializable
     */
    public function __construct(string $type, string $text = '', array $attachments = [], array $adapterData = [])
    {
        foreach ($attachments as $file) {
            if (! $file instanceof FileInfoInterface) {
                throw new \InvalidArgumentException('Message attachments must be instances of FileInfoInterface.');
            }
        }
        $this->type = $type;
        $this->text = $text;
        $this->attachments = array_values($attachments);
        foreach ($adapterData as $key => $data) {
            $this->setAdapterData((string) $key, $data);
        }
    }

    /**
     * {@inheritDoc}
     * @see AiMessageInterface::getUid()
     */
    public function getUid() : string
    {
        return $this->uid;
    }

    /**
     * {@inheritDoc}
     * @see AiMessageInterface::getConversationId()
     */
    public function getConversationId() : string
    {
        return $this->conversationId;
    }

    /**
     * {@inheritDoc}
     * @see AiMessageInterface::getSequenceNumber()
     */
    public function getSequenceNumber() : int
    {
        return $this->sequenceNumber;
    }

    /**
     * {@inheritDoc}
     * @see AiMessageInterface::getType()
     */
    public function getType() : string
    {
        return $this->type;
    }

    /**
     * {@inheritDoc}
     * @see AiMessageInterface::isInstructions()
     */
    public function isInstructions() : bool
    {
        return false;
    }

    /**
     * {@inheritDoc}
     * @see AiMessageInterface::isInput()
     */
    public function isInput() : bool
    {
        return false;
    }

    /**
     * {@inheritDoc}
     * @see AiMessageInterface::isOutput()
     */
    public function isOutput() : bool
    {
        return false;
    }

    /**
     * {@inheritDoc}
     * @see AiMessageInterface::getText()
     */
    public function getText() : string
    {
        return $this->text;
    }

    /**
     * {@inheritDoc}
     * @see AiMessageInterface::getAttachments()
     */
    public function getAttachments() : array
    {
        return $this->attachments;
    }

    /**
     * {@inheritDoc}
     * @see AiMessageInterface::toMarkdown()
     */
    public function toMarkdown() : string
    {
        return $this->getText() . $this->buildAttachmentsMarkdown();
    }

    /**
     * Renders a list of attachments for designers
     * 
     * @return string
     */
    protected function buildAttachmentsMarkdown() : string
    {
        if ($this->attachments === []) {
            return '';
        }
        $md = "\n\nAttachments:\n";
        foreach ($this->attachments as $file) {
            $md .= '- ' . $file->getFilename() . ($file->exists() ? '' : ' (unavailable)') . "\n";
        }
        return rtrim($md);
    }

    /**
     * {@inheritDoc}
     * @see AiMessageInterface::getAdapterData()
     */
    public function getAdapterData(string $key) : ?array
    {
        return $this->adapterData[$key] ?? null;
    }

    /**
     * Returns the data of all adapters indexed by their keys - for the conversation only, never for adapters
     * 
     * @internal
     * @return array<string,array<array-key,mixed>>
     */
    public function getAdapterDataAll() : array
    {
        return $this->adapterData;
    }

    /**
     * Returns a copy with the identity assigned by the conversation when saving or loading
     * 
     * @internal
     * @param string $uid
     * @param string $conversationId
     * @param int $sequenceNumber
     * @return static
     */
    public function withIdentity(string $uid, string $conversationId, int $sequenceNumber) : static
    {
        $clone = clone $this;
        $clone->uid = $uid;
        $clone->conversationId = $conversationId;
        $clone->sequenceNumber = $sequenceNumber;
        return $clone;
    }

    /**
     * Returns a copy without the data of any adapter - the conversation may drop it at any time
     * 
     * @internal
     * @return static
     */
    public function withoutAdapterData() : static
    {
        $clone = clone $this;
        $clone->adapterData = [];
        return $clone;
    }

    /**
     * Returns a copy with other attachments - e.g. after the conversation saved and reloaded them
     * 
     * @internal
     * @param FileInfoInterface[] $attachments
     * @return static
     */
    public function withAttachments(array $attachments) : static
    {
        $clone = clone $this;
        $clone->attachments = array_values($attachments);
        return $clone;
    }

    /**
     * Validates and stores the opaque data of one adapter
     * 
     * Adapter data must be JSON-serializable because the conversation saves it as-is. Nested JSON objects
     * should be passed as `\stdClass` to keep the distinction between `{}` and `[]` when saved and loaded.
     * 
     * @param string $key
     * @param mixed $data
     * @throws \InvalidArgumentException
     * @return void
     */
    private function setAdapterData(string $key, mixed $data) : void
    {
        if ($key === '' || ! is_array($data)) {
            throw new \InvalidArgumentException('Adapter data must be an array stored under a non-empty adapter key.');
        }
        static::validateJsonValue($data);
        $this->adapterData[$key] = $data;
    }

    /**
     * Makes sure, a value contains JSON values only (scalars, arrays, \stdClass) and can be encoded
     * 
     * Prevents live objects like tools, SDK objects or exceptions from becoming empty JSON objects.
     * 
     * @internal
     * @param mixed $value
     * @throws \InvalidArgumentException
     * @return void
     */
    public static function validateJsonValue(mixed $value) : void
    {
        $pending = [[$value, 0]];
        while ($pending !== []) {
            [$current, $depth] = array_pop($pending);
            if (is_array($current) || $current instanceof \stdClass) {
                if ($depth >= 512) {
                    throw new \InvalidArgumentException('Message data exceeds the supported JSON depth or contains a cycle.');
                }
                foreach ($current as $child) {
                    $pending[] = [$child, $depth + 1];
                }
            } elseif ($current !== null && ! is_scalar($current)) {
                throw new \InvalidArgumentException('Message data accepts JSON values only; export typed values explicitly.');
            }
        }
        try {
            json_encode($value, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new \InvalidArgumentException('Message data must be valid JSON: ' . $error->getMessage(), 0, $error);
        }
    }
}
