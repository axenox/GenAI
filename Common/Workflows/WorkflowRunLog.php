<?php
namespace axenox\GenAI\Common\Workflows;

use exface\Core\DataTypes\DateTimeDataType;
use exface\Core\DataTypes\StringDataType;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\Interfaces\WorkbenchInterface;

/**
 * Allows recording of an audit trail of an agentic workflow run from start to completion.
 *
 * The run log captures the workflow identity, title, initiating user, correlation key, status,
 * outcome, timestamps and terminal error. Its ordered step log records each executed node with its
 * type, parent step, business subject, status, outcome, message, duration, cost and terminal error.
 * Agent steps link to their AI conversation and persist its message cost on completion. Workflow
 * steps persist the sum of their direct child costs, which recursively includes nested workflows.
 *
 * Steps may be nested through their parent step, allowing grouping by processed subject.
 */
class WorkflowRunLog
{
    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_SKIPPED = 'skipped';

    private const OBJECT_RUN = 'axenox.GenAI.AI_WORKFLOW_RUN';
    private const OBJECT_STEP = 'axenox.GenAI.AI_WORKFLOW_RUN_STEP';

    // Keeps step payloads bounded. Full content belongs in artifacts or the child conversation.
    private const MAX_TEXT_LENGTH = 2000;

    private WorkbenchInterface $workbench;

    private string $workflowAlias;

    private string $title;

    private ?string $autonomousAgentAlias = null;

    private ?string $runUid = null;

    private ?string $runModifiedOn = null;

    private int $sequenceNumber = 0;

    /**
     * Start times per step UID, used to calculate the duration without a second read.
     *
     * @var float[]
     */
    private array $stepStartTimes = [];

    /**
     * Modification timestamps per step UID, required for optimistic concurrency checks.
     *
     * @var string[]
     */
    private array $stepModifiedOn = [];

    /**
    * Node types per step UID, used to distinguish cost-bearing workflows on completion.
     *
     * @var string[]
     */
    private array $stepNodeTypes = [];

    /**
     * @param WorkbenchInterface $workbench
     * @param string $workflowAlias Stable alias of the workflow, constant while the graph is hardcoded.
     * @param string $title Human readable title of this run.
     * @param string|null $autonomousAgentAlias Alias of the agent whose autonomous configuration runs this workflow.
     */
    public function __construct(
        WorkbenchInterface $workbench,
        string $workflowAlias,
        string $title,
        ?string $autonomousAgentAlias = null
    ) {
        $this->workbench = $workbench;
        $this->workflowAlias = $workflowAlias;
        $this->title = $title;
        $this->autonomousAgentAlias = $autonomousAgentAlias;
    }

    /**
     * Looks up the autonomous configuration of the configured agent.
     * TODO: this should be rewritten/reconsidered when we improve workflow architecture
     * 
     * @return string|null
     */
    private function findAutonomousUid() : ?string
    {
        if ($this->autonomousAgentAlias === null || trim($this->autonomousAgentAlias) === '') {
            return null;
        }

        // ALIAS_WITH_NS is an SQL expression over related data, so it is only filtered on its own object.
        $agentSheet = DataSheetFactory::createFromObjectIdOrAlias($this->workbench, 'axenox.GenAI.AI_AGENT');
        $agentUidColumn = $agentSheet->getColumns()->addFromUidAttribute();
        $agentSheet->getFilters()->addConditionFromString('ALIAS_WITH_NS', $this->autonomousAgentAlias);
        $agentSheet->dataRead();
        if ($agentSheet->isEmpty()) {
            return null;
        }

        $sheet = DataSheetFactory::createFromObjectIdOrAlias($this->workbench, 'axenox.GenAI.AI_AUTONOMOUS');
        $uidColumn = $sheet->getColumns()->addFromUidAttribute();
        $sheet->getFilters()->addConditionFromString('AI_AGENT', $agentUidColumn->getValue(0));
        $sheet->dataRead();

        return $sheet->isEmpty() ? null : $uidColumn->getValue(0);
    }

    /**
    * Creates the run row and returns its UID or NULL if persistence failed.
     *
     * @param string|null $correlationKey Optional key reserved for later idempotency checks.
     * @return string|null
     */
    public function start(?string $correlationKey = null) : ?string
    {
        try {
            $sheet = DataSheetFactory::createFromObjectIdOrAlias($this->workbench, self::OBJECT_RUN);
            $sheet->addRow([
                'WORKFLOW_ALIAS' => $this->workflowAlias,
                'AI_AUTONOMOUS' => $this->findAutonomousUid(),
                'TITLE' => $this->truncate($this->title, 250),
                'STATUS' => self::STATUS_RUNNING,
                'CORRELATION_KEY' => $correlationKey,
                'USER' => $this->workbench->getSecurity()->getAuthenticatedUser()->getUid(),
                'STARTED_ON' => DateTimeDataType::now()
            ]);
            $sheet->dataCreate(false);
            $this->runUid = $sheet->getUidColumn()->getValue(0);
            $this->runModifiedOn = $sheet->getCellValue('MODIFIED_ON', 0);
        } catch (\Throwable $e) {
            $this->logFailure($e);
        }

        return $this->runUid;
    }

    /**
     * Closes the run. Must be called from a finally block so no run stays in status "running".
     *
     * @param string $status One of the STATUS_* constants.
     * @param string|null $outcome Domain specific outcome, separate from the technical status.
     * @param \Throwable|null $error Optional technical error that terminated the run.
     * @return void
     */
    public function finish(string $status, ?string $outcome = null, ?\Throwable $error = null) : void
    {
        if ($this->runUid === null) {
            return;
        }

        try {
            $sheet = DataSheetFactory::createFromObjectIdOrAlias($this->workbench, self::OBJECT_RUN);
            $sheet->addRow([
                'UID' => $this->runUid,
                'MODIFIED_ON' => $this->runModifiedOn,
                'STATUS' => $status,
                'OUTCOME' => $outcome,
                'FINISHED_ON' => DateTimeDataType::now(),
                'ERROR_MESSAGE' => $this->describeError($error)
            ]);
            $sheet->dataUpdate(false);
        } catch (\Throwable $e) {
            $this->logFailure($e);
        }
    }

    /**
    * Creates a step in status "running" and returns its UID or NULL if persistence failed.
     *
     * Pass the returned UID to `finishStep()`, or as `$parentStepUid` of nested steps. A NULL UID is
     * safe to pass on: all methods ignore it.
     *
     * @param string $nodeId Stable node identifier, e.g. `story_planner`.
    * @param string $nodeType Node type, e.g. `agent`, `action`, `decision` or `workflow`.
     * @param string|null $parentStepUid UID of the grouping step this step belongs to.
     * @param string|null $subjectKey Business subject of the step, e.g. a Jira issue key.
     * @param string|null $message Short orchestration message for the timeline.
     * @return string|null
     */
    public function startStep(
        string $nodeId,
        string $nodeType,
        ?string $parentStepUid = null,
        ?string $subjectKey = null,
        ?string $message = null
    ) : ?string {
        if ($this->runUid === null) {
            return null;
        }

        try {
            $sheet = DataSheetFactory::createFromObjectIdOrAlias($this->workbench, self::OBJECT_STEP);
            $sheet->addRow([
                'AI_WORKFLOW_RUN' => $this->runUid,
                'PARENT_STEP' => $parentStepUid,
                'SEQUENCE_NUMBER' => $this->sequenceNumber++,
                'NODE_ID' => $nodeId,
                'NODE_TYPE' => $nodeType,
                'SUBJECT_KEY' => $subjectKey,
                'STATUS' => self::STATUS_RUNNING,
                'MESSAGE' => $this->truncate($message, self::MAX_TEXT_LENGTH),
                'STARTED_ON' => DateTimeDataType::now(),
                'COST' => 0
            ]);
            $sheet->dataCreate(false);
            $stepUid = $sheet->getUidColumn()->getValue(0);
            $this->stepStartTimes[$stepUid] = microtime(true);
            $this->stepModifiedOn[$stepUid] = $sheet->getCellValue('MODIFIED_ON', 0);
            $this->stepNodeTypes[$stepUid] = $nodeType;

            return $stepUid;
        } catch (\Throwable $e) {
            $this->logFailure($e);
            return null;
        }
    }

    /**
     * Closes a step and optionally links the AI conversation created by an agent node.
     *
     * @param string|null $stepUid UID returned by `startStep()`. NULL is ignored.
     * @param string $status One of the STATUS_* constants.
     * @param string|null $outcome Domain specific outcome, e.g. `ready` or `planned`.
     * @param string|null $message Short orchestration message for the timeline.
     * @param string|null $conversationUid UID of the child AI conversation, for agent nodes.
     * @param \Throwable|null $error Optional technical error that terminated the step.
     * @return void
     */
    public function finishStep(
        ?string $stepUid,
        string $status,
        ?string $outcome = null,
        ?string $message = null,
        ?string $conversationUid = null,
        ?\Throwable $error = null
    ) : void {
        if ($stepUid === null) {
            return;
        }

        try {
            $row = [
                'UID' => $stepUid,
                'MODIFIED_ON' => $this->stepModifiedOn[$stepUid] ?? null,
                'STATUS' => $status,
                'OUTCOME' => $outcome,
                'AI_CONVERSATION' => $conversationUid,
                'FINISHED_ON' => DateTimeDataType::now(),
                'COST' => $this->calculateStepCost($stepUid, $conversationUid),
                'ERROR_MESSAGE' => $this->describeError($error)
            ];
            if ($message !== null) {
                $row['MESSAGE'] = $this->truncate($message, self::MAX_TEXT_LENGTH);
            }
            if (array_key_exists($stepUid, $this->stepStartTimes)) {
                $row['DURATION_MS'] = (int) round((microtime(true) - $this->stepStartTimes[$stepUid]) * 1000);
                unset($this->stepStartTimes[$stepUid]);
            }

            $sheet = DataSheetFactory::createFromObjectIdOrAlias($this->workbench, self::OBJECT_STEP);
            $sheet->addRow($row);
            $sheet->dataUpdate(false);
            unset($this->stepNodeTypes[$stepUid], $this->stepModifiedOn[$stepUid]);
        } catch (\Throwable $e) {
            $this->logFailure($e);
        }
    }

    /**
    * Returns the direct conversation cost or, for workflows, the sum of direct child costs.
     *
     * @param string $stepUid
     * @param string|null $conversationUid
     * @return float
     */
    private function calculateStepCost(string $stepUid, ?string $conversationUid) : float
    {
        if (($this->stepNodeTypes[$stepUid] ?? null) === 'workflow') {
            $sheet = DataSheetFactory::createFromObjectIdOrAlias($this->workbench, self::OBJECT_STEP);
            $costColumn = $sheet->getColumns()->addFromExpression('COST:SUM');
            $sheet->getFilters()->addConditionFromString('PARENT_STEP', $stepUid);
            $sheet->dataRead();

            return (float) ($costColumn->getValue(0) ?? 0);
        }

        if ($conversationUid === null) {
            return 0.0;
        }

        $sheet = DataSheetFactory::createFromObjectIdOrAlias($this->workbench, 'axenox.GenAI.AI_MESSAGE');
        $costColumn = $sheet->getColumns()->addFromExpression('COST:SUM');
        $sheet->getFilters()->addConditionFromString('AI_CONVERSATION', $conversationUid);
        $sheet->dataRead();

        return (float) ($costColumn->getValue(0) ?? 0);
    }

    /**
     * Records a step that has already reached its terminal state, e.g. a decision or a skip.
     *
     * @param string $nodeId Stable node identifier.
     * @param string $nodeType Node type, e.g. `decision`.
     * @param string $status One of the STATUS_* constants.
     * @param string|null $outcome Domain specific outcome, e.g. the selected branch.
     * @param string|null $parentStepUid UID of the grouping step this step belongs to.
     * @param string|null $subjectKey Business subject of the step, e.g. a Jira issue key.
     * @param string|null $message Short orchestration message for the timeline.
     * @return string|null
     */
    public function logStep(
        string $nodeId,
        string $nodeType,
        string $status,
        ?string $outcome = null,
        ?string $parentStepUid = null,
        ?string $subjectKey = null,
        ?string $message = null
    ) : ?string {
        $stepUid = $this->startStep($nodeId, $nodeType, $parentStepUid, $subjectKey, $message);
        $this->finishStep($stepUid, $status, $outcome);

        return $stepUid;
    }

    /**
     * Returns the UID of the run or NULL if the run could not be persisted.
     *
     * @return string|null
     */
    public function getRunUid() : ?string
    {
        return $this->runUid;
    }

    /**
     * Renders a bounded, human readable description of an error.
     *
     * @param \Throwable|null $error
     * @return string|null
     */
    private function describeError(?\Throwable $error) : ?string
    {
        if ($error === null) {
            return null;
        }

        return $this->truncate(get_class($error) . ': ' . $error->getMessage(), self::MAX_TEXT_LENGTH);
    }

    /**
     * Shortens a value and marks it as truncated, so stored text stays bounded.
     *
     * @param string|null $value
     * @param int $maxLength
     * @return string|null
     */
    private function truncate(?string $value, int $maxLength) : ?string
    {
        if ($value === null) {
            return null;
        }

        return StringDataType::truncate($value, $maxLength, false, true, true);
    }

    /**
    * Logs a persistence failure without interrupting the workflow.
     *
     * @param \Throwable $error
     * @return void
     */
    private function logFailure(\Throwable $error) : void
    {
        $this->workbench->getLogger()->logException(new \RuntimeException(
            'Cannot persist agentic workflow run log: ' . $error->getMessage(),
            0,
            $error
        ));
    }
}
