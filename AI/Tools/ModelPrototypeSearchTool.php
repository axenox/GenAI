<?php
namespace axenox\GenAI\AI\Tools;

use axenox\GenAI\Common\AbstractAiTool;
use axenox\GenAI\Common\AiToolResultString;
use axenox\GenAI\Exceptions\AiToolRuntimeError;
use axenox\GenAI\Interfaces\AiAgentInterface;
use axenox\GenAI\Interfaces\AiPromptInterface;
use axenox\GenAI\Interfaces\AiToolResultInterface;
use exface\Core\CommonLogic\Actions\ServiceParameter;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\DataTypes\MarkdownDataType;
use exface\Core\Factories\DataTypeFactory;
use exface\Core\Interfaces\DataSheets\DataSheetInterface;
use exface\Core\Interfaces\DataTypes\DataTypeInterface;
use exface\Core\Interfaces\WorkbenchInterface;

/**
 * Searches for UXON prototypes of a component type by alias and returns their selectors.
 * 
 * Use this tool to discover prototypes for components such as `action`, `behavior`, or `data_type` before
 * generating UXON. The result is a Markdown table containing the selectors accepted by
 * `ModelUxonPrototypeTool`.
 * 
 * By default, a single search result is automatically followed by its full UXON prototype documentation.
 * Configure `include_prototype_info_if_not_more_results_than` with a higher result threshold to include
 * details for broader searches, or set it to `0` to return only the search table.
 * 
 * @author Andrej Kabachnik
 */
class ModelPrototypeSearchTool extends AbstractAiTool
{
    public const ARG_SEARCH_QUERY = 'search_query';
    public const ARG_COMPONENT = 'component';
    public const ARG_ROWS_LIMIT = 'rows_limit';
    public const ARG_ROWS_OFFSET = 'rows_offset';

    private int $includePrototypeInfoIfNotMoreResultsThan = 1;

    /**
     * {@inheritDoc}
     * @see \axenox\GenAI\Interfaces\AiToolInterface::invoke()
     */
    public function invoke(AiAgentInterface $agent, AiPromptInterface $prompt, array $arguments): AiToolResultInterface
    {
        list($query, $component, $rowsLimit, $rowsOffset) = $arguments;
        
        if (! $query) {
            throw new AiToolRuntimeError($this, $prompt, 'Missing required argument: search_query');
        }
        if (! $component) {
            throw new AiToolRuntimeError($this, $prompt, 'Missing required argument: component');
        }
        
        $resultSheet = $this->getWorkbench()->getComponentRegistry()->searchPrototypes($query, $component, $rowsLimit, $rowsOffset);
        $markdown = $this->buildMarkdownSearchResult($resultSheet, $query, $component);
        if ($this->includePrototypeInfoIfNotMoreResultsThan > 0
            && $resultSheet->countRows() > 0
            && $resultSheet->countRows() <= $this->includePrototypeInfoIfNotMoreResultsThan
        ) {
            $selector = $resultSheet->getRows()[0]['Selector'];
            $prototypeResult = (new ModelUxonPrototypeTool($this->getWorkbench()))->invoke($agent, $prompt, [$selector]);
            $markdown .= "\n\n## Prototype details\n\n" . $prototypeResult->getValueAsMarkdown();
        }
        return new AiToolResultString($this, $arguments, $markdown, $this->getReturnDataType());
    }
    
    protected function buildMarkdownSearchResult(DataSheetInterface $resultSheet, string $query, string $component) : string
    {
        $rows = [];
        $colTitles = [];
        $selectorColTitle = null;
        foreach ($resultSheet->getColumns() as $col) {
            if ($col->getHidden()) {
                continue;
            }
            if ($col->isAttribute()) {
                $colTitles[$col->getName()] = $col->getAttribute()->getName();
            } else {
                $colTitles[$col->getName()] = $col->getName();
            }
            if ($col->getName() === 'Selector') {
                $colTitles[$col->getName()] = 'Selector (' . $colTitles[$col->getName()] . ')';
                $selectorHint = "Column `{$colTitles[$col->getName()]}` contains the selector of the prototype, which can be used to retrieve detailed information about it.";
            }
        }
        foreach ($resultSheet->getRows() as $i => $row) {
            foreach ($row as $colName => $colValue) {
                if (! isset($colTitles[$colName])) {
                    continue;
                }
                $rows[$i][$colTitles[$colName]] = $colValue;
            }
        }
        $md = <<<MD
Search results for "{$query}" in prototypes of component "{$component}". {$selectorHint}


MD;

        $md .= MarkdownDataType::buildMarkdownTableFromArray($rows);
        return $md;
    }

    /**
     * {@inheritDoc}
     * @see \axenox\GenAI\Common\AbstractAiTool::getArgumentsTemplates()
     */
    protected static function getArgumentsTemplates(WorkbenchInterface $workbench): array
    {
        $self = new self($workbench);
        $searchableComponents = $workbench->getComponentRegistry()->getComponentKeys('search_prototype_data');

        return [
            (new ServiceParameter($self))
                ->setName(self::ARG_SEARCH_QUERY)
                ->setDescription('Search term to find prototypes by alias (without namespace!)')
                ->setRequired(true)
                ->setExamples([
                    'StateMachineBehavior',
                    'ReadData'
                ]),
            (new ServiceParameter($self))
                ->setName(self::ARG_COMPONENT)
                ->setDescription('Component type (e.g. `action`)')
                ->setDataType(new UxonObject([
                    'alias' => 'exface.Core.GenericStringEnum',
                    'values' => array_combine($searchableComponents, $searchableComponents)
                ])),
            (new ServiceParameter($self))
                ->setName(self::ARG_ROWS_LIMIT)
                ->setDescription('Optional max number of rows to return (defaults to 50, capped by DataSheetReadTool)')
                ->setRequired(false),
            (new ServiceParameter($self))
                ->setName(self::ARG_ROWS_OFFSET)
                ->setDescription('Optional pagination offset (defaults to 0)')
                ->setRequired(false)
        ];
    }

    /**
     * @inheritDoc
     */
    public function getReturnDataType(): DataTypeInterface
    {
        return DataTypeFactory::createFromPrototype($this->getWorkbench(), MarkdownDataType::class);
    }

    /**
     * Append component details if the search result was very narrow - this saves a very probably next call to ModelUxonPrototypeTool.
     * 
     * Set to 0 to NEVER include prototype details, or to a higher number to include them if the search result has that many or fewer rows.
     * 
     * @uxon-property include_prototype_info_if_not_more_results_than
     * @uxon-type integer
    * @uxon-default 1
     * 
     * @param int $count
     * @return void
     */
    protected function setIncludePrototypeInfoIfNotMoreResultsThan(int $count): void
    {
        $this->includePrototypeInfoIfNotMoreResultsThan = $count;
    }
}