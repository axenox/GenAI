<?php
namespace axenox\GenAI\Actions;

use axenox\GenAI\Common\AiPrompt;
use axenox\GenAI\Common\AiResponse;
use axenox\GenAI\Common\AiTestRating;
use axenox\GenAI\Factories\AiFactory;
use axenox\GenAI\Factories\AiTestingFactory;
use axenox\GenAI\Interfaces\AiAgentInterface;
use axenox\GenAI\Interfaces\AiResponseInterface;
use axenox\GenAI\Interfaces\AiTestMetricInterface;
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

    private $userFeedback = [];

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
        $agent = $this->getAgent($caseSheet);
        $prompt = $this->getPrompt($caseSheet);
        try{
            $result = $agent->handle($prompt);
        }catch(\Throwable $e){
            $this->getWorkbench()->getLogger()->logException($e);
            $errorMessage = $e->getMessage();
            $this->finishMessage = 'Testcase mit folgenden Fehler abgeschlossen: ' . $errorMessage;
            $result = new AiResponse($prompt, '', $e->getConversationId());
            $this->userFeedback = ['USER_RATING' => 1, 'USER_FEEDBACK' => $errorMessage];
        }
        $testCaseId = $caseSheet->getUidColumn()->getValue($rowIdx);
        //$result = $this->getAgent()->handle($this->getPrompt());
        $this->saveTestRun($result, $testCaseId);
        return $this;
    }


    /**
     * Save AI_TEST_RUN
     * 
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
            'AI_TEST_CASE' => $testCaseId,
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
        $rows = $criteriaSheet->getRows();
        foreach($criteriaSheet->getRows() as $rowNr => $row){
            $rowUID = $criteriaSheet->getUidColumn()->getValue($rowNr);
            $this->createCriteriaResult($result, $criteriaResultSheet, $criteriaSheet, $testRunUid, $rowNr);
        }
        /*
        if ($criteriaResultSheet->isEmpty() === false) {
            //$criteriaResultSheet->getColumn('AI_TEST_RUN')->setValueOnAllRows($testRunUid);
            $criteriaResultSheet->dataCreate();
        }
        */
        return $this;
    }

     /**
     * Save / create AI_TEST_RESULT
     *
     * Persists a single criterion result for the current run
     * Expects $testCriteria to be an array with at least key UID
     */
    protected function createCriteriaResult(AiResponseInterface $result, DataSheetInterface $resultSheet, DataSheetInterface $criteriaSheet, string $testRunUid, int $criteriaIdx = 0) : AbstractActionDeferred
    {

        $pathRel = $criteriaSheet->getCellValue('PROTOTYPE', $criteriaIdx);
        
        // TODO switch to selectors
        $criterion = AiTestingFactory::createCriterionFromPathRel($this->getworkbench(), $pathRel, UxonObject::fromJson($criteriaSheet->getCellValue('CONFIG_UXON', $criteriaIdx)));
        
        
        $row = [
            'AI_TEST_CRITERION' => $criteriaSheet->getUidColumn()->getValue($criteriaIdx),
            'VALUE'=> $criterion->getValue($result),
            'AI_TEST_RUN'=> $testRunUid
        ];
        
        if (!empty($this->userFeedback)) {
            $row = array_merge($row, $this->userFeedback);
        }
        
        

        $resultSheet->addRow($row);

        $resultSheet->dataCreate();

        $criteriaResultUid = $resultSheet->getUidColumn()->getValue(0);

        $resultRatings = $criterion->evaluateMetrics($result);

        
        
        foreach ($resultRatings as $resultItem) {
            $this->createTestResultRating($criteriaResultUid, $resultItem);
        }


        return $this;
    }

    protected function createTestResultRating(string $aiTestResultOid,  AiTestRating $rating) : AbstractActionDeferred
    {
        $resultRatingSheet = DataSheetFactory::createFromObjectIdOrAlias($this->getWorkbench(), 'axenox.GenAI.AI_TEST_RESULT_RATING');

        $row = [
            'NAME' => $rating->getMetric()->getName(),
            'RATING' => $rating->getRating(),
            'AI_TEST_RESULT' => $aiTestResultOid,
            'RAW_VALUE' => $rating->getCriterion()->getValue($rating->getResponse()), //TODO improve this
            'EXPLANATION' => $rating->getExplanation() ?? '',
            'PROS' => $rating->getExplanationPros() ?? '',
            'CONS' => $rating->getExplanationCons()?? '',
        ];

        $resultRatingSheet->addRow($row);

        $resultRatingSheet->dataCreate();
        return $this;
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
    protected function getAgent(DataSheetInterface $caseSheet) : AiAgentInterface
    {
        if($this->agent === null){
            // axenox.GenAI.SqlAssistant or with version axenox.GenAI.SqlAssistant:1.1
            $agentAlias = $caseSheet->getCellvalue('AI_AGENT__ALIAS_WITH_NS', 0);
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
    protected function getPrompt(DataSheetInterface $caseSheet) : AiPrompt
    {
        if($this->prompt === null)
        {
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

        /**
     * Loads a prototype class from a given relative path and uses it to extract a value from the AI response.
     *
     * @param string $prototypePathRel Relative path to the prototype file inside the vendor folder
     * @param AiResponseInterface $result The AI response object to process
     *
     * @return string Extracted value from the AI response if prototype works,
     *                otherwise returns the original AI response message
     *
     * @throws \Throwable If an unexpected error occurs (handled internally with logging)
     */
    protected function getValue(string $prototypePathRel, AiResponseInterface $result, DataSheetInterface $criteriaSheet, int $criteriaIdx = 0) : string
    {
        try{
            $pathAbs = $this->getWorkbench()->filemanager()->getPathToVendorFolder()
            . DIRECTORY_SEPARATOR . $prototypePathRel;
            $class = PhpFilePathDataType::findClassInFile($pathAbs);
            $criterion = new $class($this->getWorkbench(), UxonObject::fromJson($criteriaSheet->getCellValue('CONFIG_UXON', $criteriaIdx)));
            $value = $criterion->getValue($result);
            return $value;
        }catch(\Throwable $e) {
            $this->getWorkbench()->getLogger()->logException($e);
            $this->finishMessage = $this->finishMessage . `              Warning: `  . $e->getMessage();
            return $result->getMessage();
        }
        
    }
    
  





}