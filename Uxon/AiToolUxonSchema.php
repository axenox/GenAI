<?php
namespace axenox\GenAI\Uxon;

use axenox\GenAI\Common\AbstractAiTool;
use axenox\GenAI\Common\AbstractTool;
use axenox\GenAI\Common\Selectors\AiToolSelector;
use axenox\GenAI\Exceptions\AiToolNotFoundError;
use axenox\GenAI\Factories\AiFactory;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\DataTypes\ComparatorDataType;
use exface\Core\DataTypes\PhpClassDataType;
use exface\Core\DataTypes\PhpFilePathDataType;
use exface\Core\DataTypes\StringDataType;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\Interfaces\Log\LoggerInterface;
use exface\Core\Uxon\UxonSchema;

/**
 * UXON-schema class for AI tools/functions
 *
 * @see UxonSchema for general information.
 *
 * @author Andrej Kabachnik
 *
 */
class AiToolUxonSchema extends UxonSchema
{
    public static function getSchemaName() : string
    {
        return 'AI tool';
    }
    
    /**
     *
     * {@inheritDoc}
     * @see \exface\Core\Uxon\UxonSchema::getPrototypeClass()
     */
    public function getPrototypeClass(UxonObject $uxon, array $path, string $rootPrototypeClass = null) : string
    {
        $class = $rootPrototypeClass ?? $this->getDefaultPrototypeClass();

        foreach ($uxon as $key => $value) {
            if (strcasecmp($key, 'class') === 0) {
                $selector = new AiToolSelector($this->getWorkbench(), $value);
                break;
            }
            if (strcasecmp($key, 'alias') === 0) {
                $selector = new AiToolSelector($this->getWorkbench(), $value);
                break;
            }
        }

        if ($selector !== null && trim($selector->toString()) !== '') {
            try {
                $class = AiFactory::findToolClass($selector);
                if ($class !== null) {
                    $class = '\\' . ltrim($class, '\\');
                }
            } catch (AiToolNotFoundError $e) {
                // If the concept class cannot be found, we will just use the default prototype class.
            }
        }

        if (count($path) > 1) {
            return parent::getPrototypeClass($uxon, $path, $class);
        }

        return $class;
    }

    public function getPresets(UxonObject $uxon, array $path, string $rootPrototypeClass = null) : array
    {
        $presets = [];
        $ds = DataSheetFactory::createFromObjectIdOrAlias($this->getWorkbench(), 'axenox.GenAI.AI_TOOL_PROTOTYPE');
        $ds->getColumns()->addMultiple([
            'PATHNAME_ABSOLUTE',
            'PATHNAME_RELATIVE',
            'FILENAME',
        ]);
        $ds->dataRead();
        
        foreach ($ds->getRows() as $row) {
            $path = $row['PATHNAME_ABSOLUTE'];
            try {
                $class = PhpFilePathDataType::findClassInFile($path, 1000);
                
                $detailsSheet = DataSheetFactory::createFromObjectIdOrAlias($this->getWorkbench(), 'exface.Core.UXON_ENTITY_ANNOTATION');
                $detailsSheet->getColumns()->addMultiple([
                    'TITLE',
                    'DESCRIPTION',
                    'FILE',
                    'CLASSNAME'
                ]);
                $detailsSheet->getFilters()->addConditionFromString('FILE', $row['PATHNAME_RELATIVE'], ComparatorDataType::EQUALS);
                $detailsSheet->dataRead();
                
                $title = $detailsSheet->getCellValue('TITLE', 0);
                $template = $class::getTemplate($this->getWorkbench());
                if (! $template['description']) {
                    $template['description'] = $title;
                }
                $presets[] = [
                    'UID' => '',
                    'NAME' => $this->getNameForGeneratedPreset($class, $template),
                    'PROTOTYPE__LABEL' => 'Defaults',
                    'DESCRIPTION' => $title,
                    'PROTOTYPE' => $class,
                    'UXON' => (new UxonObject($template))->toJson()
                ];
            } catch (\Throwable $e) {
                $this->getWorkbench()->getLogger()->logException($e, LoggerInterface::WARNING);
            }
        }
        return $presets;
    }
    
    protected function getNameForGeneratedPreset(string $class, array $template) : string
    {
        $className = PhpClassDataType::findClassNameWithoutNamespace($class);
        if (StringDataType::endsWith($className, 'Tool')) {
            $presetName = StringDataType::substringBefore($className, 'Tool', $className, true, true);
        } else {
            $presetName = $className;
        }
        $args = $template['arguments'];
        $argsStr = '';
        foreach ($args as $arg) {
            $argsStr .= ($argsStr !== '' ? ', ' : '') . (true !== ($arg['required'] ?? false) ? '?' : '') . $arg['name'];
        }
        return '<b>' . $presetName . '</b><br/>(' . $argsStr . ')';
    }

    /**
     * {@inheritDoc}
     * @see UxonSchema::getPropertiesTemplates()
     */
    public function getPropertiesTemplates(string $prototypeClass, UxonObject $uxon, array $path) : array
    {
        $tpls = parent::getPropertiesTemplates($prototypeClass, $uxon, $path);
        
        
        if (
            is_a($prototypeClass, AbstractAiTool::class, true) 
            && ltrim($prototypeClass, '\\') !== AbstractAiTool::class
        ) {
            $tpls['arguments'] = json_encode($prototypeClass::getTemplate($this->getWorkbench())['arguments'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        }
        
        foreach ($tpls['arguments'] as &$argument) {
            unset($argument['data_type']);
            if (array_key_exists('custom_properties', $argument)) {
                unset($argument['custom_properties']);
            }
        }

        return $tpls;
    }
    
    /**
     *
     * {@inheritDoc}
     * @see \exface\Core\Uxon\UxonSchema::getDefaultPrototypeClass()
     */
    protected function getDefaultPrototypeClass() : string
    {
        return '\\' . AbstractTool::class;
    }
}