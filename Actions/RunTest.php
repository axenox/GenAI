<?php
namespace axenox\GenAI\Actions;

use exface\Core\CommonLogic\AbstractActionDeferred;
use exface\Core\CommonLogic\DataSheets\DataCollector;
use exface\Core\Factories\MetaObjectFactory;
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
        
        $collector = new DataCollector(MetaObjectFactory::createFromString($this->getWorkbench(), 'axenox.GenAI.AI_TEST_CASE'));
        
        yield 'Testing not really implemented yet!';
    }
}