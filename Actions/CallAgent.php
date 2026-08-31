<?php
namespace axenox\GenAI\Actions;

use axenox\GenAI\Common\AiPrompt;
use axenox\GenAI\Factories\AiFactory;
use exface\Core\CommonLogic\AbstractAction;
use exface\Core\DataTypes\MarkdownDataType;
use exface\Core\Exceptions\Actions\ActionConfigurationError;
use exface\Core\Factories\ResultFactory;
use exface\Core\Interfaces\DataSheets\DataSheetInterface;
use exface\Core\Interfaces\DataSources\DataTransactionInterface;
use exface\Core\Interfaces\Tasks\ResultInterface;
use exface\Core\Interfaces\Tasks\TaskInterface;

/**
 * Calls an AI agent with the input DataSheet rendered as a Markdown table.
 */
class CallAgent extends AbstractAction
{
    private const PROMPT_POSITION_BEFORE_DATA_SHEET = 'before_data_sheet';
    private const PROMPT_POSITION_AFTER_DATA_SHEET = 'after_data_sheet';

    private ?string $agentAlias = null;
    private string $additionalPrompt = '';
    private string $additionalPromptPosition = self::PROMPT_POSITION_BEFORE_DATA_SHEET;

    /**
     * Calls the configured agent and returns its textual response.
     *
     * @param TaskInterface $task Action task containing the input DataSheet.
     * @param DataTransactionInterface $transaction Current action transaction.
     */
    protected function perform(TaskInterface $task, DataTransactionInterface $transaction) : ResultInterface
    {
        $inputData = $this->getInputDataSheet($task);
        $prompt = new AiPrompt($this->getWorkbench());
        $prompt->setInputData($inputData->copy());
        $prompt->setPrompt($this->buildPrompt($inputData));

        $agent = AiFactory::createAgentFromString($this->getWorkbench(), $this->getAgentAlias());
        $response = $agent->handle($prompt);

        return ResultFactory::createMessageResult($task, $response->getMessage());
    }

    /**
     * Builds the user prompt in the configured order.
     *
     * @param DataSheetInterface $dataSheet Input data to include in the prompt.
     */
    protected function buildPrompt(DataSheetInterface $dataSheet) : string
    {
        $table = $this->renderDataSheetAsMarkdownTable($dataSheet);
        $additionalPrompt = trim($this->additionalPrompt);

        if ($additionalPrompt === '') {
            return $table;
        }

        if ($this->additionalPromptPosition === self::PROMPT_POSITION_AFTER_DATA_SHEET) {
            return $table . PHP_EOL . PHP_EOL . $additionalPrompt;
        }

        return $additionalPrompt . PHP_EOL . PHP_EOL . $table;
    }

    /**
     * Renders the exact DataSheet columns and rows as a Markdown table.
     *
     * @param DataSheetInterface $dataSheet DataSheet to render.
     */
    protected function renderDataSheetAsMarkdownTable(DataSheetInterface $dataSheet) : string
    {
        $headings = [];
        $columnNames = [];

        foreach ($dataSheet->getColumns() as $column) {
            $headings[] = $column->getExpressionObj()->__toString();
            $columnNames[] = $column->getName();
        }

        if ($columnNames === []) {
            return MarkdownDataType::buildMarkdownTableFromArray([['No columns']], ['Data']);
        }

        $rows = [];
        foreach ($dataSheet->getRows() as $row) {
            $markdownRow = [];
            foreach ($columnNames as $columnName) {
                $markdownRow[] = $this->formatMarkdownCell($row[$columnName] ?? null);
            }
            $rows[] = $markdownRow;
        }

        return MarkdownDataType::buildMarkdownTableFromArray($rows, $headings);
    }

    /**
     * Converts a DataSheet cell into a stable Markdown-safe scalar value.
     *
     * @param mixed $value Cell value.
     */
    protected function formatMarkdownCell($value) : string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_scalar($value)) {
            return preg_replace('/\R/u', '\\n', (string) $value) ?? '';
        }
        if (is_object($value) && method_exists($value, '__toString')) {
            return preg_replace('/\R/u', '\\n', (string) $value) ?? '';
        }

        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';
    }

    /**
     * Returns the configured agent alias, optionally including a version constraint.
     */
    protected function getAgentAlias() : string
    {
        if ($this->agentAlias === null || trim($this->agentAlias) === '') {
            throw new ActionConfigurationError($this, 'Missing required property "agent_alias" for action "' . $this->getAliasWithNamespace() . '"!');
        }

        return $this->agentAlias;
    }

    /**
     * Selects the AI agent to call, optionally including a version after a colon.
     *
     * @uxon-property agent_alias
     * @uxon-type string
     * @uxon-required true
     *
     * @param string $agentAlias Agent alias such as axenox.GenAI.MyAgent:1.0.
     */
    protected function setAgentAlias(string $agentAlias) : CallAgent
    {
        $this->agentAlias = trim($agentAlias);
        return $this;
    }

    /**
     * Sets text to place before or after the Markdown DataSheet.
     *
     * @uxon-property additional_prompt
     * @uxon-type string
     *
     * @param string $additionalPrompt Additional instructions for the agent.
     */
    protected function setAdditionalPrompt(string $additionalPrompt) : CallAgent
    {
        $this->additionalPrompt = $additionalPrompt;
        return $this;
    }

    /**
     * Selects where the additional prompt is placed relative to the DataSheet.
     *
     * @uxon-property additional_prompt_position
     * @uxon-type [before_data_sheet,after_data_sheet]
     * @uxon-default before_data_sheet
     *
     * @param string $position Position relative to the rendered DataSheet.
     */
    protected function setAdditionalPromptPosition(string $position) : CallAgent
    {
        $allowedPositions = [
            self::PROMPT_POSITION_BEFORE_DATA_SHEET,
            self::PROMPT_POSITION_AFTER_DATA_SHEET
        ];
        if (!in_array($position, $allowedPositions, true)) {
            throw new ActionConfigurationError(
                $this,
                'Invalid value "' . $position . '" for property "additional_prompt_position": expecting "'
                . implode('" or "', $allowedPositions) . '"!'
            );
        }

        $this->additionalPromptPosition = $position;
        return $this;
    }
}