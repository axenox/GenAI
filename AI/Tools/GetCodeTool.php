<?php

namespace axenox\GenAI\AI\Tools;

use axenox\GenAI\Common\AbstractAiTool;
use exface\Core\CommonLogic\Actions\ServiceParameter;
use exface\Core\DataTypes\MarkdownDataType;
use exface\Core\Facades\DocsFacade\MarkdownPrinters\CodeMarkdownPrinter;
use exface\Core\Facades\DocsFacade\MarkdownPrinters\ObjectMarkdownPrinter;
use exface\Core\Factories\DataTypeFactory;
use exface\Core\Interfaces\DataTypes\DataTypeInterface;
use exface\Core\Interfaces\WorkbenchInterface;

class GetCodeTool extends AbstractAiTool
{

    /**
     *
     * @var string
     */
    const ARG_CODE_PATH = 'CodePath';

    

    public function invoke(array $arguments): string
    {
        list($codePath) = $arguments;

        $printer = new CodeMarkdownPrinter($this->getWorkbench(), $codePath);
        return $printer->getMarkdown();
    }

    protected static function getArgumentsTemplates(WorkbenchInterface $workbench): array
    {
        $self = new self($workbench);
        return [
            (new ServiceParameter($self))
                ->setName(self::ARG_CODE_PATH)
                ->setDescription('Path pointing to the Code File itself to get details for')
        ];
    }

    /**
     * {@inheritDoc}
     * @see AiToolInterface::getReturnDataType()
     */
    public function getReturnDataType(): DataTypeInterface
    {
        return DataTypeFactory::createFromPrototype($this->getWorkbench(), MarkdownDataType::class);
    }
}