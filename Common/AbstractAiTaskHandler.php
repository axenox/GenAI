<?php
namespace axenox\GenAI\Common;

use axenox\GenAI\Common\ToolBox;
use axenox\GenAI\Exceptions\AiAgentNotFoundError;
use axenox\GenAI\Exceptions\AiAgentRuntimeError;
use axenox\GenAI\Factories\AiFactory;
use axenox\GenAI\Interfaces\AiPromptInterface;
use axenox\GenAI\Interfaces\AiSkillInterface;
use axenox\GenAI\Interfaces\AiTaskHandlerInterface;
use axenox\GenAI\Interfaces\AiToolInterface;
use axenox\GenAI\Interfaces\Selectors\AiAgentSelectorInterface;
use exface\Core\CommonLogic\Traits\AliasTrait;
use exface\Core\CommonLogic\Traits\ICanBeConvertedToUxonTrait;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\DataTypes\ComparatorDataType;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\Interfaces\DataSheets\DataSheetInterface;
use exface\Core\Widgets\DebugMessage;

/**
 * Base for configurable AI task handlers that expose tools.
 *
 * The base owns persisted handler identity, exact-version selector access and direct tool
 * configuration. Specialized handlers can contribute additional tools without inheriting prompt,
 * conversation or model-connection behavior.
 *
 * @author Andrej Kabachnik
 */
abstract class AbstractAiTaskHandler implements AiTaskHandlerInterface
{
    use ICanBeConvertedToUxonTrait;
    use AliasTrait;

    protected $workbench = null;

    private $selector = null;

    private $agentDataSheet = null;

    private $versionDataSheet = null;

    private $versionRow = null;

    /** @var AiToolInterface[]|null */
    protected ?array $tools = null;

    /** @var \Throwable[] */
    protected array $toolWarnings = [];

    /** @var UxonObject[]|null */
    private ?array $toolsUxon = null;

    /** @var AiSkillInterface[]|null */
    private ?array $skills = null;

    private ?AiPromptInterface $skillsPrompt = null;

    private UxonObject $skillsUxon;

    /**
     * @param AiAgentSelectorInterface $selector
     * @param UxonObject|null $uxon
     */
    public function __construct(AiAgentSelectorInterface $selector, UxonObject $uxon = null)
    {
        $this->workbench = $selector->getWorkbench();
        $this->selector = $selector;
        $this->skillsUxon = new UxonObject();
        if ($uxon !== null) {
            $this->importUxonObject($uxon);
        }
    }

    /**
     * Assigns reusable skills whose tools are exposed by this handler.
     *
     * @uxon-property skills
     * @uxon-type {string => metamodel:axenox.GenAI.AI_SKILL:ALIAS_WITH_NS}
     * @uxon-template {"": {"alias": ""}}
     *
     * @param UxonObject $skills
     * @return AiTaskHandlerInterface
     */
    protected function setSkills(UxonObject $skills) : AiTaskHandlerInterface
    {
        $this->skillsUxon = $skills;
        $this->skills = null;
        $this->skillsPrompt = null;
        $this->tools = null;
        return $this;
    }

    /**
     * Initializes assigned skills for tool loading and optional prompt rendering.
     */
    protected function initSkills(?AiPromptInterface $prompt = null) : void
    {
        if ($this->skills !== null && ($prompt === null || $this->skillsPrompt === $prompt)) {
            return;
        }

        $this->skills = [];
        $this->skillsPrompt = $prompt;
        foreach ($this->skillsUxon as $placeholder => $skillUxon) {
            $this->skills[] = AiFactory::createSkillFromUxon($this, $prompt, $placeholder, $skillUxon);
        }
        $this->tools = null;
    }

    /**
     * Returns assigned skills, initializing them without prompt rendering when necessary.
     *
     * @return AiSkillInterface[]
     */
    protected function getSkills() : array
    {
        if ($this->skills === null) {
            $this->initSkills();
        }
        return $this->skills;
    }

    /**
     * Configures tools exposed by this handler.
     *
     * @uxon-property tools
     * @uxon-type \axenox\GenAI\Common\AbstractAiTool[]
     * @uxon-template {"": {"alias": "", "description": ""}}
     *
     * @param UxonObject $objectWithToolDefs
    * @return AiTaskHandlerInterface
     */
    protected function setTools(UxonObject $objectWithToolDefs) : AiTaskHandlerInterface
    {
        foreach ($objectWithToolDefs as $toolName => $toolUxon) {
            $this->toolsUxon[$toolName] = $toolUxon;
        }
        $this->tools = null;
        return $this;
    }

    /**
     * Adds handler-specific tool sources before directly configured tools are applied.
     *
     * @param ToolBox $toolBox
     * @return \Throwable[]
     */
    protected function configureAdditionalTools(ToolBox $toolBox) : array
    {
        $warnings = [];
        foreach ($this->getSkills() as $skill) {
            $source = 'skill "' . $skill->getPlaceholder() . '"';
            $toolBox->appendTools($skill->getTools(), $source);
            $warnings = array_merge($warnings, $skill->getWarnings());
        }
        return $warnings;
    }

    /**
     * Initializes all configured tools once.
     */
    protected function initTools() : void
    {
        if ($this->tools !== null) {
            return;
        }

        $toolBox = new ToolBox($this->workbench);
        $warnings = $this->configureAdditionalTools($toolBox);

        foreach ($this->toolsUxon ?? [] as $toolName => $toolUxon) {
            $toolBox->prepend(
                AiFactory::createToolFromUxon($this->workbench, $toolUxon, $toolName),
                $toolName,
                'agent configuration'
            );
        }

        $this->toolWarnings = array_merge($warnings, $toolBox->getWarnings());
        $this->tools = $toolBox->getTools();
    }

    /**
     * {@inheritDoc}
    * @see AiTaskHandlerInterface::getTools()
     */
    public function getTools() : array
    {
        if ($this->tools === null) {
            $this->initTools();
        }
        return $this->tools;
    }

    /**
     * {@inheritDoc}
    * @see AiTaskHandlerInterface::getTool()
     */
    public function getTool(string $name) : AiToolInterface
    {
        foreach ($this->getTools() as $tool) {
            if ($tool->getName() === $name) {
                return $tool;
            }
        }
        throw new AiAgentRuntimeError(
            $this,
            'Tool "' . $name . '" not found!',
            $this->getAliasWithNamespace()
        );
    }

    /**
     * Adds a tool programmatically.
     *
     * @param AiToolInterface $tool
    * @return AiTaskHandlerInterface
     */
    protected function addTool(AiToolInterface $tool) : AiTaskHandlerInterface
    {
        $this->tools[$tool->getName()] = $tool;
        return $this;
    }

    /**
     * Sets the local alias loaded from the model.
     *
     * @param string $alias
    * @return AiTaskHandlerInterface
     */
    protected function setAlias(string $alias) : AiTaskHandlerInterface
    {
        $this->alias = $alias;
        return $this;
    }

    /**
     * Returns the exact selector resolved by the factory.
     *
    * @return AiAgentSelectorInterface
     */
    public function getSelector() : AiAgentSelectorInterface
    {
        return $this->selector;
    }

    /**
     * Accepts the model name during UXON import.
     *
     * @param string $name
    * @return AiTaskHandlerInterface
     */
    protected function setName(string $name) : AiTaskHandlerInterface
    {
        return $this;
    }

    /**
     * Loads the persisted handler identity.
     *
     * @return DataSheetInterface
     */
    protected function getModelData() : DataSheetInterface
    {
        if ($this->agentDataSheet === null) {
            $sheet = DataSheetFactory::createFromObjectIdOrAlias($this->workbench, 'axenox.GenAI.AI_AGENT');
            $sheet->getColumns()->addFromSystemAttributes();
            $sheet->getColumns()->addMultiple(['NAME']);
            $sheet->getFilters()->addConditionFromString(
                'ALIAS_WITH_NS',
                $this->getAliasWithNamespace(),
                ComparatorDataType::EQUALS
            );
            $sheet->dataRead();
            switch ($sheet->countRows()) {
                case 0:
                    throw new AiAgentNotFoundError('AI agent "' . $this->getSelector()->toString() . '" not found!');
                case 1:
                    break;
                default:
                    throw new AiAgentNotFoundError('Multiple AI agents found for "' . $this->getSelector()->toString() . '"!');
            }
            $this->agentDataSheet = $sheet;
        }
        return $this->agentDataSheet;
    }

    /**
     * Loads persisted version data.
     *
     * @return DataSheetInterface
     */
    protected function getVersionModelData() : DataSheetInterface
    {
        if ($this->versionDataSheet === null) {
            $sheet = DataSheetFactory::createFromObjectIdOrAlias($this->workbench, 'axenox.GenAI.AI_AGENT_VERSION');
            $sheet->getColumns()->addMultiple(['VERSION', 'ENABLED_FLAG', 'DATA_CONNECTION']);
            $sheet->dataRead();
            $this->versionDataSheet = $sheet;
        }
        return $this->versionDataSheet;
    }

    /**
     * Returns the persisted row for the selected exact version.
     *
     * @return array
     */
    protected function getVersionRow() : array
    {
        if ($this->versionRow === null) {
            $this->versionRow = $this->getVersionModelData()->getRow(
                $this->getVersionModelData()->getColumns()->get('VERSION')->findRowByValue($this->getVersion())
            );
        }
        return $this->versionRow;
    }

    /**
     * {@inheritDoc}
    * @see AiTaskHandlerInterface::getUid()
     */
    public function getUid() : string
    {
        return $this->getModelData()->getCellValue('UID', 0);
    }

    /**
     * Returns the persisted handler name.
     */
    public function getName() : string
    {
        return $this->getModelData()->getCellValue('NAME', 0);
    }

    /**
     * {@inheritDoc}
    * @see AiTaskHandlerInterface::getVersion()
     */
    public function getVersion() : string
    {
        return $this->getSelector()->getVersion();
    }

    /**
     * {@inheritDoc}
     * @see \exface\Core\Interfaces\WorkbenchDependantInterface::getWorkbench()
     */
    public function getWorkbench()
    {
        return $this->workbench;
    }

    /**
     * {@inheritDoc}
     * @see \exface\Core\Interfaces\iCanGenerateDebugWidgets::createDebugWidget()
     */
    public function createDebugWidget(DebugMessage $debugWidget)
    {
        foreach ($debugWidget->getTabs() as $tab) {
            if ($tab->getCaption() === 'AI Agent') {
                return $debugWidget;
            }
        }
        $tab = $debugWidget->createTab();
        $tab->setCaption('AI Agent');
        $tab->setWidgets(new UxonObject([[
            'widget_type' => 'InputUxon',
            'disabled' => true,
            'width' => '100%',
            'height' => '100%',
            'hide_caption' => true,
            'value' => $this->exportUxonObject()->toJson(),
        ]]));
        $debugWidget->addTab($tab);
        return $debugWidget;
    }
}