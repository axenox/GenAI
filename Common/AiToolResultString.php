<?php
namespace axenox\GenAI\Common;

use axenox\GenAI\Interfaces\AiToolInterface;
use axenox\GenAI\Interfaces\AiToolResultInterface;
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

/**
 * Generic string AI tool result - most tools will produce strings
 * 
 */
class AiToolResultString implements AiToolResultInterface
{
    private AiToolInterface $tool;
    private array $arguments;
    private mixed $value;
    private ?DataTypeInterface $type;
    private array $appendix;
    /** @var ExceptionInterface[] */
    private array $exceptions;
    private bool $hasCriticalErrors = false;

    /**
     * @param AiToolInterface $tool
     * @param $value
     * @param DataTypeInterface $type
     * @param array $appendix
     * @param \Throwable[] $exceptions
     */
    public function __construct(AiToolInterface $tool, array $arguments, mixed $value, DataTypeInterface $type = null, array $appendix = [], array $exceptions = [])
    {
        $this->tool = $tool;
        $this->arguments = $arguments;
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
     * @see AiToolResultInterface::getTool()
     */
    public function getTool(): AiToolInterface
    {
        return $this->tool;
    }

    /**
     * {@inheritDoc}
     * @see AiToolResultInterface::getArguments()
     */
    public function getArguments(): array
    {
        return $this->arguments;
    }

    /**
     * {@inheritDoc}
     * @see AiToolResultInterface::getValue()
     */
    public function getValue(): string
    {
        switch (true) {
            case is_bool($this->value):
                return $this->value ? 'true' : 'false';
                
        }
        return $this->value;
    }

    /**
     * {@inheritDoc}
     * @see AiToolResultInterface::getValueAsMarkdown()
     */
    public function getValueAsMarkdown(): string
    {
        $type = $this->getValueDataType();
        switch (true) {
            case $type instanceof HtmlDataType:
            case $type instanceof MarkdownDataType:
                $markdown = $this->getValue();
                break;
            case $type instanceof CodeDataType:
                $markdown = MarkdownDataType::escapeCodeBlock($this->getValue(), $type->getLanguage());
                break;
            default:
                $markdown = MarkdownDataType::escapeCodeBlock($this->__toString());
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

    /**
     * {@inheritDoc}
     * @see AiToolResultInterface::addException()
     */
    public function addException(\Throwable $exception) : AiToolResultInterface
    {
        if (! $exception instanceof ExceptionInterface) {
            $exception = new InternalError("Error in AI tool `{$this->tool->getName()}`. {$exception->getMessage()}", null, $exception);
        }
        if (LogLevelDataType::compareLogLevels($exception->getLogLevel(), LoggerInterface::CRITICAL) >= 0) {
            $this->hasCriticalErrors = true;
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
        return $this->tool->getWorkbench();
    }

    /**
     * {@inheritDoc}
     * @see \Stringable::__toString()
     */
    public function __toString() : string
    {
        return $this->getValue();
    }

    /**
     * {@inheritDoc}
     * @see AiToolResultInterface::isFailed()
     */
    public function isFailed() : bool
    {
        return $this->hasCriticalErrors;
    }
}