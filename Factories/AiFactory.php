<?php
namespace axenox\GenAI\Factories;

use axenox\GenAI\Common\AbstractAiTaskHandler;
use axenox\GenAI\Common\AiConversation;
use axenox\GenAI\Common\AiToolResultString;
use axenox\GenAI\Common\Messages\DiagnosticMessage;
use axenox\GenAI\Common\Messages\InputMessage;
use axenox\GenAI\Common\Messages\InstructionsMessage;
use axenox\GenAI\Common\Messages\OutputMessage;
use axenox\GenAI\Common\Messages\ToolCallMessage;
use axenox\GenAI\DataTypes\AiConversationTypeDataType;
use axenox\GenAI\DataTypes\AiMessageTypeDataType;
use axenox\GenAI\Interfaces\AiMessageInterface;
use axenox\GenAI\Interfaces\AiToolCallMessageInterface;
use axenox\GenAI\Interfaces\AiToolResultInterface;
use axenox\GenAI\Common\Selectors\AiToolSelector;
use axenox\GenAI\Common\Selectors\AiSkillSelector;
use axenox\GenAI\Exceptions\AiAgentNotFoundError;
use axenox\GenAI\Exceptions\AiConceptNotFoundError;
use axenox\GenAI\Exceptions\AiSkillNotFoundError;
use axenox\GenAI\Exceptions\AiToolNotFoundError;
use axenox\GenAI\Interfaces\AiPromptInterface;
use axenox\GenAI\Interfaces\AiConversationInterface;
use axenox\GenAI\Interfaces\AiTaskHandlerInterface;
use axenox\GenAI\Interfaces\AiTaskHandlerWithoutConnectionInterface;
use axenox\GenAI\Interfaces\AiSkillInterface;
use axenox\GenAI\Interfaces\AiToolInterface;
use axenox\GenAI\Common\Selectors\AiAgentSelector;
use axenox\GenAI\Common\Selectors\AiConceptSelector;
use axenox\GenAI\Interfaces\Selectors\AiConceptSelectorInterface;
use axenox\GenAI\Interfaces\Selectors\AiSkillSelectorInterface;
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
    /**
     * Creates a new conversation or restores the conversation referenced by the prompt.
     * 
     * @param AiAgentInterface $agent
     * @param AiPromptInterface $prompt
     * @return AiConversationInterface
     */
    public static function createConversationFromPrompt(
        AiAgentInterface $agent,
        AiPromptInterface $prompt
    ) : AiConversationInterface 
    {
        $conversationId = $prompt->getConversationUid();
        $conversation = static::createConversation($agent, $conversationId, [
            'TYPE' => AiConversationTypeDataType::AGENT,
            'DEVMODE' => $agent->getDevmode(),
            'CONNECTION' => $agent->getConnection()->getId()
        ]);
        if ($conversationId === null) {
            $prompt->setConversationUid($conversation->getConversationId());
        }

        return $conversation;
    }

    /**
     * Creates a new conversation or continues the conversation with the supplied ID.
     * 
     * @param AiTaskHandlerInterface $handler
     * @param string|null $conversationId
     * @param array<string,mixed> $row An axenox.GenAI.AI_CONVERSATION row keyed by attribute alias.
     * New rows require TYPE from AiConversationTypeDataType; omitted DEVMODE defaults to false. DATA is serialized JSON.
     * On restoration only TYPE is used, to verify the caller's expected source.
     * @return AiConversationInterface
     */
    public static function createConversation(
        AiTaskHandlerInterface $handler,
        ?string $conversationId = null,
        array $row = []
    ) : AiConversationInterface 
    {
        return new AiConversation($handler, $conversationId, $row);
    }

    /**
     * Restores an existing conversation for the current agent and user.
     * 
     * @param AiTaskHandlerInterface $agent
     * @param string $conversationId
     * @return AiConversationInterface
     */
    public static function createConversationFromUid(
        AiTaskHandlerInterface $agent,
        string $conversationId
    ) : AiConversationInterface 
    {
        return static::createConversation($agent, $conversationId);
    }

    /**
     * Restores only explicitly supported result classes, never a tool or arbitrary stored class.
     * 
     * @throws UxonParserError
     * @param WorkbenchInterface $workbench
     * @param UxonObject $uxon
     * @return AiToolResultInterface
     */
    public static function createToolResult(WorkbenchInterface $workbench, UxonObject $uxon) : AiToolResultInterface
    {
        $class = ltrim((string) $uxon->getProperty('class'), '\\');
        if ($class !== AiToolResultString::class) {
            throw new UxonParserError($uxon, 'Unsupported stored AI tool result class: ' . $class);
        }
        $result = new AiToolResultString($workbench);
        $result->importUxonObject($uxon, ['class']);
        return $result;
    }

    /**
     * Creates an unsaved conversation message from domain values
     * 
     * The message class is selected by the type and decides the context role of the message. Messages
     * get their identity and sequence when the conversation saves them. Use `createToolCallMessage()`
     * for tool calls.
     * 
     * @param string $type AiMessageTypeDataType value
     * @param string $text What was said
     * @param \exface\Core\Interfaces\Filesystem\FileInfoInterface[] $attachments
     * @param array<string,array<array-key,mixed>> $adapterData Optional opaque data indexed by the key of its owning adapter
     * @throws InvalidArgumentException for unknown types or tool call messages
     * @return AiMessageInterface
     */
    public static function createMessage(string $type, string $text, array $attachments = [], array $adapterData = []) : AiMessageInterface
    {
        switch ($type) {
            case AiMessageTypeDataType::SYSTEM:
                if (! empty($attachments)) {
                    throw new InvalidArgumentException('Instructions cannot have attachments!');
                }
                return new InstructionsMessage($text, $adapterData);
            case AiMessageTypeDataType::USER:
                return new InputMessage($text, $attachments, $adapterData);
            case AiMessageTypeDataType::ASSISTANT:
                return new OutputMessage($text, $attachments, $adapterData);
            case AiMessageTypeDataType::WARNING:
            case AiMessageTypeDataType::ERROR:
                return new DiagnosticMessage($type, $text);
            case AiMessageTypeDataType::TOOL:
                throw new InvalidArgumentException('Use AiFactory::createToolCallMessage() to create tool call messages!');
        }
        throw new InvalidArgumentException('Unknown AI message type "' . $type . '"!');
    }

    /**
     * Creates an unsaved tool call message - e.g. from the response of an LLM requesting tools
     * 
     * @param string $text What the LLM said together with the tool request (may be empty)
     * @param \axenox\GenAI\Interfaces\AiToolCallInterface[] $calls In the order requested by the LLM
     * @param array<string,array<array-key,mixed>> $adapterData Optional opaque data indexed by the key of its owning adapter
     * @return AiToolCallMessageInterface
     */
    public static function createToolCallMessage(string $text, array $calls, array $adapterData = []) : AiToolCallMessageInterface
    {
        return new ToolCallMessage($text, $calls, [], $adapterData);
    }

    /**
     * Creates the selected AI component using the standard selectable-component factory contract.
     * 
     * @param SelectorInterface $selector
     * @param array<array-key,mixed>|null $constructorArguments
     * @return mixed
     */
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
     * 
     * @throws UxonParserError
     */
    // TODO Remove the prompt dependency from concept creation and pass it only when rendering a concept.
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

    /**
     * Creates the selected concept for the current agent prompt.
     * 
     * @param AiConceptSelectorInterface $selector
     * @param AiAgentInterface $agent
     * @param AiPromptInterface $prompt
     * @param string $placeholder
     * @param UxonObject $uxon
     * @return AiConceptInterface
     */
    public static function createConceptFromSelector(AiConceptSelectorInterface $selector, AiAgentInterface $agent, AiPromptInterface $prompt, string $placeholder, UxonObject $uxon) : AiConceptInterface
    {
        $class = static::findConceptClass($selector);
        $instance = new $class($agent, $prompt, $placeholder, $uxon);
        return $instance;
    }
    
    /**
     * Resolves the concept prototype class from its selector.
     * 
     * @throws AiConceptNotFoundError
     * @param AiConceptSelectorInterface $selector
     * @return string
     */
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

    /**
     * Creates a persisted skill referenced by an alias UXON model.
     * 
     * @throws UxonParserError
     * @param AiTaskHandlerInterface $handler
     * @param AiPromptInterface|null $prompt
     * @param string $placeholder
     * @param UxonObject $uxon
     * @return AiSkillInterface
     */
    // TODO Remove the prompt dependency from skill creation and pass it only when rendering a skill.
    public static function createSkillFromUxon(
        AiTaskHandlerInterface $handler,
        ?AiPromptInterface $prompt,
        string $placeholder,
        UxonObject $uxon
    ) : AiSkillInterface {
        $alias = trim((string) $uxon->getProperty('alias'));
        if ($alias === '') {
            throw new UxonParserError($uxon, 'Cannot instantiate AI skill: no `alias` property found in UXON model');
        }

        return static::createSkillFromSelector(
            new AiSkillSelector($handler->getWorkbench(), $alias),
            $handler,
            $prompt,
            $placeholder
        );
    }

    /**
     * Loads a skill record and instantiates its configured prototype.
     * 
     * @throws AiSkillNotFoundError
     * @param AiSkillSelectorInterface $selector
     * @param AiTaskHandlerInterface $handler
     * @param AiPromptInterface|null $prompt
     * @param string $placeholder
     * @return AiSkillInterface
     */
    public static function createSkillFromSelector(
        AiSkillSelectorInterface $selector,
        AiTaskHandlerInterface $handler,
        ?AiPromptInterface $prompt,
        string $placeholder
    ) : AiSkillInterface {
        $dataSheet = DataSheetFactory::createFromObjectIdOrAlias(
            $selector->getWorkbench(),
            'axenox.GenAI.AI_SKILL'
        );
        $dataSheet->getFilters()->addConditionFromString(
            'ALIAS_WITH_NS',
            $selector->toString(),
            ComparatorDataType::EQUALS
        );
        $dataSheet->getColumns()->addMultiple([
            'CONFIG_UXON',
            'INSTRUCTIONS',
            'PROTOTYPE_CLASS'
        ]);
        $dataSheet->dataRead();

        if ($dataSheet->countRows() !== 1) {
            throw new AiSkillNotFoundError('AI skill "' . $selector->toString() . '" not found');
        }

        $skillData = $dataSheet->getRow(0);
        $configValue = $skillData['CONFIG_UXON'] ?? null;
        $uxon = $configValue === null || $configValue === ''
            ? new UxonObject()
            : UxonObject::fromAnything($configValue);
        $uxon->setProperty('alias', $selector->toString());
        $uxon->setProperty('instructions', (string) ($skillData['INSTRUCTIONS'] ?? ''));

        $prototypePath = trim((string) ($skillData['PROTOTYPE_CLASS'] ?? ''));
        if ($prototypePath === '') {
            throw new AiSkillNotFoundError('AI skill "' . $selector->toString() . '" has no prototype class');
        }

        try {
            $class = PhpFilePathDataType::findClassInFile(
                $selector->getWorkbench()->filemanager()->getPathToVendorFolder()
                . DIRECTORY_SEPARATOR . $prototypePath
            );
            $skill = new $class($handler, $prompt, $placeholder, $uxon);
        } catch (AiSkillNotFoundError $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new AiSkillNotFoundError(
                'Cannot instantiate AI skill "' . $selector->toString() . '"',
                null,
                $e
            );
        }

        if (! $skill instanceof AiSkillInterface) {
            throw new AiSkillNotFoundError(
                'Prototype of AI skill "' . $selector->toString() . '" must implement ' . AiSkillInterface::class
            );
        }

        return $skill;
    }

    /**
     * Creates a chat-capable AI agent from its versioned selector.
     * 
     * @throws AiAgentNotFoundError
     * @param WorkbenchInterface $workbench
     * @param string $aliasWithVersion
     * @return AiAgentInterface
     */
    public static function createAgentFromString(WorkbenchInterface $workbench, string $aliasWithVersion) : AiAgentInterface
    {
        $handler = static::createTaskHandlerFromString($workbench, $aliasWithVersion);
        if (! $handler instanceof AiAgentInterface) {
            throw new AiAgentNotFoundError("AI task handler '$aliasWithVersion' does not handle AI prompts");
        }
        return $handler;
    }

    /**
     * Creates a configured task handler from an alias and semantic version constraint.
     * 
     * @throws AiAgentNotFoundError
     * @param WorkbenchInterface $workbench
     * @param string $aliasWithVersion
     * @return AiTaskHandlerInterface
     */
    public static function createTaskHandlerFromString(
        WorkbenchInterface $workbench,
        string $aliasWithVersion
    ) : AiTaskHandlerInterface
    {
        list($alias, $versionConstraint) = array_pad(explode(':', $aliasWithVersion, 2), 2, '*');

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
        $versionCol = $ds->getColumns()->get('VERSION');
        $versions = $versionCol->getValues();
        $bestFitVersion = SemanticVersionDataType::findVersionBest($versionConstraint, $versions);
        $agentRow = $ds->getRow($versionCol->findRowByValue($bestFitVersion));
        if (! (bool) $agentRow['ENABLED_FLAG']) {
            throw new AiAgentNotFoundError(
                "AI task handler '$aliasWithVersion' is disabled. Restart the MCP server after selecting an enabled endpoint version."
            );
        }
        /** @var class-string<AbstractAiTaskHandler> $prototypeClass */
        $prototypeClass = PhpFilePathDataType::findClassInFile(
            $workbench->filemanager()->getPathToVendorFolder()
            . DIRECTORY_SEPARATOR
            . $agentRow['PROTOTYPE_CLASS']
        );
        $requiresConnection = ! is_a($prototypeClass, AiTaskHandlerWithoutConnectionInterface::class, true);

        // Prepare the agent UXON
        $uxon = UxonObject::fromAnything($agentRow['CONFIG_UXON']);
        $uxon->unsetProperty('skills');
        $skillsUxon = static::loadAgentVersionSkills($workbench, $agentRow['UID']);
        if (! $skillsUxon->isEmpty()) {
            $uxon->setProperty('skills', $skillsUxon);
        }
        
        // Add required props from the data row
        $uxon->setProperty('name', $agentRow['AI_AGENT__NAME']);
        $uxon->setProperty('alias', $agentRow['AI_AGENT__ALIAS']);
        if ($requiresConnection) {
            $uxon->setProperty('instructions', $agentRow['INSTRUCTIONS']);
        }
        
        if ($requiresConnection) {
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
        }

        // Create a new selector with the exact version
        $selectorWithVersion = new AiAgentSelector($workbench, $alias . VersionedSelectorInterface::VERSION_SEPARATOR . $bestFitVersion);
        $agent = new $prototypeClass($selectorWithVersion, $uxon);

        return $agent;
    }

    /**
     * Builds the transient skill map consumed by agent prototypes.
     * 
     * @param WorkbenchInterface $workbench
     * @param string $agentVersionUid
     * @return UxonObject
     * 
     * @throws AiSkillNotFoundError
     */
    protected static function loadAgentVersionSkills(
        WorkbenchInterface $workbench,
        string $agentVersionUid
    ) : UxonObject {
        $dataSheet = DataSheetFactory::createFromObjectIdOrAlias(
            $workbench,
            'axenox.GenAI.AI_AGENT_VERSION_SKILL'
        );
        $dataSheet->getFilters()->addConditionFromString(
            'AI_AGENT_VERSION',
            $agentVersionUid,
            ComparatorDataType::EQUALS
        );
        $dataSheet->getColumns()->addMultiple([
            'AI_SKILL__ALIAS',
            'AI_SKILL__ALIAS_WITH_NS'
        ]);
        $dataSheet->getSorters()->addFromString(
            'UID',
            SortingDirectionsDataType::ASC
        );
        $dataSheet->dataRead();

        $skills = [];
        foreach ($dataSheet->getRows() as $skillRow) {
            $alias = trim((string) ($skillRow['AI_SKILL__ALIAS'] ?? ''));
            $aliasWithNamespace = trim((string) ($skillRow['AI_SKILL__ALIAS_WITH_NS'] ?? ''));
            if ($alias === '' || $aliasWithNamespace === '') {
                throw new AiSkillNotFoundError(
                    'Cannot load skills for AI agent version "' . $agentVersionUid . '": invalid skill assignment'
                );
            }
            if (isset($skills[$alias])) {
                throw new AiSkillNotFoundError(
                    'Cannot load skills for AI agent version "' . $agentVersionUid . '": duplicate skill alias "' . $alias . '"'
                );
            }
            $skills[$alias] = new UxonObject(['alias' => $aliasWithNamespace]);
        }

        return new UxonObject($skills);
    }
    
    /**
     * Resolves the configured agent connection using its exact version.
     * 
     * @param string $currentVersion
     * @param DataSheetInterface $allVersions
     * @return string|null
     */
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

    /**
     * Resolves the tool prototype class from its selector.
     * 
     * @throws AiToolNotFoundError
     * @param AiToolSelectorInterface $selector
     * @param string|null $functionName
     * @return string
     */
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
    
    /**
     * Finds a tool prototype matching the advertised function name.
     * 
     * @throws AiAgentNotFoundError
     * @param WorkbenchInterface $workbench
     * @param string $functionName
     * @return string
     */
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
     * 
     * @throws InvalidArgumentException
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