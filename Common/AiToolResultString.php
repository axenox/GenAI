<?php
namespace axenox\GenAI\Common;

use axenox\GenAI\Interfaces\AiToolResultInterface;
use exface\Core\CommonLogic\Traits\ICanBeConvertedToUxonTrait;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\DataTypes\CodeDataType;
use exface\Core\DataTypes\LogLevelDataType;
use exface\Core\DataTypes\MarkdownDataType;
use exface\Core\Exceptions\InternalError;
use exface\Core\Factories\DataTypeFactory;
use exface\Core\Interfaces\DataTypes\DataTypeInterface;
use exface\Core\Interfaces\Exceptions\ExceptionInterface;
use exface\Core\Interfaces\Log\LoggerInterface;
use exface\Core\Interfaces\WorkbenchInterface;

/**
 * Retain a textual tool result with ordered diagnostics and optional supplementary instructions.
 * 
 * Results can be restored without the original tool. For example:
 *
 * ```json
 * {
 *     "class": "\\axenox\\GenAI\\Common\\AiToolResultString",
 *     "value": "Found three records",
 *     "errors": [],
 *     "warnings": []
 * }
 *  
 * ```
 * 
 * @author GitHub Copilot
 */
class AiToolResultString implements AiToolResultInterface
{
    use ICanBeConvertedToUxonTrait;

    private WorkbenchInterface $workbench;
    private string $value = '';
    private ?DataTypeInterface $type = null;
    private array $appendix = [];
    private array $exceptions = [];
    private array $errors = [];
    private array $warnings = [];
    private array $operationalErrors = [];
    private bool $failed = false;
    private ?float $durationMs = null;

    /**
     * Creates a detached result; exceptions are classified only during fresh execution.
     * 
     * @param WorkbenchInterface $workbench
     * @param mixed $value
     * @param DataTypeInterface|null $type
     * @param string[] $appendix
     * @param \Throwable[] $exceptions
     * @return void
     */
    public function __construct(WorkbenchInterface $workbench, mixed $value = '', ?DataTypeInterface $type = null, array $appendix = [], array $exceptions = [])
    {
        $this->workbench = $workbench;
        $this->setValue($value);
        $this->type = $type;
        $this->appendix = $appendix;
        foreach ($exceptions as $exception) {
            $this->addException($exception);
        }
    }

    /**
     * {@inheritDoc}
     * @see AiToolResultInterface::getValue()
     */
    public function getValue() : string
    {
        return $this->value;
    }

    /**
     * Retain the complete textual output, not a monitoring preview.
     * 
     * @uxon-property value
     * @uxon-type string
     * 
     * @throws \InvalidArgumentException
     * @param mixed $value
     * @return void
     */
    protected function setValue(mixed $value) : void
    {
        if (!is_scalar($value) && $value !== null) {
            throw new \InvalidArgumentException('A string tool result requires a scalar value.');
        }
        $this->value = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
    }

    /**
     * Select the data type used to display the retained output.
     * 
     * @uxon-property type
     * @uxon-type \exface\Core\CommonLogic\DataTypes\AbstractDataType
     * @uxon-template {"alias": "exface.Core.String"}
     * 
     * @param UxonObject $type
     * @return void
     */
    protected function setType(UxonObject $type) : void
    {
        $this->type = DataTypeFactory::createFromUxon($this->workbench, $type);
    }

    /**
     * Restore ordered supplementary instructions.
     * 
     * @uxon-property appendix
     * @uxon-type string[]
     * 
     * @param UxonObject $appendix
     * @return void
     */
    protected function setAppendix(UxonObject $appendix) : void
    {
        $this->appendix = $appendix->toArray();
    }

    /**
     * Restore all retained error messages, including captured remediation.
     * 
     * @uxon-property errors
     * @uxon-type string[]
     * 
     * @param UxonObject $errors
     * @return void
     */
    protected function setErrors(UxonObject $errors) : void
    {
        $this->errors = $this->validateMessages($errors);
    }

    /**
     * Restore all retained warning messages, including captured remediation.
     * 
     * @uxon-property warnings
     * @uxon-type string[]
     * 
     * @param UxonObject $warnings
     * @return void
     */
    protected function setWarnings(UxonObject $warnings) : void
    {
        $this->warnings = $this->validateMessages($warnings);
    }

    /**
     * Validates durable diagnostic collections without manufacturing exceptions.
     * 
     * @throws \InvalidArgumentException
     * @param UxonObject $messages
     * @return string[]
     */
    private function validateMessages(UxonObject $messages) : array
    {
        $values = $messages->toArray();
        foreach ($values as $message) {
            if (!is_string($message)) {
                throw new \InvalidArgumentException('Tool diagnostics must be message strings.');
            }
        }
        return array_values($values);
    }

    /**
     * Record rejection or execution failure independently of exception availability.
     * 
     * @uxon-property failed
     * @uxon-type boolean
     * 
     * @param bool $failed
     * @return AiToolResultInterface
     */
    public function setFailed(bool $failed) : AiToolResultInterface
    {
        $this->failed = $failed;
        return $this;
    }

    /**
     * Retain elapsed execution time in milliseconds.
     * 
     * @uxon-property duration_ms
     * @uxon-type number
     * 
     * @param float $milliseconds
     * @return AiToolResultInterface
     */
    public function setDurationMs(float $milliseconds) : AiToolResultInterface
    {
        $this->durationMs = max(0, $milliseconds);
        return $this;
    }

    /**
     * {@inheritDoc}
     * @see AiToolResultInterface::getDurationMs()
     */
    public function getDurationMs() : ?float
    {
        return $this->durationMs;
    }

    /**
     * {@inheritDoc}
     * @see AiToolResultInterface::getErrors()
     */
    public function getErrors() : array
    {
        return $this->errors;
    }

    /**
     * {@inheritDoc}
     * @see AiToolResultInterface::getWarnings()
     */
    public function getWarnings() : array
    {
        return $this->warnings;
    }

    /**
     * {@inheritDoc}
     * @see AiToolResultInterface::getExceptions()
     */
    public function getExceptions() : array
    {
        return $this->exceptions;
    }

    /**
     * {@inheritDoc}
     * @see AiToolResultInterface::addException()
     */
    public function addException(\Throwable $exception) : AiToolResultInterface
    {
        if (!$exception instanceof ExceptionInterface) {
            $exception = new InternalError('AI tool execution failed. ' . $exception->getMessage(), null, $exception);
        }
        $this->exceptions[] = $exception;
        $message = $exception->getMessage();
        if ($this->workbench->isStarted() && $exception->getAlias() !== null) {
            try {
                $hint = $exception->getMessageModel($this->workbench)->getHint();
                if ($hint !== null && trim($hint) !== '') {
                    $message .= "\nRemediation: " . trim($hint);
                }
            } catch (\Throwable $error) {
                $this->operationalErrors[] = $error;
            }
        }
        if (LogLevelDataType::compareLogLevels($exception->getLogLevel(), LoggerInterface::ERROR) >= 0) {
            $this->errors[] = $message;
            $this->failed = true;
        } else {
            $this->warnings[] = $message;
        }
        return $this;
    }

    /**
     * {@inheritDoc}
     * @see AiToolResultInterface::getOperationalErrors()
     */
    public function getOperationalErrors() : array
    {
        return $this->operationalErrors;
    }

    /**
     * {@inheritDoc}
     * @see AiToolResultInterface::getValueWithMetadata()
     */
    public function getValueWithMetadata() : string
    {
        $parts = [$this->value];
        foreach ($this->errors as $error) {
            $parts[] = 'ERROR: ' . $error;
        }
        foreach ($this->warnings as $warning) {
            $parts[] = 'WARNING: ' . $warning;
        }
        if ($this->failed) {
            $parts[] = 'Tool execution failed. Do not repeat the same call unchanged.';
        }
        return implode("\n\n", array_filter($parts, static fn(string $part) => $part !== ''));
    }

    /**
     * {@inheritDoc}
     * @see AiToolResultInterface::getValueAsMarkdown()
     */
    public function getValueAsMarkdown(bool $includeMetadata = false) : string
    {
        $value = $includeMetadata ? $this->getValueWithMetadata() : $this->value;
        $type = $this->getValueDataType();
        return MarkdownDataType::escapeCodeBlock($value, $type instanceof CodeDataType ? $type->getLanguage() : '');
    }

    /**
     * {@inheritDoc}
     * @see AiToolResultInterface::getValueDataType()
     */
    public function getValueDataType() : DataTypeInterface
    {
        return $this->type ?? ($this->type = DataTypeFactory::createBaseDataType($this->workbench));
    }

    /**
     * {@inheritDoc}
     * @see AiToolResultInterface::getAppendix()
     */
    public function getAppendix() : array
    {
        return $this->appendix;
    }

    /**
     * {@inheritDoc}
     * @see AiToolResultInterface::isFailed()
     */
    public function isFailed() : bool
    {
        return $this->failed || $this->errors !== [];
    }

    /**
     * {@inheritDoc}
     * @see AiToolResultInterface::getWorkbench()
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
        return $this->value;
    }

    /**
     * {@inheritDoc}
     * @see \exface\Core\Interfaces\iCanBeConvertedToUxon::exportUxonObject()
     */
    public function exportUxonObject()
    {
        $type = $this->getValueDataType()->exportUxonObject()->toArray();
        $type['alias'] = '\\' . get_class($this->getValueDataType());
        $data = [
            'class' => '\\' . static::class,
            'type' => $type,
            'value' => $this->value,
            'failed' => $this->isFailed(),
            'appendix' => $this->appendix,
            'errors' => $this->errors,
            'warnings' => $this->warnings
        ];
        if ($this->durationMs !== null) {
            $data['duration_ms'] = $this->durationMs;
        }
        return new UxonObject($data);
    }
}
