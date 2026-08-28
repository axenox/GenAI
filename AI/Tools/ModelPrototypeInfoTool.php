<?php

namespace axenox\GenAI\AI\Tools;

use axenox\GenAI\Common\AbstractAiTool;
use axenox\GenAI\Common\AiToolResultString;
use axenox\GenAI\Interfaces\AiAgentInterface;
use axenox\GenAI\Interfaces\AiPromptInterface;
use axenox\GenAI\Interfaces\AiToolResultInterface;
use exface\Core\CommonLogic\Actions\ServiceParameter;
use exface\Core\DataTypes\MarkdownDataType;
use exface\Core\Facades\DocsFacade\MarkdownPrinters\UxonPrototypeMarkdownPrinter;
use exface\Core\Factories\DataTypeFactory;
use exface\Core\Interfaces\DataTypes\DataTypeInterface;
use exface\Core\Interfaces\WorkbenchInterface;

/**
 * Returns the UXON configuration documentation for a prototype class.
 *
 * Use the fully qualified PHP class name or its file path relative to the
 * vendor folder to inspect the prototype's properties, types, and defaults.
 *
 * @author Andrej Kabachnik
 */
class ModelPrototypeInfoTool extends AbstractAiTool
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

    /**
     * {@inheritDoc}
     * @see \axenox\GenAI\Interfaces\AiToolInterface::invoke()
     */
    public function invoke(AiAgentInterface $agent, AiPromptInterface $prompt, array $arguments): AiToolResultInterface
    {
        list($selector) = $arguments;

        $printer = new UxonPrototypeMarkdownPrinter($this->getWorkbench(), $selector);
        $markdown = $printer->getMarkdown();
        
        return new AiToolResultString($this, $arguments, $markdown, $this->getReturnDataType());
    }

    /**
     * {@inheritDoc}
     * @see \axenox\GenAI\Common\AbstractAiTool::getArgumentsTemplates()
     */
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
     * @see \axenox\GenAI\Interfaces\AiToolInterface::getReturnDataType()
     */
    public function getReturnDataType(): DataTypeInterface
    {
        return DataTypeFactory::createFromPrototype($this->getWorkbench(), MarkdownDataType::class);
    }
}