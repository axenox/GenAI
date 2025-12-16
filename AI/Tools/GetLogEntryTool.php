<?php
namespace axenox\GenAI\AI\Tools;

use axenox\GenAI\Common\AbstractAiTool;
use axenox\GenAI\Interfaces\AiToolInterface;
use exface\Core\CommonLogic\Actions\ServiceParameter;
use exface\Core\DataTypes\ComparatorDataType;
use exface\Core\DataTypes\MarkdownDataType;
use exface\Core\Facades\DocsFacade\MarkdownPrinters\LogEntryMarkdownPrinter;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\Factories\DataTypeFactory;
use exface\Core\Interfaces\DataTypes\DataTypeInterface;
use exface\Core\Interfaces\WorkbenchInterface;

/**
 * This AI tool allows an LLM to fetch the JSON of a log details widget we see when clicking on a log entry in the log viewer.
 */
class GetLogEntryTool extends AbstractAiTool
{
    /**
     * {@inheritDoc}
     * @see AiToolInterface::invoke()
     */
    public function invoke(array $arguments): string
    {
        list($logId, $logFilePath) = $arguments;
        
        $printer = new LogEntryMarkdownPrinter($this->workbench, $logId, $logFilePath);

        return $printer->getMarkdown();
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
                ->setName('LogId')
                ->setDescription('Log-ID pointing to the log entry to get details for'),
            (new ServiceParameter($self))
                ->setName('LogFilePath')
                ->setDescription('Path to the log file to search relative to the installation folder')
                ->setRequired(false)
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