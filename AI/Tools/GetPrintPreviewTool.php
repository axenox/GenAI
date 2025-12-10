<?php
namespace axenox\GenAI\AI\Tools;

use axenox\GenAI\Common\AbstractAiTool;
use axenox\GenAI\Exceptions\AiToolConfigurationError;
use axenox\GenAI\Interfaces\AiToolInterface;
use exface\Core\CommonLogic\Actions\ServiceParameter;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\CommonLogic\WorkbenchCache;
use exface\Core\DataTypes\ComparatorDataType;
use exface\Core\DataTypes\HtmlDataType;
use exface\Core\DataTypes\StringDataType;
use exface\Core\Factories\ActionFactory;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\Factories\DataTypeFactory;
use exface\Core\Interfaces\Actions\iRenderTemplate;
use exface\Core\Interfaces\DataSheets\DataSheetInterface;
use exface\Core\Interfaces\DataTypes\DataTypeInterface;
use exface\Core\Interfaces\WorkbenchInterface;
use exface\Core\Templates\BracketHashStringTemplateRenderer;
use exface\Core\Templates\Placeholders\ArrayPlaceholders;
use exface\Core\Templates\Placeholders\ConfigPlaceholders;
use exface\Core\Templates\Placeholders\FormulaPlaceholders;
use Psr\SimpleCache\CacheInterface;

/**
 * This AI tool allows an LLM to request the output of a print action (PrintTemplate, PrintPDF, etc.) as an HTML preview.
 * 
 * ## Basic configuration
 * 
 * ```
 *  {
 *     "tools": {
 *          "GetReport": {
 *              "class": "\\axenox\\GenAI\\AI\\Tools\\GetPrintPreviewTool",
 *              "description": "Returns an HTML print of the report with the given document number",
 *              "arguments": [
 *                  {"name": "Document number"}
 *              ],
 *              "print_action": "my.App.DocumentPrintPDF"
 *          }
 *      }
 *  }
 * 
 * ```
 * 
 * ## Printing multiple reports using custom arguments
 * 
 * If you do not have a UID or you want to print multiple objects using filters, you can define a custom
 * `print_data` and use your own arguments as placeholders there.
 * 
 * NOTE: if you use the tool inside a `ToolCallConcept`, use `[#~input:#]` placeholders instead of argument
 * placeholders because there are no arguments actually being passed from outside in this case.
 * 
 * ```
 *  {
 *     "tools": {
 *          "GetReports": {
 *              "class": "\\axenox\\GenAI\\AI\\Tools\\GetPrintPreviewTool",
 *              "description": "Returns an HTML prints of all report from a given week and year",
 *              "arguments": [
 *                  {"name": "weekNo", "description": "Week number"},
 *                  {"name": "year", "description": "Year with four digits - e.g. `2025`"}
 *              ],
 *              "print_action": "my.App.DocumentPrintPDF",
 *              "print_data": {
 *                  "object_alias": "my.App.DailyReport",
 *                  "filters": {
 *                     "operator": "AND",
 *                     "conditions": [
 *                          {"expression": "Week", "comparator": "==", "value": "[#~argument:0#]"},
 *                          {"expression": "Year", "comparator": "==", "value": "[#~argument:1#]"}
 *                      ]
 *                  }
 *              }
 *          }
 *      }
 *  }
 *
 * ```
 * 
 * ## Caching
 * 
 * Since printing may be a pretty heavy job, you can enable `cache_previews` to cache results and avoid doing the
 * actual printing every time. To make sure, the cache is invalidated if important things change in your object,
 * use a custom `print_data` definition and add columns for critical attributes like modification timestamps, statuses,
 * etc.
 * 
 * The cache will only be used as long as the row of the print data does not change. In fact, if your object has
 * a `TimeStampingBehavior`, any change will automatically invalidate the cache even without a custom print data!
 * 
 * ```
 *  {
 *     "tools": {
 *          "GetReports": {
 *              "class": "\\axenox\\GenAI\\AI\\Tools\\GetPrintPreviewTool",
 *              "description": "Returns an HTML prints of all report from a given week and year",
 *              "cache_previews": true,
 *              "print_action": "my.App.DocumentPrintPDF",
 *              "print_data": {
 *                  "object_alias": "my.App.DailyReport",
 *                  "columns": [
 *                      {"attribute_alias": "ModifiedOn"},
 *                      {"attribute_alias": "Status"}
 *                  ],
 *                  "filters": {
 *                     "operator": "AND",
 *                     "conditions": [
 *                          {"expression": "Week", "comparator": "==", "value": "[#~argument:0#]"},
 *                          {"expression": "Year", "comparator": "==", "value": "[#~argument:1#]"}
 *                      ]
 *                  }
 *              }
 *          }
 *      }
 *  }
 * 
 * ```
 *
 * @author Andrej Kabachnik
 */
class GetPrintPreviewTool extends AbstractAiTool
{
    private ?string $printActionAlias = null;
    private ?UxonObject $printDataTpl = null;
    
    private bool $useCache = false;
    private CacheInterface|null $cache = null;

    /**
     * {@inheritDoc}
     * @see AiToolInterface::invoke()
     */
    public function invoke(array $arguments): string
    {
        $printData = $this->getPrintData($arguments);
        
        $results = [];
        if ($this->willCachePreviews()) {
            foreach ($printData->getRows() as $i => $row) {
                $key = $this->getCacheKey($row);
                $cache = $this->getCache();
                if (null === $preview = $cache->get($key)) {
                    $printDataForRow = $printData->extractRows([$i]);
                    $preview = $this->print($printDataForRow)[0];
                    $cache->set($key, $preview);
                }
                $results[] = $preview;
            }
        } else {
            $results = $this->print($printData);
        }
        
        return $this->concatenate($results);
    }

    /**
     * The data sheet to print - put tool arguments as placeholders anywhere in here
     * 
     * Available placeholders:
     * 
     * - `[#~argument:0#]` - nth argument of the tool call
     * - `[#=Formula()#]` - static formula call
     * - `[#~config:app_alias:config_key#]` - will be replaced by the value of the `config_key` in the given app
     * 
     * Example:
     * 
     * ```
     * {
     *      "arguments": [
     *          {"name": "DocumentId"}
     *      ],
     *      "print_data": {
     *          "object_alias": "my.App.DOCUMENT",
     *          "filters": {
     *              "operator": "AND",
     *              "conditions": [
     *                  {"expression": "UID", "comparator": "==", "value": "[#~argument:0#]"}
     *                  {"expression": "STATUS", "comparator": ">=", "value": 10}
     *              ]
     *          }
     *      }
     * }
     * 
     * ``` 
     * 
     * @uxon-property print_data
     * @uxon-type \exface\Core\CommonLogic\DataSheets\DataSheet
     * @uxon-template {"object_alias": "", "filters": {"operator": "AND","conditions":[{"expression": "","comparator": "==","value": ""}]}}
     * 
     * @param UxonObject $uxon
     * @return $this
     */
    protected function setPrintData(UxonObject $uxon) : GetPrintPreviewTool
    {
        $this->printDataTpl = $uxon;
        return $this;
    }
    
    protected function getPrintData(array $args) : DataSheetInterface
    {
        $action = $this->getPrintAction();
        $obj = $action->getMetaObject();
        
        if ($this->printDataTpl !== null) {
            $tpl = $this->printDataTpl->toJson();
            $tplRenderer = new BracketHashStringTemplateRenderer($this->getWorkbench());
            $tplRenderer->addPlaceholder(new ArrayPlaceholders($args, '~argument:'));
            $tplRenderer->addPlaceholder((new FormulaPlaceholders($this->getWorkbench()))->setSanitizeAsUxon(true));
            $tplRenderer->addPlaceholder((new ConfigPlaceholders($this->getWorkbench()))->setSanitizeAsUxon(true));
            $uxon = $tplRenderer->render($tpl);
            $printData = DataSheetFactory::createFromUxon($this->getWorkbench(), $uxon, $obj);
        } else {
            $printData = DataSheetFactory::createFromObject($obj);
            if ($obj->hasUidAttribute()) {
                $printData->getFilters()->addConditionFromAttribute($obj->getUidAttribute(), $args[0], ComparatorDataType::IN);
            } else {
                throw new AiToolConfigurationError($this, 'Cannot use GetPrintPreviewTool without a `print_data` template if the metaobject has no UID attribute!');
            }
            $printData->getColumns()->addFromSystemAttributes();
        }
        
        $printData->dataRead();
        return $printData;
    }

    /**
     * @param DataSheetInterface $printData
     * @return string[]
     */
    protected function print(DataSheetInterface $printData) : array
    {
        $action = $this->getPrintAction();
        $prints = $action->renderPreviewHTML($printData);
        // Make sure we just keep the previews, not the filenames, that are used as keys
        $prints = array_values($prints);
        
        foreach ($prints as $i => $preview) {
            // Remove anything outside the HTML body - the AI does not need that
            $preview = StringDataType::substringAfter($preview, "<body>", $preview);
            $preview = StringDataType::substringBefore($preview, "</body>", $preview);

            // Remove spaces and tabs at the beginning of lines to ensure, they are never recognized as (indented) code
            // by markdown renderers
            $lines = StringDataType::splitLines($preview);
            $lines = array_map('trim', $lines);
            $preview = implode("\n", $lines);
            $prints[$i] = $preview;
        }
        
        return $prints;
    }

    /**
     * @param array $previews
     * @return string
     */
    protected function concatenate(array $previews) : string
    {
        return implode("\n\n", $previews);
    }
    
    /**
     * Alias of the print action - the action MUST support print previews!
     * 
     * @uxon-property print_action
     * @uxon-type metamodel:action
     * 
     * @param string $alias
     * @return $this
     */
    protected function setPrintAction(string $alias) : GetPrintPreviewTool
    {
        $this->printActionAlias = $alias;
        return $this;
    }

    /**
     * @return iRenderTemplate|null
     */
    public function getPrintAction() : ?iRenderTemplate
    {
        if ($this->printActionAlias === null) {
            return null;
        }
        $action = ActionFactory::createFromString($this->getWorkbench(), $this->printActionAlias);
        if (! $action instanceof iRenderTemplate) {
            throw new AiToolConfigurationError($this, 'Cannot use action "' . $this->printActionAlias . '" in print preview tool');
        }
        return $action;
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
                ->setName('uid')
                ->setDescription('UID of document object to be printed')
        ];
    }

    /**
     * @param string $uid
     * @return string
     */
    protected function getCacheKey(array $dataRow) : string
    {
        $key = $this->exportUxonObject()->toJson(false) . '--' . json_encode($dataRow);
        return WorkbenchCache::createCacheKey($key);
    }

    /**
     * @return CacheInterface
     */
    protected function getCache() : CacheInterface
    {
        if ($this->cache === null && $this->willCachePreviews()) {
            $cacheName = str_replace('\\', '', get_class($this));
            $this->cache = WorkbenchCache::createDefaultPool($this->getWorkbench(), $cacheName, true);
        }
        return $this->cache;
    }

    /**
     * @return bool
     */
    protected function willCachePreviews() : bool
    {
        return $this->useCache;
    }

    /**
     * Cache previews instead of printing them every time
     * 
     * @uxon-property cache_previews
     * @uxon-type boolean
     * @uxon-default false
     * 
     * @param bool $trueOrFalse
     * @return $this
     */
    public function setCachePreviews(bool $trueOrFalse) : GetPrintPreviewTool
    {
        $this->useCache = $trueOrFalse;
        return $this;
    }

    /**
     * {@inheritDoc}
     * @see AiToolInterface::getReturnDataType()
     */
    public function getReturnDataType(): DataTypeInterface
    {
        return DataTypeFactory::createFromPrototype($this->getWorkbench(), HtmlDataType::class);
    }
}