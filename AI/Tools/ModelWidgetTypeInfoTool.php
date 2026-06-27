<?php
namespace axenox\GenAI\AI\Tools;

use axenox\GenAI\Common\AbstractAiTool;
use axenox\GenAI\Common\AiToolResultString;
use axenox\GenAI\Exceptions\AiToolRuntimeError;
use axenox\GenAI\Interfaces\AiAgentInterface;
use axenox\GenAI\Interfaces\AiPromptInterface;
use axenox\GenAI\Interfaces\AiToolResultInterface;
use exface\Core\CommonLogic\Actions\ServiceParameter;
use exface\Core\CommonLogic\Selectors\WidgetSelector;
use exface\Core\DataTypes\ComparatorDataType;
use exface\Core\DataTypes\MarkdownDataType;
use exface\Core\DataTypes\SortingDirectionsDataType;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\Factories\DataTypeFactory;
use exface\Core\Factories\WidgetFactory;
use exface\Core\Interfaces\DataSheets\DataSheetInterface;
use exface\Core\Interfaces\DataTypes\DataTypeInterface;
use exface\Core\Interfaces\Selectors\AliasSelectorInterface;
use exface\Core\Interfaces\WorkbenchInterface;

/**
 * Retrieve detailed documentation for a widget prototype with available properties, widget functions, presets, etc.
 * 
 * The tool provides documentation about a prototype, not a UI model. It show, what CAN be done with a widget. To 
 * get the model of a widget from the UI - a page, a dialog, etc. - add the `UiWidgetInfoTool`. 
 * 
 * This tool returns comprehensive information about a widget including:
 * 
 * - **Description**: The full documentation of the widget from its class docblock
 * - **Properties**: All available UXON properties with their types, defaults, and descriptions
 * - **Functions**: Widget functions that can be called via widget links
 * - **Presets**: Available widget presets that can be used to configure the widget
 * 
 * The idea is to use this tool together with the `WidgetOverviewConcept` to provide the LLM 
 * with a list of available widgets in the instructions. The LLM can then request detailed 
 * information about specific widgets using this tool when needed.
 * 
 * The tool reads from the following metaobjects:
 * 
 * - `exface.Core.UXON_ENTITY_ANNOTATION` - widget description
 * - `exface.Core.UXON_PROPERTY_ANNOTATION` - widget properties
 * - `exface.Core.WIDGET_FUNCTION_ANNOTATION` - widget functions
 * 
 * ## Example configuration in an assistant
 * 
 * ```
 *  {
 *      "instructions": "You are an helpful assistant answering questions about a no-code platform for business web apps. Use here is an overview of the available documentation. [#app_docs_overview#]",
 *      "concepts: {
 *          "app_docs_overview": {
 *              "alias": "axenox.GenAI.AppDocsConcept",
 *              "starting_page": "developer_docs/index.md"
 *              "app_alias": "exface.Core",
 *              "depth": 0
 *          }
 *      },
 *      "tools": {
 *          "get_widget": {
 *              "alias": "axenox.GenAI.GetWidgetTool",
 *              "description": "Shows long description, properties, functions and presets of the requested Widget",
 *              "arguments": [
 *                  {
 *                      "name": "uri",
 *                      "description": "path of the requested Widget",
 *                      "data_type": {
 *                          "alias": "exface.Core.String"
 *                      }
 *                  }
 *              ]
 *          }
 *      }
 *  }
 * 
 * ```
 * 
 * ## Output format
 * 
 * The tool returns a markdown document containing:
 * 
 * 1. The widget's full description from its class docblock
 * 2. A table of all UXON properties with columns: PROPERTY, TITLE, TYPE, DEFAULT, TEMPLATE, REQUIRED, DESCRIPTION
 * 3. A table of widget functions with columns: FUNCTION, TITLE
 * 4. A table of available presets with columns: NAME, DESCRIPTION, USABLE AS WRAPPER, IS PART OF APP
 * 
 */
class ModelWidgetTypeInfoTool extends AbstractAiTool
{
    /**
     * Argument name for the widget file path
     * 
     * The path should be relative to the vendor folder and point to a widget PHP file.
     * 
     * Examples:
     * - exface/Core/Widgets/DataTable.php
     * - exface/Core/Widgets/Form.php
     * - exface/Core/Widgets/Button.php
     * 
     * @var string
     */
    const ARG_SELECTOR = 'path';

    /**
     * Invokes the tool to retrieve widget metadata.
     * 
     * Reads from UXON annotation metaobjects to get the widget's description,
     * properties, functions, and presets. Returns a formatted markdown document
     * with all the collected information.
     * 
     * @param AiAgentInterface $agent The AI agent invoking the tool
     * @param AiPromptInterface $prompt The current prompt context
     * @param array $arguments Tool arguments, expects [0] to be the widget file path
     * 
     * @return AiToolResultInterface Markdown-formatted widget documentation or error message
     */
    public function invoke(AiAgentInterface $agent, AiPromptInterface $prompt, array $arguments): AiToolResultInterface
    {
        // TODO accept a widget type here too. Perhaps rename the tool to GetWidgetTypeTool
        list($widgetTypeOrPath) = $arguments;
        
        try {
            $selector = new WidgetSelector($this->getWorkbench(), $widgetTypeOrPath);
        } catch (\Throwable $e) {
            throw new AiToolRuntimeError($this, $prompt, 'Cannot parse widget selector `' . $widgetTypeOrPath . '`. ' . $e->getMessage(), null, $e);
        }
        
        
        try{
            $widgetDescriptionDs = DataSheetFactory::createFromObjectIdOrAlias($this->getWorkbench(), 'exface.Core.UXON_ENTITY_ANNOTATION');
            $widgetDescriptionDs->getColumns()->addMultiple([
                'TITLE',
                'DESCRIPTION',
                'FILE'
            ]);
            switch (true) {
                case $selector->isFilepath():
                    $filePath = $widgetTypeOrPath;
                    break;
                case $selector->isAlias():
                    if ($selector->isCoreWidget()) {
                        $filePath = 'exface/core/Widgets/' . $widgetTypeOrPath . '.php';
                    } else {
                        $widgetClass = WidgetFactory::getWidgetClassFromType($widgetTypeOrPath);
                        $filePath = str_replace(AliasSelectorInterface::ALIAS_NAMESPACE_DELIMITER, '/', $widgetClass) . '.php';
                    }
                    break;
                case $selector->isClassname():
                    $filePath = str_replace(AliasSelectorInterface::ALIAS_NAMESPACE_DELIMITER, '/', $widgetTypeOrPath) . '.php';
                    break;
                default:
                    throw new AiToolRuntimeError($this, $prompt, 'Cannot convert widget select `' . $widgetTypeOrPath . '` to a class path. Expecting widget type or file path relative to the vendor folder');
            }
            $widgetDescriptionDs->getFilters()->addConditionFromString('FILE', $filePath, ComparatorDataType::EQUALS);
            $widgetDescriptionDs->dataRead();
            
            switch ($widgetDescriptionDs->countRows()) {
                case 0:
                    throw new AiToolRuntimeError("Widget prototype `{$widgetTypeOrPath}` not found. Expecting widget type or file path relative to the vendor folder");
                case 1:
                    $output = $this->buildMarkdownChapterForWidgetType($widgetDescriptionDs, 0);
                    break;
                default:
                    $output = '';
                    foreach ($widgetDescriptionDs->getRowIndexes() as $rowIdx) {
                        $output .= $this->buildMarkdownChapterForWidgetType($output, $rowIdx) . "\n\n";
                    }
            }
            
            return new AiToolResultString($this, $arguments, $output, $this->getReturnDataType());
        }
        catch(\Throwable $e){
            $exception = new AiToolRuntimeError($this, $prompt, 'Failed to load widget metadata. ' . $e->getMessage(), null, $e);
            return new AiToolResultString($this, $arguments, 'ERROR: file not found!', $this->getReturnDataType(), [], [$exception]);
        }
    }
    
    protected function buildMarkdownChapterForWidgetType(DataSheetInterface $widgetDescriptionDs, int $rowIdx) : string
    {
        $row = $widgetDescriptionDs->getRow($rowIdx);
        $filePathRelativeToVendor = $row['FILE'];
        
        $output = MarkdownDataType::buildMarkdownHeader($row['TITLE'], 1);
        $output .= "\n" . $row['DESCRIPTION'] . PHP_EOL;

        $propertiesDs = DataSheetFactory::createFromObjectIdOrAlias($this->getWorkbench(), 'exface.Core.UXON_PROPERTY_ANNOTATION');
        $propertiesDs->getColumns()->addMultiple([
            'PROPERTY',
            'TITLE',
            'TYPE',
            'DEFAULT',
            'TEMPLATE',
            'REQUIRED',
            'DESCRIPTION'
        ]);
        $propertiesDs->getFilters()->addConditionFromString('FILE', $filePathRelativeToVendor, ComparatorDataType::EQUALS);
        $propertiesDs->getSorters()->addFromString('PROPERTY', SortingDirectionsDataType::ASC);
        $propertiesDs->dataRead();
        $widgetProperties = $propertiesDs->getRows();

        $functionsDs = DataSheetFactory::createFromObjectIdOrAlias($this->getWorkbench(), 'exface.Core.WIDGET_FUNCTION_ANNOTATION');
        $functionsDs->getColumns()->addMultiple([
            'PROPERTY',
            'TITLE'
        ]);
        $functionsDs->getFilters()->addConditionFromString('FILE', $filePathRelativeToVendor, ComparatorDataType::EQUALS);
        $functionsDs->getSorters()->addFromString('PROPERTY', SortingDirectionsDataType::DESC);
        $functionsDs->dataRead();
        $widgetFunctions = $functionsDs->getRows();

        $presetsDs = DataSheetFactory::createFromObjectIdOrAlias($this->getWorkbench(), 'exface.Core.UXON_PROPERTY_ANNOTATION');
        $presetsDs->getColumns()->addMultiple([
            'NAME',
            'DESCRIPTION',
            'WRAP_FLAG',
            'APP__LABEL'
        ]);
        $presetsDs->getFilters()->addConditionFromString('FILE', $filePathRelativeToVendor, ComparatorDataType::EQUALS);
        $presetsDs->getSorters()->addFromString('PROPERTY', SortingDirectionsDataType::ASC);
        $presetsDs->dataRead();
        $widgetPresets = $presetsDs->getRows();

        $propertiesHeaders = ['PROPERTY', 'TITLE', 'TYPE', 'DEFAULT', 'TEMPLATE', 'REQUIRED', 'DESCRIPTION'];
        $functionsHeaders = ['FUNCTION', 'TITLE'];
        $presetsHeaders = ['NAME', 'DESCRIPTION', 'USABLE AS WRAPPER', 'IS PART OF APP'];

        $output .= $this->createTextTable('Widget Properties', $propertiesHeaders, $widgetProperties) . PHP_EOL;
        $output .= $this->createTextTable('Widget Functions', $functionsHeaders, $widgetFunctions) . PHP_EOL;
        $output .= $this->createTextTable('Widget Presets', $presetsHeaders, $widgetPresets);
        
        return $output;
    }

    /**
     * Creates a markdown table from an array of rows.
     * 
     * Used internally to format widget properties, functions, and presets
     * as readable markdown tables for the LLM output.
     * 
     * @param string $title The table title (rendered as h3 heading)
     * @param array $headers Column headers for the table
     * @param array $rows Array of associative arrays containing the data
     * @param int $wrap Maximum character width before wrapping cell content
     * 
     * @return string Formatted markdown table with the given title
     */
    public function createTextTable(string $title, array $headers, array $rows, int $wrap = 80): string
    {
        $out  = "### " . $title . "\n\n";
        $out .= '| ' . implode(' | ', $headers) . " | \n";
        $sep  = array_map(fn($h) => str_repeat('-', max(3, mb_strlen($h))), $headers);
        $out .= '| ' . implode(' | ', $sep) . " | \n";
    
        foreach ($rows as $row) {
            $cells = [];
            foreach ($row as $cell) {
                $text    = preg_replace('/\s+/', ' ', trim((string)$cell));
                $wrapped = wordwrap($text, $wrap, "\n", true);
                $cells[] = str_replace("\n", '<br>', $wrapped);
            }
            $out .= '| ' . implode(' | ', $cells) . " | \n";
        }
    
        return $out;
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
                ->setName(self::ARG_SELECTOR)
                ->setDescription('Widget type (e.g. `DataTable`) or path to the widget PHP file relative to the vendor folder (e.g. `exface/core/Widgets/DataTable.php`)')
        ];
    }

    /**
     * Returns the data type for this tool's output.
     * 
     * This tool returns markdown-formatted text containing the widget documentation.
     * 
     * {@inheritDoc}
     * @see AiToolInterface::getReturnDataType()
     */
    public function getReturnDataType(): DataTypeInterface
    {
        return DataTypeFactory::createFromPrototype($this->getWorkbench(), MarkdownDataType::class);
    }
}