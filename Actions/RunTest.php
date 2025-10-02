<?php
namespace axenox\GenAI\Actions;

use axenox\GenAI\Common\AiPrompt;
use axenox\GenAI\Factories\AiFactory;
use axenox\GenAI\Interfaces\AiAgentInterface;
use axenox\GenAI\Interfaces\AiResponseInterface;
use exface\Core\CommonLogic\AbstractActionDeferred;
use exface\Core\CommonLogic\DataSheets\DataCollector;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\DataTypes\DateTimeDataType;
use exface\Core\DataTypes\PhpFilePathDataType;
use exface\Core\Factories\MetaObjectFactory;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\Interfaces\DataSources\DataTransactionInterface;
use exface\Core\Interfaces\Tasks\ResultMessageStreamInterface;
use exface\Core\Interfaces\Tasks\TaskInterface;
use exface\Core\Interfaces\DataSheets\DataSheetInterface;
use exface\Core\DataTypes\ComparatorDataType;

/**
 * Class RunTest
 *
 * Will run selected AI test cases and store results.
 */
class RunTest extends AbstractActionDeferred
{
    /** @var string Message shown when the test run finishes */
    private $finishMessage = 'Testcase erfolgreich ausgeführt';

    private ?string $testCaseUid = null;

    private ?string $testRunUid = null;

    private ?DataSheetInterface $inputSheet = null;

    private ?DataSheetInterface $caseSheet = null;

    private ?AiAgentInterface $agent = null;

    private ?AiPrompt $prompt = null;

    private ?DataSheetInterface $criteriaSheet = null;

    protected function init()
    {
        parent::init();
        $this->setInputRowsMin(1); 
    }

    /**
     * @inheritDoc
     */
    protected function performImmediately(TaskInterface $task, DataTransactionInterface $transaction, ResultMessageStreamInterface $result): array
    {
        return [$task];
    }

    /**
     * @inheritDoc
     */
    protected function performDeferred(TaskInterface $task = null): \Generator
    {

        $this->setInputSheet($task?->getInputData());
        
        $caseSheet = $this->getCaseSheet();
        foreach ($caseSheet->getRows() as $i => $row) {
            $this->runTestCase($caseSheet, $i);
        }
        
        yield $this->finishMessage;
    }

    /**
     * Runs a single test case
     * 1 get the agent
     * 2 build the prompt
     * 3 let the agent handle the prompt
     * 4 save the run and results
     */
    protected function runTestCase(DataSheetInterface $caseSheet = null, int $rowIdx = 0) : AbstractActionDeferred
    {
        // TODO pass caseSheet to getAgent() and getPrompt()
        $agent = $this->getAgent();
        $prompt = $this->getPrompt();
        $result = $agent->handle($prompt);
        $testCaseId = $caseSheet->getUidColumn()->getValue($rowIdx);
        //$result = $this->getAgent()->handle($this->getPrompt());
        $this->saveTestRun($result, $testCaseId);
        return $this;
    }


    /**
     * Persists the test run meta data
     * Creates a row in AI_TEST_RUN and stores the created run UID on the instance
     * Then calls addCriteriaResults to store per criterion results
     */
    protected function saveTestRun(AiResponseInterface $result, string $testCaseId) : AbstractActionDeferred
    {
        $transaction = $this->getworkbench()->data()->startTransaction();

        $testRun = DataSheetFactory::createFromObjectIdOrAlias($this->getWorkbench(), 'axenox.GenAI.AI_TEST_RUN');

        $row = [
            // TODO use $testCaseId
            'AI_TEST_CASE' => $this->getTestCaseUid(),
            'STATUS' => 1,
            'TESTED_ON' => DateTimeDataType::now(),
            'AI_CONVERSATION' => $result->getConversationId()
        ];


        $testRun->addRow($row);
        $testRun->dataCreate(false, $transaction);
        $testRunuid = $testRun->getUidColumn()->getValue(0);
        $this->setTestRunUid($testRunuid);

        try {
            $this->evaluateCriteria($result, $testCaseId, $testRunuid);
        } catch (\Throwable $e) {
            $this->getWorkbench()->getLogger()->logException($e);
        }
        
        $transaction->commit();
        return $this;
    }

    /**
     * Iterates over all criteria for the case and stores a result for each one
     */
    protected function evaluateCriteria(AiResponseInterface $result, string $testCaseUid, string $testRunUid) : AbstractActionDeferred
    {
        
        $criteriaSheet = $this->getCriteriaSheet($testCaseUid);

        $criteriaResultSheet = DataSheetFactory::createFromObjectIdOrAlias($this->getworkbench(), 'axenox.GenAI.AI_TEST_RESULT');
        foreach($criteriaSheet->getRows() as $rowNr => $row){
            $this->createCriteriaResult($result, $criteriaResultSheet, $criteriaSheet, $rowNr);
        }
        if ($criteriaResultSheet->isEmpty() === false) {
            $criteriaResultSheet->getColumn('AI_TEST_RUN')->setValueOnAllRows($testRunUid);
            $criteriaResultSheet->dataCreate();
        }

        return $this;
    }

     /**
     * Persists a single criterion result for the current run
     * Expects $testCriteria to be an array with at least key UID
     */
    protected function createCriteriaResult(AiResponseInterface $result, DataSheetInterface $resultSheet, DataSheetInterface $criteriaSheet, int $criteriaIdx = 0) : DataSheetInterface
    {

        $pathRel = $criteriaSheet->getCellValue('PROTOTYPE', $criteriaIdx);
        $pathAbs = $this->getWorkbench()->filemanager()->getPathToDataFolder()
            . DIRECTORY_SEPARATOR . $pathRel;
        $class = PhpFilePathDataType::findClassInFile($pathAbs);
        $criterion = new $class($this->getWorkbench(), UxonObject::fromJson($criteriaSheet->getCellValue('CONFIG_UXON', $criteriaIdx)));
        
        $row = [
            'AI_TEST_CRITERION' => $criteriaSheet->getUidColumn()->getValue($criteriaIdx),
            'VALUE'=> $criterion->getValue()
        ];
        
        
        $resultSheet->addRow($row);

        return $this;
    }

    protected function setTestCaseUid(string $uid) : AbstractActionDeferred
    {
        $this->testCaseUid = $uid;
        return $this;
    }

    protected function getTestCaseUid() : string
    {
        if($this->testCaseUid === null){
            $this->testCaseUid = $this->getCaseSheet()->getCellValue('UID', 0); 
        }
        return $this->testCaseUid;
    }

    protected function setTestRunUid(string $uid) : AbstractActionDeferred
    {
        $this->testRunUid = $uid;
        return $this;
    }

    protected function getTestRunUid() : string
    {
        return $this->testRunUid;
    }

    protected function setInputSheet(DataSheetInterface $sheet = null) : AbstractActionDeferred
    {
        $this->inputSheet = $sheet;
        return $this;
    }

    protected function getInputSheet()
    {
        return $this->inputSheet;
    }

    /**
     * Lazily builds and caches the case sheet for the given input rows
     */
    protected function getCaseSheet() : DataSheetInterface
    {
        if($this->caseSheet === null){
            
        $collector = new DataCollector(MetaObjectFactory::createFromString($this->getWorkbench(), 'axenox.GenAI.AI_TEST_CASE'));
        $collector->addAttributeAlias('PROMPT');
        $collector->addAttributeAlias('AI_AGENT__ALIAS_WITH_NS');
        $collector->addAttributeAlias('CONTEXT');
        $collector->collectFrom($this->getInputSheet());

        $this->caseSheet = $collector->getRequiredData();
        }

        return $this->caseSheet;
    }

    /**
     * Resolves and caches the AI agent from the case sheet
     * Also enables developer mode on the agent
     */
    protected function getAgent() : AiAgentInterface
    {
        if($this->agent === null){
            // axenox.GenAI.SqlAssistant or with version axenox.GenAI.SqlAssistant:1.1
            $agentAlias = $this->getcaseSheet()->getCellvalue('AI_AGENT__ALIAS_WITH_NS', 0);
            $agent = AiFactory::createAgentFromString($this->getWorkbench(), $agentAlias);
            $agent->setDevmode(true);
            $this->agent = $agent;
        }

        return $this->agent;
    }

    /**
     * Builds and caches the AiPrompt from case sheet values
     * Parses CONTEXT as JSON and injects a page_alias
     */
    protected function getPrompt() : AiPrompt
    {
        if($this->prompt === null)
        {
            $caseSheet = $this->getCaseSheet();
            $prompt = new AiPrompt($this->getWorkbench());
            $uxonJson = $caseSheet->getCellValue('CONTEXT', 0);

            $uxonJson = $caseSheet->getCellValue('CONTEXT', 0);

            // Decode and ensure array structure and enrich page_alias
            $decoded = json_decode($uxonJson, true);
            if (!is_array($decoded)) {
                $decoded = [];
            }
            $decoded['page_alias'] = 'axenox.genai.testing';
            $uxonJson = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);


            $prompt->importUxonObject(UxonObject::fromJson($uxonJson));

            $promptText = $caseSheet->getCellValue('PROMPT',0);
            $prompt->setPrompt($promptText);
            $this->prompt = $prompt;
        }

        return $this->prompt;
    }


    /**
     * Loads and caches all criteria for the current test case
     * Adds minimal columns and filters by the current case UID
     */
    protected function getCriteriaSheet(string $testCaseUid) : DataSheetInterface
    {
        $criteriaSheet = DataSheetFactory::createFromObjectIdOrAlias($this->getworkbench(), 'axenox.GenAI.AI_TEST_CRITERION');
        $criteriaSheet->getColumns()->addMultiple([
            'UID',
            'AI_TEST_CASE',
            'PROTOTYPE',
            'CONFIG_UXON'
        ]);
        $criteriaSheet->getFilters()->addConditionFromString('AI_TEST_CASE', $testCaseUid,ComparatorDataType::EQUALS);
        $criteriaSheet->dataRead();
        
        $this->criteriaSheet = $criteriaSheet;

        return $this->criteriaSheet;
    }
    
  





}