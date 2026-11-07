<?php
namespace axenox\GenAI\Common;

use axenox\GenAI\Interfaces\AiToolResultInterface;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\DataTypes\CodeDataType;
use exface\Core\DataTypes\HtmlDataType;
use exface\Core\DataTypes\LogLevelDataType;
use exface\Core\DataTypes\MarkdownDataType;
use exface\Core\Exceptions\InternalError;
use exface\Core\Factories\DataTypeFactory;
use exface\Core\Interfaces\DataTypes\DataTypeInterface;
use exface\Core\Interfaces\Exceptions\ExceptionInterface;
use exface\Core\Interfaces\Log\LoggerInterface;
use exface\Core\Interfaces\WorkbenchDependantInterface;
use exface\Core\Interfaces\WorkbenchInterface;

/**
 * Generic string AI tool result - most tools will produce strings
 * 
 */
class AiToolResultString implements AiToolResultInterface
{
    private WorkbenchInterface $workbench;
    private mixed $value;
    private ?DataTypeInterface $type;
    private array $appendix;
    /** @var ExceptionInterface[] */
    private array $exceptions;
    private array $errors = [];
    private array $warnings = [];
    private bool $failed = false;
    private ?float $durationMs = null;

    /**
     * @param WorkbenchInterface $workbench
     * @param $value
     * @param DataTypeInterface $type
     * @param array $appendix
     * @param \Throwable[] $exceptions
     */
    public function __construct(WorkbenchInterface $workbench, mixed $value = '', ?DataTypeInterface $type = null, array $appendix = [], array $exceptions = [])
    {
        $this->workbench = $workbench;
        $this->value = $value;
        $this->type = $type;
        $this->appendix = $appendix;
        $this->exceptions = [];

        foreach ($exceptions as $e) {
            $this->addException($e);
        }
    }

    /**
     * {@inheritDoc}
     * @see AiToolResultInterface::getValue()
     */
    public function getValue(): mixed
    {
        return $this->value;
    }

    /**
     * {@inheritDoc}
     * @see AiToolResultInterface::getValueWithMetadata()
     */
    public function getValueWithMetadata(): string
    {
        $value = (string) $this;
        $errors = $this->getErrors();
        $warnings = $this->getWarnings();

        $metadata = [];
        if ($errors !== []) {
            $errorStatus = $value === ''
                ? 'TOOL EXECUTION FAILED: No result was produced because errors occurred.'
                : 'TOOL EXECUTION COMPLETED WITH ERRORS: The result may be incomplete or invalid.';
            $metadata[] = $errorStatus . "\n\nErrors:\n" . implode("\n\n", $errors);
        }
        if ($this->isFailed()) {
            $metadata[] = 'The tool execution failed. Do not repeat the same call unchanged.';
        }
        if ($warnings !== []) {
            $metadata[] = "Tool warnings:\n" . implode("\n\n", $warnings);
        }
        if ($metadata === []) {
            return $value;
        }

        $metadataText = implode("\n\n", $metadata);
        return $value === '' ? $metadataText : $value . "\n\n" . $metadataText;
    }

    /**
     * {@inheritDoc}
     * @see AiToolResultInterface::getValueAsMarkdown()
     */
    public function getValueAsMarkdown(bool $includeMetadata = false): string
    {
        $type = $this->getValueDataType();
        $value = $includeMetadata ? $this->getValueWithMetadata() : (string) $this;
        switch (true) {
            case $type instanceof HtmlDataType:
            case $type instanceof MarkdownDataType:
                $markdown = $value;
                break;
            case $type instanceof CodeDataType:
                $markdown = MarkdownDataType::escapeCodeBlock($value, $type->getLanguage());
                break;
            default:
                $markdown = MarkdownDataType::escapeCodeBlock($value);
        }
        return $markdown;
    }

    /**
     * {@inheritDoc}
     * @see AiToolResultInterface::getValueDataType()
     */
    public function getValueDataType(): DataTypeInterface
    {
        if ($this->type === null) {
            $this->type = DataTypeFactory::createBaseDataType($this->getWorkbench());
        }
        return $this->type;
    }

    /**
     * {@inheritDoc}
     * @see AiToolResultInterface::getAppendix()
     */
    public function getAppendix(): array
    {
        return $this->appendix;
    }

    /**
     * {@inheritDoc}
     * @see AiToolResultInterface::getExceptions()
     */
    public function getExceptions(): array
    {
        return $this->exceptions;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

    public function getWarnings(): array
    {
        return $this->warnings;
    }

    /**
     * {@inheritDoc}
     */
    public function getDurationMs(): ?float
    {
        return $this->durationMs;
    }

    /**
     * {@inheritDoc}
     */
    public function setDurationMs(float $milliseconds): AiToolResultInterface
    {
        $this->durationMs = max(0, $milliseconds);
        return $this;
    }

    /**
     * {@inheritDoc}
     * @see AiToolResultInterface::addException()
     */
    public function addException(\Throwable $exception) : AiToolResultInterface
    {
        if (! $exception instanceof ExceptionInterface) {
            $exception = new InternalError('Error in AI tool. ' . $exception->getMessage(), null, $exception);
        }
        $logLevel = $exception->getLogLevel();
        $diagnostic = strtoupper($logLevel) . ': ' . $exception->getMessage();
        try {
            $hint = $exception->getMessageModel($this->getWorkbench())->getHint();
        } catch (\Throwable $e) {
            $hint = null;
        }
        if ($hint !== null && trim($hint) !== '') {
            $diagnostic .= "\nRemediation: " . trim($hint);
        }
        if (LogLevelDataType::compareLogLevels($logLevel, LoggerInterface::WARNING) <= 0) {
            $this->warnings[] = $diagnostic;
        } else {
            $this->errors[] = $diagnostic;
            $this->failed = true;
        }
        $this->exceptions[] = $exception;
        return $this;
    }

    /**
     * {@inheritDoc}
     * @see WorkbenchDependantInterface::getWorkbench()
     */
    public function getWorkbench()
    {
        return $this->workbench;
    }

    /**
     * {@inheritDoc}
     * @see \Stringable::__toString()
     */
    public function __toString() : string
    {
        $value = $this->getValue();
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        return is_array($value) || is_object($value) ? AiPayload::encode($value) : (string) $value;
    }

    /**
     * {@inheritDoc}
     * @see AiToolResultInterface::isFailed()
     */
    public function isFailed() : bool
    {
        return $this->failed;
    }

    public function setFailed(bool $failed): self
    {
        $this->failed = $failed;
        return $this;
    }

    public function exportUxonObject(): UxonObject
    {
        $type = $this->getValueDataType();
        $typeUxon = $type->exportUxonObject()->copy();
        if (! $typeUxon->hasProperty('alias')) {
            $typeUxon->setProperty('alias', '\\' . get_class($type));
        }
        // CodeDataType does not export its language, which controls Markdown rendering.
        if ($type instanceof CodeDataType) {
            $typeUxon->setProperty('language', $type->getLanguage());
        }
        return new UxonObject([
            'class' => '\\' . static::class,
            'type' => $typeUxon->toArray(),
            'value' => $this->value,
            'appendix' => $this->appendix,
            'errors' => $this->errors,
            'warnings' => $this->warnings,
            'failed' => $this->failed,
            'duration_ms' => $this->durationMs
        ]);
    }

    public function importUxonObject(UxonObject $uxon): void
    {
        $data = $uxon->toArray();
        if (! array_key_exists('value', $data)) {
            throw new \UnexpectedValueException('AI tool result is missing its value.');
        }
        $type = $data['type'] ?? null;
        if ($type !== null && ! $type instanceof UxonObject) {
            if (! is_array($type) && ! $type instanceof \stdClass) {
                throw new \UnexpectedValueException('AI tool result type must be a UXON object.');
            }
            $type = new UxonObject((array) $type);
        }
        $errors = $this->importMessages($data['errors'] ?? [], 'errors');
        $warnings = $this->importMessages($data['warnings'] ?? [], 'warnings');
        $appendix = $this->importMessages($data['appendix'] ?? [], 'appendix');
        if (isset($data['failed']) && ! is_bool($data['failed'])) {
            throw new \UnexpectedValueException('AI tool result failed must be a boolean.');
        }
        $duration = $data['duration_ms'] ?? null;
        if ($duration !== null && ((! is_int($duration) && ! is_float($duration)) || ! is_finite($duration) || $duration < 0)) {
            throw new \UnexpectedValueException('AI tool result duration must be a non-negative finite number.');
        }
        $this->type = $type === null ? null : DataTypeFactory::createFromUxon($this->workbench, $type);
        $this->value = $data['value'];
        $this->errors = $errors;
        $this->warnings = $warnings;
        $this->appendix = $appendix;
        $this->failed = $data['failed'] ?? $errors !== [];
        $this->durationMs = $duration === null ? null : (float) $duration;
        $this->exceptions = [];
    }

    private function importMessages(mixed $messages, string $property): array
    {
        if ($messages instanceof UxonObject) {
            $messages = $messages->toArray();
        }
        if (! is_array($messages) || ! array_is_list($messages)) {
            throw new \UnexpectedValueException('AI tool result ' . $property . ' must be an ordered string array.');
        }
        foreach ($messages as $message) {
            if (! is_string($message)) {
                throw new \UnexpectedValueException('AI tool result ' . $property . ' must contain only strings.');
            }
        }
        return $messages;
    }

    public static function getUxonSchemaClass(): ?string
    {
        return null;
    }
}