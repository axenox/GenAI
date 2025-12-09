<?php
namespace axenox\GenAI\AI\Tools;

use axenox\GenAI\Common\AbstractAiTool;
use axenox\GenAI\Exceptions\AiToolConfigurationError;
use axenox\GenAI\Interfaces\AiToolInterface;
use exface\Core\CommonLogic\Actions\ServiceParameter;
use exface\Core\DataTypes\ComparatorDataType;
use exface\Core\DataTypes\HtmlDataType;
use exface\Core\DataTypes\MarkdownDataType;
use exface\Core\Exceptions\RuntimeException;
use exface\Core\Facades\DocsFacade\MarkdownPrinters\LogEntryMarkdownPrinter;
use exface\Core\Factories\ActionFactory;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\Factories\DataTypeFactory;
use exface\Core\Interfaces\Actions\iRenderTemplate;
use exface\Core\Interfaces\DataTypes\DataTypeInterface;
use exface\Core\Interfaces\WorkbenchInterface;

/**
 * This AI tool allows an LLM to request a printout of an object
 * 
 * @author Andrej Kabachnik
 */
class GetPrintPreviewTool extends AbstractAiTool
{
    private ?string $printActionAlias = null;

    /**
     * {@inheritDoc}
     * @see AiToolInterface::invoke()
     */
    public function invoke(array $arguments): string
    {
        list($docUid) = $arguments;
        
        $action = $this->getPrintAction();
        $obj = $action->getMetaObject();
        $printData = DataSheetFactory::createFromObject($obj);
        $uidCol = $printData->getColumns()->addFromUidAttribute();
        $uidCol->setValue(0, $docUid);

        $prints = $action->renderPreviewHTML($printData);
        $preview = reset($prints);
        
        $preview = str_replace(['<html>', '</html>'], '', $preview);
        
        return $preview;
    }


    /**
     * Alias of the print action - the action MUST support print previews!
     * 
     * @uxon-property print_action
     * @uxon-type metamodel:action
     * 
     * @param string $alias
     * @return $this
     */
    protected function setPrintAction(string $alias) : GetPrintPreviewTool
    {
        $this->printActionAlias = $alias;
        return $this;
    }
    
    public function getPrintAction() : ?iRenderTemplate
    {
        if ($this->printActionAlias === null) {
            return null;
        }
        $action = ActionFactory::createFromString($this->getWorkbench(), $this->printActionAlias);
        if (! $action instanceof iRenderTemplate) {
            throw new AiToolConfigurationError($this, 'Cannot use action "' . $this->printActionAlias . '" in print preview tool');
        }
        return $action;
    }

    /**
     * {@inheritDoc}
     * @see AbstractAiTool::getArgumentsTemplates()
     */
    protected static function getArgumentsTemplates(WorkbenchInterface $workbench) : array
    {
        $self = new self($workbench);
        return [
            (new ServiceParameter($self))
                ->setName('uid')
                ->setDescription('UID of document object to be printed')
        ];
    }

    /**
     * {@inheritDoc}
     * @see AiToolInterface::getReturnDataType()
     */
    public function getReturnDataType(): DataTypeInterface
    {
        return DataTypeFactory::createFromPrototype($this->getWorkbench(), HtmlDataType::class);
    }
}