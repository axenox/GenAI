<?php
namespace axenox\GenAI\Common;

use axenox\GenAI\Events\OnAiToolCallEvent;
use axenox\GenAI\Events\OnBeforeAiToolCallEvent;
use axenox\GenAI\Exceptions\AiToolCriticalError;
use axenox\GenAI\Exceptions\AiToolNotFoundError;
use axenox\GenAI\Interfaces\AiConversationInterface;
use axenox\GenAI\Interfaces\AiTaskHandlerInterface;
use axenox\GenAI\Interfaces\AiToolCallInterface;
use axenox\GenAI\Interfaces\AiToolResultInterface;
use exface\Core\DataTypes\LogLevelDataType;
use exface\Core\Exceptions\InvalidArgumentException;
use exface\Core\Exceptions\Actions\ActionInputMissingError;
use exface\Core\Exceptions\DataTypes\DataTypeCastingError;
use exface\Core\Exceptions\DataTypes\DataTypeValidationError;
use exface\Core\Exceptions\UnexpectedValueException;
use exface\Core\Exceptions\Security\AccessDeniedError;
use exface\Core\Exceptions\RuntimeException;
use exface\Core\Interfaces\Exceptions\ExceptionInterface;
use exface\Core\Interfaces\Log\LoggerInterface;
use exface\Core\Interfaces\Tasks\TaskInterface;

/**
 * Shared invocation boundary for agent and MCP tasks with independent per-call failures.
 */
class AiToolExecutor
{
    private array $operationalErrors = [];
    private ?AiToolCall $executionCall = null;

    /** Invokes once, measures duration, and preserves results after observer failures. */
    public function execute(AiTaskHandlerInterface $handler, TaskInterface $task, AiToolCallInterface $call) : AiToolResultInterface
    {
        $this->operationalErrors = [];
        $this->executionCall = null;
        $workbench = $handler->getWorkbench();
        $start = hrtime(true);
        try {
            $tool = $handler->getTool($call->getToolName());
        } catch (AiToolNotFoundError $error) {
            $warning = new RuntimeException($error->getMessage(), null, $error);
            $warning->setLogLevel(LoggerInterface::WARNING);
            return (new AiToolResultString($workbench, '', null, [], [$warning]))->setFailed(true)
                ->setDurationMs((hrtime(true) - $start) / 1000000);
        }
        try {
            $arguments = $call instanceof AiToolCall ? $call->getExecutionArguments() : null;
            if ($arguments === null) {
                $arguments = [];
                foreach ($tool->getArguments() as $parameter) {
                    $name = $parameter->getName();
                    if (array_key_exists($name, $call->getArguments())) {
                        $arguments[] = $parameter->parseValue($call->getArguments()[$name]);
                    } elseif ($parameter->hasDefaultValue()) {
                        $arguments[] = $parameter->parseValue($parameter->getDefaultValue());
                    } elseif ($parameter->isRequired()) {
                        throw new InvalidArgumentException("Missing required tool argument '{$name}'");
                    } else {
                        $arguments[] = null;
                    }
                }
            }
        } catch (InvalidArgumentException | UnexpectedValueException | ActionInputMissingError | DataTypeCastingError | DataTypeValidationError $error) {
            $warning = new RuntimeException($error->getMessage(), null, $error);
            $warning->setLogLevel(LoggerInterface::WARNING);
            return (new AiToolResultString($workbench, '', $tool->getReturnDataType(), [], [$warning]))->setFailed(true)
                ->setDurationMs((hrtime(true) - $start) / 1000000);
        }
        $this->executionCall = new AiToolCall(
            $call->getToolName(), $call->getCallId(), $call->getArguments(),
            $call instanceof AiToolCall ? $call->getRawArguments() : null,
            $arguments, $tool->getAliasWithNamespace()
        );
        try {
            $workbench->eventManager()->dispatch(new OnBeforeAiToolCallEvent($tool));
        } catch (\Throwable $error) {
            $this->operationalErrors[] = $error;
            return (new AiToolResultString($workbench, 'Tool invocation skipped because preparation failed.'))
                ->setFailed(true)->setDurationMs((hrtime(true) - $start) / 1000000);
        }
        try {
            $result = $tool->invoke($handler, $task, $arguments);
        } catch (AccessDeniedError $error) {
            $warning = new RuntimeException('This tool call is not permitted with the current authorization.');
            $warning->setLogLevel(LoggerInterface::WARNING);
            $result = (new AiToolResultString($workbench, '', null, [], [$warning]))->setFailed(true);
        } catch (\Throwable $error) {
            if ($error instanceof AiToolCriticalError) { $error->setToolCall($call); }
            $result = new AiToolResultString($workbench, '', $tool->getReturnDataType(), [], [$error]);
            $result->setFailed(true);
        }
        $duration = (hrtime(true) - $start) / 1000000;
        $result->setDurationMs($duration);
        try {
            $workbench->eventManager()->dispatch(new OnAiToolCallEvent($result, $duration));
        } catch (\Throwable $error) {
            $this->operationalErrors[] = $error;
        }
        return $result;
    }

    /** Reports real errors through both paths without recursion or discarded completed results. */
    public static function reportError(AiConversationInterface $conversation, \Throwable $error, array $data = []) : void
    {
        try {
            $conversation->saveError($error, [], null, $data);
        } catch (\Throwable $reportingError) {
            try {
                $conversation->getTaskHandler()->getWorkbench()->getLogger()->logException($reportingError);
            } catch (\Throwable $loggerError) {
                error_log('AI conversation error reporting failed: ' . $reportingError->getMessage()
                    . '; original error: ' . $error->getMessage() . '; logger failure: ' . $loggerError->getMessage());
            }
        }
    }

    /** Correlates embedded diagnostics with separately persisted operational errors. */
    private function diagnostic(AiConversationInterface $conversation, \Throwable $error, array $context) : array
    {
        $id = bin2hex(random_bytes(16));
        $before = $conversation->getWrittenMessageIds();
        self::reportError($conversation, $error, array_replace($context, ['diagnostic_id' => $id]));
        $written = array_values(array_diff($conversation->getWrittenMessageIds(), $before));
        return [
            'id' => $id, 'source' => $context['source'], 'severity' => 'error',
            'call_id' => $context['call_id'], 'error_message_id' => $written[0] ?? null,
            'message' => $error->getMessage()
        ];
    }

    /** Coordinates detail writes around each execution and commits one combined completion. */
    public function executeBatch(AiConversationInterface $conversation, TaskInterface $task, string $messageId, array $calls) : array
    {
        $handler = $conversation->getTaskHandler();
        $batchClock = hrtime(true);
        $results = [];
        $timing = [];
        $diagnostics = [];
        $executionCalls = [];
        foreach ($calls as $call) {
            $started = date('Y-m-d H:i:s');
            $clock = hrtime(true);
            $recordId = null;
            try {
                $recordId = $conversation->saveToolCallStart($messageId, $call, ['started_on' => $started]);
            } catch (\Throwable $error) {
                $diagnostics[] = $this->diagnostic($conversation, $error, [
                    'source' => 'detail_start', 'message_id' => $messageId, 'call_id' => $call->getCallId()
                ]);
            }
            try {
                $result = $this->execute($handler, $task, $call);
            } catch (\Throwable $error) {
                $result = new AiToolResultString($handler->getWorkbench(), '', null, [], [$error]);
                $result->setFailed(true);
            }
            $duration = (hrtime(true) - $clock) / 1000000;
            if ($this->executionCall !== null) {
                $executionCalls[$call->getCallId()] = $this->executionCall->jsonSerialize();
            }
            $result->setDurationMs($duration);
            $finished = date('Y-m-d H:i:s');
            $results[$call->getCallId()] = $result;
            $timing[$call->getCallId()] = ['started_on' => $started, 'finished_on' => $finished, 'duration_ms' => $duration];
            if ($recordId !== null) {
                try {
                    $conversation->saveToolCallEnd($recordId, $result, $timing[$call->getCallId()]);
                } catch (\Throwable $error) {
                    $diagnostics[] = $this->diagnostic($conversation, $error, [
                        'source' => 'detail_end', 'message_id' => $messageId, 'call_id' => $call->getCallId()
                    ]);
                }
            }
            foreach ($this->operationalErrors as $error) {
                $diagnostics[] = $this->diagnostic($conversation, $error, [
                    'source' => 'event', 'message_id' => $messageId, 'call_id' => $call->getCallId()
                ]);
            }
            $freshErrors = array_values(array_filter($result->getExceptions(), static fn($error) =>
                $error instanceof ExceptionInterface && LogLevelDataType::compareLogLevels($error->getLogLevel(), LoggerInterface::ERROR) >= 0
            ));
            foreach ($result->getErrors() as $index => $message) {
                $error = $freshErrors[$index] ?? new RuntimeException($message);
                $diagnostics[] = $this->diagnostic($conversation, $error, [
                    'source' => 'tool', 'message_id' => $messageId, 'call_id' => $call->getCallId()
                ]);
            }
        }
        try {
            $conversation->saveToolResults($messageId, $results, [
                'finished_on' => date('Y-m-d H:i:s'), 'timing' => (object) $timing, 'diagnostics' => $diagnostics,
                'duration_ms' => (hrtime(true) - $batchClock) / 1000000, 'execution_calls' => (object) $executionCalls
            ]);
        } catch (\Throwable $error) {
            self::reportError($conversation, $error, ['source' => 'batch_completion', 'message_id' => $messageId]);
        }
        return $results;
    }
}
