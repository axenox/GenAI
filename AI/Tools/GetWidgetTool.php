<?php
namespace axenox\GenAI\AI\Tools;

use axenox\GenAI\Common\AbstractAiTool;
use axenox\GenAI\Common\AiToolResultString;
use axenox\GenAI\Exceptions\AiToolRuntimeError;
use axenox\GenAI\Interfaces\AiAgentInterface;
use axenox\GenAI\Interfaces\AiPromptInterface;
use axenox\GenAI\Interfaces\AiToolResultInterface;
use exface\Core\CommonLogic\Actions\ServiceParameter;
use exface\Core\DataTypes\ComparatorDataType;
use exface\Core\DataTypes\MarkdownDataType;
use exface\Core\DataTypes\SortingDirectionsDataType;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\Factories\DataTypeFactory;
use exface\Core\Interfaces\DataTypes\DataTypeInterface;
use exface\Core\Interfaces\Exceptions\ExceptionInterface;
use exface\Core\Interfaces\WorkbenchInterface;

/**
 * This AI tool allows an LLM to retrieve detailed metadata about a widget from UXON annotations.
 * 
 * The tool returns comprehensive information about a widget including:
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
class GetWidgetTool extends AbstractAiTool
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
    const ARG_PATH = 'path';

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
        list($url) = $arguments;
        
        try{
            $widgetDescriptionDs = DataSheetFactory::createFromObjectIdOrAlias($this->getWorkbench(), 'exface.Core.UXON_ENTITY_ANNOTATION');
            $widgetDescriptionDs->getColumns()->addMultiple(['FULL_DESCRIPTION']);
            $widgetDescriptionDs->getFilters()->addConditionFromString('FILE', $url, ComparatorDataType::EQUALS);
            $widgetDescriptionDs->dataRead();
            $description = $widgetDescriptionDs->getRows();
            $output = $description[0]['FULL_DESCRIPTION'] . PHP_EOL;

            $widgetPropertiesDs = DataSheetFactory::createFromObjectIdOrAlias($this->getWorkbench(), 'exface.Core.UXON_PROPERTY_ANNOTATION');
            $widgetPropertiesDs->getColumns()->addMultiple([
                'PROPERTY', 
                'TITLE', 
                'TYPE', 
                'DEFAULT', 
                'TEMPLATE', 
                'REQUIRED',
                'DESCRIPTION'
            ]);
            $widgetPropertiesDs->getFilters()->addConditionFromString('FILE', $url, ComparatorDataType::EQUALS);
            $widgetPropertiesDs->getSorters()->addFromString('PROPERTY', SortingDirectionsDataType::ASC);
            $widgetPropertiesDs->dataRead();
            $widgetProperties = $widgetDescriptionDs->getRows();

            $widgetFunctionsDs = DataSheetFactory::createFromObjectIdOrAlias($this->getWorkbench(), 'exface.Core.WIDGET_FUNCTION_ANNOTATION');
            $widgetFunctionsDs->getColumns()->addMultiple([
                'PROPERTY',
                'TITLE'
            ]);
            $widgetFunctionsDs->getFilters()->addConditionFromString('FILE', $url, ComparatorDataType::EQUALS);
            $widgetFunctionsDs->getSorters()->addFromString('PROPERTY', SortingDirectionsDataType::DESC);
            $widgetFunctionsDs->dataRead();
            $widgetFunctions = $widgetFunctionsDs->getRows();

            $widgetPresetsDs = DataSheetFactory::createFromObjectIdOrAlias($this->getWorkbench(), 'exface.Core.UXON_PROPERTY_ANNOTATION');
            $widgetPresetsDs->getColumns()->addMultiple([
                'NAME', 
                'DESCRIPTION', 
                'WRAP_FLAG', 
                'APP__LABEL'
            ]);
            $widgetPresetsDs->getFilters()->addConditionFromString('FILE', $url, ComparatorDataType::EQUALS);
            $widgetPresetsDs->getSorters()->addFromString('PROPERTY', SortingDirectionsDataType::ASC);
            $widgetPresetsDs->dataRead();
            $widgetPresets = $widgetPresetsDs->getRows();
           
            $widgetPropertiesHeaders = ['PROPERTY', 'TITLE', 'TYPE', 'DEFAULT', 'TEMPLATE', 'REQUIRED', 'DESCRIPTION'];
            $widgetFunctionsHeaders = ['FUNCTION', 'TITLE'];
            $widgetPresetsHeaders = ['NAME', 'DESCRIPTION', 'USABLE AS WRAPPER', 'IS PART OF APP'];

            $output .= $this->createTextTable('Widget Properties', $widgetPropertiesHeaders, $widgetProperties) . PHP_EOL;
            $output .= $this->createTextTable('Widget Functions', $widgetFunctionsHeaders, $widgetFunctions) . PHP_EOL;
            $output .= $this->createTextTable('Widget Presets', $widgetPresetsHeaders, $widgetPresets);

            return new AiToolResultString($this, $arguments, $output, $this->getReturnDataType());
        }
        catch(\Throwable $e){
            $exception = new AiToolRuntimeError($this, $prompt, 'Failed to load widget metadata. ' . $e->getMessage(), null, $e);
            return new AiToolResultString($this, $arguments, 'ERROR: file not found!', $this->getReturnDataType(), [], [$exception]);
        }
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
                ->setName(self::ARG_PATH)
                ->setDescription('Path to the widget PHP file relative to the vendor folder (e.g. exface/Core/Widgets/DataTable.php)')
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