<?php
namespace axenox\GenAI\AI\Tools;

use axenox\GenAI\Common\AbstractAiTool;
use exface\Core\CommonLogic\Actions\ServiceParameter;
use exface\Core\DataTypes\ComparatorDataType;
use exface\Core\DataTypes\MarkdownDataType;
use exface\Core\DataTypes\SortingDirectionsDataType;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\Factories\DataTypeFactory;
use exface\Core\Interfaces\DataTypes\DataTypeInterface;
use exface\Core\Interfaces\WorkbenchInterface;

/**
 * This AI tool allows an LLM to request any widget from our application.
 * 
 * The idea is to include a list of available docs files in the instructions for the LLM using
 * the `AppDocsConcept` and give it the possibility to request any of those files if needed.
 * 
 * ## Example configuration in an assistant
 * 
 * ```
 *  {
 *      "instructions": "You are an helpful assistant answering questions about a no-code platform for business web apps. Use here is an overview of the available documentation. [#app_docs_overview#]",
 *      "concepts: {
 *          "app_docs_overview": {
 *              "class": "axenox\\GenAI\\AI\\Concepts\\AppDocsConcept",
 *              "starting_page": "developer_docs/index.md"
 *              "app_alias": "exface.Core",
 *              "depth": 0
 *          }
 *      },
 *      "tools": {
 *          "GetWidget": {
 *              "name": "GetWidget",
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
 */
class GetWidgetTool extends AbstractAiTool
{
    /**
     * E.g. 
     * - exface/Core/Docs/Tutorials/BookClub_walkthrough/index.md
     * - exface/Core/Docs/index.md
     * @var string
     */
    const ARG_PATH = 'path';

    public function invoke(array $arguments): string
    {
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

            return $output;
        }
        catch(\Throwable $e){
            $this->workbench->getLogger()->logException($e);
            return 'ERROR: file not found!';
        }
    }

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
                ->setDescription('Gets full description, properties, functions and presets about requested Widget')
        ];
    }

    /**
     * {@inheritDoc}
     * @see AiToolInterface::getReturnDataType()
     */
    public function getReturnDataType(): DataTypeInterface
    {
        return DataTypeFactory::createFromPrototype($this->getWorkbench(), MarkdownDataType::class);
    }
}