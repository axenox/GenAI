<?php
namespace axenox\GenAI\Factories;

use axenox\GenAI\Common\Selectors\AiToolSelector;
use axenox\GenAI\Exceptions\AiAgentNotFoundError;
use axenox\GenAI\Exceptions\AiConceptNotFoundError;
use axenox\GenAI\Exceptions\AiToolNotFoundError;
use axenox\GenAI\Interfaces\AiPromptInterface;
use axenox\GenAI\Interfaces\AiToolInterface;
use axenox\GenAI\Common\Selectors\AiAgentSelector;
use axenox\GenAI\Common\Selectors\AiConceptSelector;
use axenox\GenAI\Interfaces\Selectors\AiConceptSelectorInterface;
use axenox\GenAI\Interfaces\Selectors\AiToolSelectorInterface;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\DataTypes\ComparatorDataType;
use exface\Core\DataTypes\FilePathDataType;
use exface\Core\DataTypes\PhpClassDataType;
use exface\Core\DataTypes\PhpFilePathDataType;
use exface\Core\DataTypes\SemanticVersionDataType;
use exface\Core\DataTypes\SortingDirectionsDataType;
use exface\Core\DataTypes\StringDataType;
use exface\Core\Exceptions\InvalidArgumentException;
use exface\Core\Exceptions\UxonParserError;
use axenox\GenAI\Interfaces\AiAgentInterface;
use axenox\GenAI\Interfaces\AiConceptInterface;
use exface\Core\Factories\AbstractSelectableComponentFactory;
use exface\Core\Factories\DataSheetFactory;
use axenox\GenAI\Interfaces\Selectors\AiAgentSelectorInterface;
use exface\Core\Interfaces\DataSheets\DataSheetInterface;
use exface\Core\Interfaces\Selectors\AliasSelectorInterface;
use exface\Core\Interfaces\Selectors\PrototypeSelectorInterface;
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
     * @param AiAgentInterface $agent
     * @param AiPromptInterface $prompt
     * @param string $placeholder
     * @param UxonObject $uxon
     * @return AiConceptInterface
     */
    public static function createConceptFromUxon(AiAgentInterface $agent, AiPromptInterface $prompt, string $placeholder, UxonObject $uxon) : AiConceptInterface
    {
        $selector = $uxon->getProperty('alias');
        if ($selector === null) {
            $selector = $uxon->getProperty('class');
            if ($selector !== null) {
                $uxon->unsetProperty('class');
            } else {
                throw new UxonParserError($uxon, 'Cannot instantiate AI concept: neither `alias` nor `class` property found in UXON model');
            }
        }
        $instance = static::createConceptFromSelector(new AiConceptSelector($agent->getWorkbench(), $selector), $agent, $prompt, $placeholder, $uxon);
        return $instance;
        
    }

    public static function createConceptFromSelector(AiConceptSelectorInterface $selector, AiAgentInterface $agent, AiPromptInterface $prompt, string $placeholder, UxonObject $uxon) : AiConceptInterface
    {
        $class = static::findConceptClass($selector);
        $instance = new $class($agent, $prompt, $placeholder, $uxon);
        return $instance;
    }
    
    public static function findConceptClass(AiConceptSelectorInterface $selector) : string
    {
        switch (true) {
            case $selector->isAlias():
                $appAlias = $selector->getAppAlias();
                $conceptAlias = StringDataType::substringAfter($selector->toString(), AliasSelectorInterface::ALIAS_NAMESPACE_DELIMITER, $selector->toString(), false, true);
                $classPath = $selector->getWorkbench()->filemanager()->getPathToVendorFolder() 
                    . DIRECTORY_SEPARATOR . str_replace(AliasSelectorInterface::ALIAS_NAMESPACE_DELIMITER, DIRECTORY_SEPARATOR, $appAlias) 
                    . DIRECTORY_SEPARATOR . 'AI'
                    . DIRECTORY_SEPARATOR . 'Concepts'
                    . DIRECTORY_SEPARATOR . $conceptAlias . '.php';
                try {
                    $class = PhpFilePathDataType::findClassInFile($classPath);
                } catch (\Throwable $e) {
                    throw new AiConceptNotFoundError('Concept "' . $selector->toString() . '" not found', null, $e);
                }
                break;
            case $selector->isClassname():
                $class = $selector->toString();
                break;
            case $selector->isFilepath():
                $class = PhpFilePathDataType::findClassInFile($selector->toString());
                break;
        }
        return $class;
    }

    public static function createAgentFromString(WorkbenchInterface $workbench, string $aliasWithVersion) : AiAgentInterface
    {
        list($alias, $versionConstraint) = explode(':', $aliasWithVersion);
        $versionConstraint = $versionConstraint ?? '*';

        $ds = DataSheetFactory::createFromObjectIdOrAlias($workbench, 'axenox.GenAI.AI_AGENT_VERSION');
        $ds->getFilters()->addConditionFromString('AI_AGENT__ALIAS_WITH_NS', $alias, ComparatorDataType::EQUALS);
        $ds->getColumns()->addMultiple([
            'CONFIG_UXON',
            'INSTRUCTIONS',
            'DATA_CONNECTION',
            'AI_AGENT__NAME',
            'AI_AGENT__ALIAS',
            'VERSION',
            'PROTOTYPE_CLASS',
            'ENABLED_FLAG'
        ]);
        // Sort by version descending - that will place most probably version in the front!
        $ds->getSorters()->addFromString('VERSION', SortingDirectionsDataType::DESC);
        $ds->dataRead();
        if($ds->isEmpty()){
            throw new AiAgentNotFoundError("Ai Agent '$aliasWithVersion' not found");
        }
        
        // Find the best fitting version for the given semantic version constraint
        $versionCol = $ds->getColumn('VERSION');
        $versions = $versionCol->getValues();
        $bestFitVersion = SemanticVersionDataType::findVersionBest($versionConstraint, $versions);
        $agentRow = $ds->getRow($versionCol->findRowByValue($bestFitVersion));

        // Prepare the agent UXON
        $uxon = UxonObject::fromAnything($agentRow['CONFIG_UXON']);
        
        // Add required props from the data row
        $uxon->setProperty('name', $agentRow['AI_AGENT__NAME']);
        $uxon->setProperty('alias', $agentRow['AI_AGENT__ALIAS']);
        $uxon->setProperty('instructions', $agentRow['INSTRUCTIONS']);
        
        // Make sure, there is an LLM connection. If there is one defined, use it regularly. If not,
        // see if the previous version had one and inherit it.
        if (null !== $val = $agentRow['DATA_CONNECTION']) {
            $uxon->setProperty('data_connection_alias', $val);
        } else {
            $val = self::findAgentConnection($bestFitVersion, $ds);
            if ($val !== null) {
                // If a previous connection is found, use it and save a customizing record - just like
                // an admin would do when setting the local connection.
                $customizingDs = DataSheetFactory::createFromObjectIdOrAlias($workbench, 'exface.Core.CUSTOMIZING');
                $customizingDs->addRow([
                    'TABLE_NAME' => 'exf_ai_agent_version',
                    'COLUMN_NAME' => 'data_connection_oid',
                    'ROW_UID' => $agentRow['UID'],
                    'VALUE' => $val,
                ]);
                $uxon->setProperty('data_connection_alias', $val);
                $customizingDs->dataCreate(false);
            } else {
                // If we did not find a previously used connection either, leave it blank - the agent will
                // be instantiated, but will probably not be usable. Still, this will allow to render 
                // chats and will only issue an error if they are really used.
            }
        }

        // Create a new selector with the exact version
        $selectorWithVersion = new AiAgentSelector($workbench, $alias . VersionedSelectorInterface::VERSION_SEPARATOR . $bestFitVersion);
        $prototypeClass = PhpFilePathDataType::findClassInFile($workbench->filemanager()->getPathToVendorFolder() . DIRECTORY_SEPARATOR . $agentRow['PROTOTYPE_CLASS']);
        $agent = new $prototypeClass($selectorWithVersion, $uxon);

        return $agent;
    }
    
    protected static function findAgentConnection(string $currentVersion, DataSheetInterface $allVersions) : ?string
    {
        $versionCol = $allVersions->getColumns()->get('VERSION');
        $connectionCol = $allVersions->getColumns()->get('DATA_CONNECTION');
        $versionsLeft = $versionCol->getValues();
        $nextVersion = $currentVersion;
        while (count($versionsLeft) > 0) {
            $connectionUid = $connectionCol->getValue($versionCol->findRowByValue($nextVersion));
            if ($connectionUid !== null) {
                return $connectionUid;
            }
            unset ($versionsLeft[array_search($nextVersion, $versionsLeft)]);
            $nextVersion = SemanticVersionDataType::findVersionBest('*', $versionsLeft);
        }
        return null;
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
    public static function createToolFromUxon(WorkbenchInterface $workbench, UxonObject $uxon, string $functionName) : AiToolInterface
    {
        $selector = $uxon->getProperty('alias');
        if ($selector === null) {
            $selector = $uxon->getProperty('class');
            if ($selector !== null) {
                $uxon->unsetProperty('class');
            } 
        }
        if ($selector !== null) {
            $class = static::findToolClass(new AiToolSelector($workbench, $selector), $functionName);
        } else {
            $class = static::findToolClassForFunctionName($workbench, $functionName);
        }

        if (! $uxon->hasProperty('name')) {
            $uxon->setProperty('name', $functionName);
        }
        
        $instance = new $class($workbench, $uxon);
        return $instance;
    }

    public static function findToolClass(AiToolSelectorInterface $selector, ?string $functionName = null) : string
    {
        switch (true) {
            case $selector->isAlias():
                $appAlias = trim($selector->getAppAlias() ?? '');
                if ($appAlias === '') {
                    throw new AiToolNotFoundError('Tool "' . $selector->toString() . '" not found');
                }
                $toolAlias = trim(StringDataType::substringAfter($selector->toString(), AliasSelectorInterface::ALIAS_NAMESPACE_DELIMITER, $selector->toString(), false, true));
                if ($toolAlias === '') {
                    throw new AiToolNotFoundError('Tool "' . $selector->toString() . '" not found');
                }
                $classPath = $selector->getWorkbench()->filemanager()->getPathToVendorFolder()
                    . DIRECTORY_SEPARATOR . str_replace(AliasSelectorInterface::ALIAS_NAMESPACE_DELIMITER, DIRECTORY_SEPARATOR, $appAlias)
                    . DIRECTORY_SEPARATOR . 'AI'
                    . DIRECTORY_SEPARATOR . 'Tools'
                    . DIRECTORY_SEPARATOR . $toolAlias . '.php';

                try {
                    $class = PhpFilePathDataType::findClassInFile($classPath);
                } catch (\Throwable $e) {
                    throw new AiToolNotFoundError('Tool "' . $selector->toString() . '" not found', null, $e);
                }
                break;
            case $selector->isClassname():
                $class = $selector->toString();
                break;
            case $selector->isFilepath():
                $class = PhpFilePathDataType::findClassInFile($selector->toString());
                break;
            case $functionName !== null:
                $class = static::findToolClassForFunctionName($selector->getWorkbench(), $functionName);
                break;
            default:
                throw new AiToolNotFoundError("Ai tool `{$selector->toString()}` not found");
        }
        return $class;
    }
    
    protected static function findToolClassForFunctionName(WorkbenchInterface $workbench, string $functionName) : string
    {
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
        return $class;
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
                $class = static::findToolClass($selector);
                break;
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

    /**
     * @param AiConceptSelectorInterface $selector
     * @return string
     */
    public static function findConceptAlias(AiConceptSelectorInterface $selector) : string
    {
        return static::findAliasOfSelector($selector);
    }

    /**
     * @param AiToolSelectorInterface $selector
     * @return string
     */
    public static function findToolAlias(AiToolSelectorInterface $selector) : string 
    {
        return static::findAliasOfSelector($selector);
    }

    /**
     * @param SelectorInterface $selector
     * @return string
     */
    protected static function findAliasOfSelector(SelectorInterface $selector) : string
    {
        switch (true) {
            case ($selector instanceof AliasSelectorInterface) && $selector->isAlias():
                return $selector->toString();
            case ($selector instanceof PrototypeSelectorInterface) && $selector->isClassname():
                return $selector->getAppAlias()
                    . AliasSelectorInterface::ALIAS_NAMESPACE_DELIMITER
                    . PhpClassDataType::findClassNameWithoutNamespace($selector->toString());
            case ($selector instanceof PrototypeSelectorInterface) && $selector->isFilepath():
                return $selector->getAppAlias()
                    . AliasSelectorInterface::ALIAS_NAMESPACE_DELIMITER
                    . FilePathDataType::findFileName($selector->toString(), false);
        }
        throw new InvalidArgumentException("Cannot find alias for selector " . $selector->toString());
    }
}