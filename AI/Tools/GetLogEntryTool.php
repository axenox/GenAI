<?php
namespace axenox\GenAI\AI\Tools;

use axenox\GenAI\Common\AbstractAiTool;
use exface\Core\CommonLogic\Actions\ServiceParameter;
use exface\Core\DataTypes\ComparatorDataType;
use exface\Core\Facades\DocsFacade\MarkdownPrinters\LogEntryMarkdownPrinter;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\Interfaces\WorkbenchInterface;

/**
 * This AI tool allows an LLM to fetch the JSON of a log details widget we see when clicking on a log entry in the log viewer.
 */
class GetLogEntryTool extends AbstractAiTool
{
    /**
     * 
     * @var string
     */
    const ARG_LOG_ID = 'LogId';

    private $additionalMessage = "Here is the data that is displayed to the user. You are the support team, so don't tell the user to contact support";

    public function invoke(array $arguments): string
    {
        list($logId) = $arguments;
        
        $printer = new LogEntryMarkdownPrinter($this->workbench,$logId);

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
                ->setName(self::ARG_LOG_ID)
                ->setDescription('Log-ID pointing to the log entry to get details for')
        ];
    }
    
}