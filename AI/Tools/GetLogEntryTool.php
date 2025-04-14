<?php
namespace axenox\GenAI\AI\Tools;

use axenox\GenAI\Common\AbstractAiTool;
use exface\Core\CommonLogic\Actions\ServiceParameter;
use exface\Core\DataTypes\ComparatorDataType;
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

    public function invoke(array $arguments): string
    {
        list($logId) = $arguments;
        $logEntrySheet = DataSheetFactory::createFromObjectIdOrAlias($this->getWorkbench(), 'exface.Core.LOG_ENTRY');
        $logEntrySheet->getColumns()->addMultiple([
            'id', 'levelname', 'message', 'filepath'
        ]);
        $logEntrySheet->getFilters()->addConditionFromString('id', ComparatorDataType::EQUALS, $logId);
        $logEntrySheet->dataRead();
        $row = $logEntrySheet->getRow(0);
        $detailsPath = $this->getWorkbench()->filemanager()->getPathToLogDetailsFolder() . DIRECTORY_SEPARATOR . $row['filepath'];
        $detailsJson = file_get_contents($detailsPath);
        return $detailsJson;
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
