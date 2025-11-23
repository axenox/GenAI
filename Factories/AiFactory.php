<?php
namespace axenox\GenAI\Factories;

use axenox\GenAI\Exceptions\AiAgentNotFoundError;
use axenox\GenAI\Interfaces\AiPromptInterface;
use axenox\GenAI\Interfaces\AiToolInterface;
use axenox\GenAI\Common\Selectors\AiAgentSelector;
use axenox\GenAI\Common\Selectors\AiConceptSelector;
use axenox\GenAI\Interfaces\Selectors\AiToolSelectorInterface;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\DataTypes\ComparatorDataType;
use exface\Core\DataTypes\PhpClassDataType;
use exface\Core\DataTypes\PhpFilePathDataType;
use exface\Core\DataTypes\SemanticVersionDataType;
use exface\Core\DataTypes\StringDataType;
use exface\Core\Exceptions\UxonParserError;
use axenox\GenAI\Interfaces\AiAgentInterface;
use axenox\GenAI\Interfaces\AiConceptInterface;
use exface\Core\Factories\AbstractSelectableComponentFactory;
use exface\Core\Factories\DataSheetFactory;
use axenox\GenAI\Interfaces\Selectors\AiAgentSelectorInterface;
use exface\Core\Interfaces\Selectors\SelectorInterface;
use exface\Core\Interfaces\Selectors\VersionedSelectorInterface;
use exface\Core\Interfaces\WorkbenchInterface;

/**
 * Produces AI framework components: agents, concepts, etc.
 * 
 * @author Andrej Kabachnik
 *
 */
abstract class AiFactory extends AbstractSelectableComponentFactory
{
    public static function createFromSelector(SelectorInterface $selector, array $constructorArguments = null)
    {
        switch (true) { 
            case ($selector instanceof AiAgentSelectorInterface):
                return static::createAgentFromString($selector->getWorkbench(), $selector->toString());
    
        }
        return parent::createFromSelector($selector, $constructorArguments);
    }
    /**
     * 
     * @param \exface\Core\Interfaces\WorkbenchInterface $workbench
     * @param string $placeholder
     * @param \axenox\GenAI\Interfaces\AiPromptInterface $prompt
     * @param \exface\Core\CommonLogic\UxonObject $uxon
     * @throws \exface\Core\Exceptions\UxonParserError
     * @return \axenox\GenAI\Interfaces\AiConceptInterface
     */
    public static function createConceptFromUxon(WorkbenchInterface $workbench, string $placeholder, AiPromptInterface $prompt, UxonObject $uxon) : AiConceptInterface
    {
        if ($uxon->hasProperty('class')) {
            $selector = new AiConceptSelector($workbench, $uxon->getProperty('class'));
            $uxon->unsetProperty('class');
        } else {
            throw new UxonParserError($uxon, 'Cannot instatiate AI concept: no class property found in UXON model');
        }
        return static::createFromSelector($selector, [$workbench, $placeholder, $prompt, $uxon]);
    }

    public static function createAgentFromString(WorkbenchInterface $workbench, string $aliasWithVersion) : AiAgentInterface
    {
        list($alias, $versionConstraint) = explode(':', $aliasWithVersion);
        $versionConstraint = $versionConstraint ?? '*';
        
        $ds = DataSheetFactory::createFromObjectIdOrAlias($workbench, 'axenox.GenAI.AI_AGENT_VERSION');
        $ds->getFilters()->addConditionFromString('AI_AGENT__ALIAS_WITH_NS', $alias, ComparatorDataType::EQUALS);
        $ds->getColumns()->addMultiple([
            'CONFIG_UXON',
            'DATA_CONNECTION',
            'AI_AGENT__NAME',
            'AI_AGENT__ALIAS',
            'VERSION',
            'PROTOTYPE_CLASS',
            'ENABLED_FLAG'
        ]);
        $ds->dataRead();
        if($ds->isEmpty()){
            throw new AiAgentNotFoundError("Ai Agent '$aliasWithVersion' not found");
        }
        $versions = $ds->getColumn('VERSION')->getValues();
        $bestFit = SemanticVersionDataType::findVersionBest($versionConstraint, $versions);
        $row = $ds->getRow($ds->getColumn('VERSION')->findRowByValue($bestFit));

        $uxon = UxonObject::fromAnything($row['CONFIG_UXON']);
        // Required props
        $uxon->setProperty('name', $row['AI_AGENT__NAME']);
        $uxon->setProperty('alias', $row['AI_AGENT__ALIAS']);
        // Optional props
        if (null !== $val = $row['DATA_CONNECTION']) {
            $uxon->setProperty('data_connection_alias', $val);
        }

        // Create a new selector with the exact version
        $exactSelector = new AiAgentSelector($workbench, $alias . VersionedSelectorInterface::VERSION_SEPARATOR . $bestFit);
        $prototypeClass = PhpFilePathDataType::findClassInFile($workbench->filemanager()->getPathToVendorFolder() . DIRECTORY_SEPARATOR . $row['PROTOTYPE_CLASS']);
        $agent = new $prototypeClass($exactSelector, $uxon);

        return $agent;
    }

    /**
     * Instantiates an AI tool from a function name
     * 
     * Examples:
     * 
     * - `createToolFromUxon($this->getWorkbench(), 'GetDocs')`
     * 
     * @param \exface\Core\Interfaces\WorkbenchInterface $workbench
     * @param string $functionName
     * @param \exface\Core\CommonLogic\UxonObject $uxon
     * @throws \axenox\GenAI\Exceptions\AiAgentNotFoundError
     * @return object
     */
    public static function createToolFromUxon(WorkbenchInterface $workbench, string $functionName, UxonObject $uxon) : AiToolInterface
    {
        if ($uxon->hasProperty('class')) {
            $class = $uxon->getProperty('class');
            if (! $uxon->hasProperty('name')) {
                $uxon->setProperty('name', $functionName);
            }
        } else {
            $ds = DataSheetFactory::createFromObjectIdOrAlias($workbench, 'axenox.GenAI.AI_TOOL_PROTOTYPE');
            $className = StringDataType::convertCaseUnderscoreToPascal($functionName) . 'Tool.php';
            $ds->getFilters()->addConditionFromString('FILENAME', $className, ComparatorDataType::EQUALS);
            $ds->getColumns()->addMultiple([
                'PATHNAME_ABSOLUTE',
                'FILENAME'
            ]);
            $ds->dataRead();
            if($ds->isEmpty()){
                throw new AiAgentNotFoundError("Ai tool '$functionName' not found");
            }
            $row = $ds->getRow(0);
    
            $path = $row['PATHNAME_ABSOLUTE'];
            $class = PhpFilePathDataType::findClassInFile($path, 1000);
            if (! $uxon->hasProperty('name')) {
                $uxon->setProperty('name', $functionName);
            }
        }

        $tool = new $class($workbench, $uxon);

        return $tool;
    }

    /**
     * Instantiates an AI tool from a selector
     * 
     * Examples:
     * 
     * - `createToolFromSelector(new AiToolSelector($this->getWorkbench(), \axenox\GenAI\AI\Tools\GetDocsTool::class))`
     * 
     * @param \exface\Core\Interfaces\WorkbenchInterface $workbench
     * @param \axenox\GenAI\Interfaces\Selectors\AiToolSelectorInterface $selector
     * @param \exface\Core\CommonLogic\UxonObject $uxon
     * @return AiToolInterface
     */
    public static function createToolFromSelector(AiToolSelectorInterface $selector, UxonObject $uxon) : AiToolInterface
    {
        switch (true) {
            case $selector->isAlias():
                return self::createToolFromUxon($selector->getWorkbench(), $selector->toString(), $uxon);
            case $selector->isClassname():
                $class = $selector->toString();
                break;
            case $selector->isFilepath():
                $class = PhpFilePathDataType::findClassInFile($selector->toString());
                break;
        }
        $tool = new $class($selector->getWorkbench(), $uxon);
        return $tool;
    }
}