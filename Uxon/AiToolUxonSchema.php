<?php
namespace axenox\GenAI\Uxon;

use axenox\GenAI\Common\AbstractTool;
use axenox\GenAI\Common\Selectors\AiToolSelector;
use axenox\GenAI\Exceptions\AiToolNotFoundError;
use axenox\GenAI\Factories\AiFactory;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\DataTypes\ComparatorDataType;
use exface\Core\DataTypes\PhpClassDataType;
use exface\Core\DataTypes\PhpFilePathDataType;
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
                    'NAME' => PhpClassDataType::findClassNameWithoutNamespace($class),
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