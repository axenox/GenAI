<?php
namespace axenox\GenAI\Actions;

use axenox\GenAI\Common\AiPrompt;
use axenox\GenAI\Factories\AiFactory;
use axenox\GenAI\Interfaces\AiAgentInterface;
use exface\Core\CommonLogic\AbstractActionDeferred;
use exface\Core\CommonLogic\DataSheets\DataCollector;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\DataTypes\DateTimeDataType;
use exface\Core\Factories\MetaObjectFactory;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\Interfaces\DataSources\DataTransactionInterface;
use exface\Core\Interfaces\Tasks\ResultMessageStreamInterface;
use exface\Core\Interfaces\Tasks\TaskInterface;

/**
 * Will run selected AI test cases and store results in the test run object
 * 
 * TODO add docs
 */
class RunTest extends AbstractActionDeferred
{

    private $testCaseId = null;

    private $data = null;

    private $prompt = null;

    private $agentAlias = null;

    private $response = null;

    private $error  = null;



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

        $inputSheet = $task->getInputData();

        $inputSheet = $task->getInputData();

        $collector = new DataCollector(MetaObjectFactory::createFromString($this->getWorkbench(), 'axenox.GenAI.AI_TEST_CASE'));
        $collector->addAttributeAlias('PROMPT');
        $collector->addAttributeAlias('AI_AGENT__ALIAS_WITH_NS');
        $collector->addAttributeAlias('CONTEXT');
        $collector->collectFrom($inputSheet);
        $caseSheet = $collector->getRequiredData();

        // axenox.GenAI.SqlAssistant or with version axenox.GenAI.SqlAssistant:1.1
        $agentAlias = $caseSheet->getCellvalue('AI_AGENT__ALIAS_WITH_NS', 0);
        $agent = AiFactory::createAgentFromString($this->getWorkbench(), $agentAlias);
        $agent->setDevmode(true);
        $prompt = new AiPrompt($this->getWorkbench());
        $prompt->importUxonObject(UxonObject::fromJson($caseSheet->getCellValue('CONTEXT', 0)));
        $prompt->setPrompt($caseSheet->getCellValue('PROMPT'));
        $result = $agent->handle($prompt);

        $transaction = $this->getworkbench()->data()->startTransaction();

        $testRun = DataSheetFactory::createFromObjectIdOrAlias($this->getWorkbench(), 'axenox.GenAI.AI_TEST_RUN');

        //run test

        $row = [
            'AI_TEST_CASE' => $this->getTestCaseId($task),
            'STATUS' => 1,
            'TESTED_ON' => DateTimeDataType::now()
        ];

        $testresponse = $this->sendDeepChatRequest($task);

        $testRun->addRow($row);
        $testRun->dataCreate(false, $transaction);
        $transaction->commit();




        
        yield 'Testing not really implemented yet!';
    }
    
    protected function getPrompt(UxonObject $promptUxon) : AiPrompt
    {
        $prompt = new AiPrompt($this->getWorkbench());
        $prompt->importUxonObject($promptUxon);
        return $prompt;
    }
    
    protected function getAgent() : AiAgentInterface
    {
        
    }

    protected function sendDeepChatRequest(TaskInterface $task = null): ?array
    {
        $url = "http://localhost/exface/exface/api/aichat/axenox.GenAI." . $this->getAgentAlias($task) . "/deepchat";

        $body = [
            "messages" => [
                [
                    "role" => "user",
                    "text" =>  $this->getPrompt($task) . "TestPrompt"
                ]
            ],
            "object" => "exface.Core.CONNECTION",
            "page"   => "exface.core.connections",
            "widget" => "DataTable_DataToolbar_ButtonGroup_DataButton06_Dialog_DialogSidebar_AIChat",
            "data"   => [
                "oId"  => "0x33380000000000000000000000000000",
                "rows" => [
                    [
                        "UID" => "0x11ea72c00f0fadeca3480205857feb80"
                    ]
                ]
            ]
        ];

        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json'
            ],
            CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            CURLOPT_TIMEOUT        => 30
        ]);

        $this->response = curl_exec($ch);
        $this->error    = curl_error($ch);

        curl_close($ch);

        if ($this->error) {
            throw new \RuntimeException("DeepChat Request Error: " . $this->error);
        }

        return $this->response ? json_decode($this->response, true) : null;
    }

    protected function getTestCaseId(TaskInterface $task = null)
    {
        if($this->id === null){
            $this->getData($task);
        }
        return $this->testCaseId;
    }

    protected function getData(TaskInterface $task = null)
    {
        if($this->data === null){
            

            $id = $sheet->getCellValue('UID',0);

            $sheet->getColumns()->addMultiple([
            'AI_AGENT',
            'AI_AGENT__ALIAS',
            'PROMPT'
            ]);

            $sheet->dataRead();
            $this->data = $sheet->getRow($sheet->getColumn('UID')->findRowByValue($id));
            $this->testCaseId = $id;
        }
        return $this->data;
    }

    /*
    protected function getPrompt(TaskInterface $task = null)
    {
        if ($this->prompt === null) {
            $this->prompt = $this->getData($task)['PROMPT'];
        }
        return $this->prompt;
    }*/


    protected function getAgentAlias(TaskInterface $task = null)
    {
        if($this->agentAlias === null){
            $this->agentAlias = $this->getData($task)['AI_AGENT__ALIAS'];
        }
        return $this->agentAlias;
    }





}