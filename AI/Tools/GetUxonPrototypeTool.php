<?php

namespace axenox\GenAI\AI\Tools;

use axenox\GenAI\Common\AbstractAiTool;
use exface\Core\CommonLogic\Actions\ServiceParameter;
use exface\Core\DataTypes\MarkdownDataType;
use exface\Core\Facades\DocsFacade\MarkdownPrinters\UxonPrototypeMarkdownPrinter;
use exface\Core\Factories\DataTypeFactory;
use exface\Core\Interfaces\DataTypes\DataTypeInterface;
use exface\Core\Interfaces\WorkbenchInterface;

class GetUxonPrototypeTool extends AbstractAiTool
{
    /**
     *
     * @var string
     */
    const ARG_OBJECT_SELECTOR = 'selector';
    
    /**
     *
     * @var string
     */
    const ARG_OBJECT_SELECTOR_TYPE = 'selector_type';

    public function invoke(array $arguments): string
    {
        list($selector) = $arguments;

        $printer = new UxonPrototypeMarkdownPrinter($this->workbench, $selector);
        return $printer->getMarkdown();
    }

    protected static function getArgumentsTemplates(WorkbenchInterface $workbench): array
    {
        $self = new self($workbench);
        return [
            (new ServiceParameter($self))
                ->setName(self::ARG_OBJECT_SELECTOR)
                ->setDescription('PHP class starting with `\` (e.g. `\exface\Core\Actions\ReadData`) or file path relative to vendor folder (e.g. `exface/core/Actions/ReadData.php`).')
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