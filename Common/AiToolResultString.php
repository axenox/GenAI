<?php
namespace axenox\GenAI\Common;

use axenox\GenAI\Interfaces\AiToolInterface;
use axenox\GenAI\Interfaces\AiToolResultInterface;
use exface\Core\Factories\DataTypeFactory;
use exface\Core\Interfaces\DataTypes\DataTypeInterface;
use exface\Core\Interfaces\Exceptions\ExceptionInterface;

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
    private array $errors;

    /**
     * @param AiToolInterface $tool
     * @param $value
     * @param DataTypeInterface $type
     * @param array $appendix
     * @param ExceptionInterface[] $errors
     */
    public function __construct(AiToolInterface $tool, array $arguments, mixed $value, DataTypeInterface $type = null, array $appendix = [], array $errors = [])
    {
        $this->tool = $tool;
        $this->arguments = $arguments;
        $this->value = $value;
        $this->type = $type;
        $this->appendix = $appendix;
        $this->errors = [];

        foreach ($errors as $error) {
            if ($error instanceof ExceptionInterface) {
                $this->errors[] = $error;
            }
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
        // TODO format depending on data type?
        return $this->getValue();
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
        return $this->errors;
    }

    /**
     * Adds an exception to this tool result.
     */
    public function addException(ExceptionInterface $exception): self
    {
        $this->errors[] = $exception;
        return $this;
    }

    /**
     * @inheritDoc
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
}