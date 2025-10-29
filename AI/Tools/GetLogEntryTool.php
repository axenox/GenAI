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

    private $additionalMessage = "Here is the data that is displayed to the user. You are the support team, so don't tell the user to contact support";

    public function invoke(array $arguments): string
    {
        list($logId) = $arguments;


        //Clean up Log Id 
        if (stripos($logId, 'log-') !== false) {
            $logId = str_ireplace('log-', '', $logId);
            $logId = strtoupper($logId);
        }

        
        $logFileSheet = DataSheetFactory::createFromObjectIdOrAlias($this->getWorkbench(), 'exface.Core.LOG');
        $logFileCol = $logFileSheet->getColumns()->addFromExpression('PATHNAME_RELATIVE');
        $logFileSheet->getFilters()->addConditionFromString('CONTENTS', $logId, ComparatorDataType::IS);
        $logFileSheet->dataRead();
        
        $logFile = $logFileCol->getValue(0);
        
        $logEntrySheet = DataSheetFactory::createFromObjectIdOrAlias($this->getWorkbench(), 'exface.Core.LOG_ENTRY');
        $logEntrySheet->getColumns()->addMultiple([
            'id', 'levelname', 'message', 'filepath'
        ]);
        $logEntrySheet->getFilters()->addConditionFromString('id',$logId, ComparatorDataType::EQUALS);
        $logEntrySheet->getFilters()->addConditionFromString('logfile', $logFile, ComparatorDataType::EQUALS);
        $logEntrySheet->dataRead();
        $row = $logEntrySheet->getRow(0);
        $detailsPath = $this->getWorkbench()->filemanager()->getPathToLogDetailsFolder(). '/' . $row['filepath'] . '.json';

        $detailsJson = file_get_contents($detailsPath);

        return $this->additionalMessage . $detailsJson;
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
