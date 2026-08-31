<?php
namespace axenox\GenAI\AI\Skills;

use axenox\GenAI\Factories\AiFactory;
use axenox\GenAI\Exceptions\AiToolConfigurationWarning;
use axenox\GenAI\Interfaces\AiAgentInterface;
use axenox\GenAI\Interfaces\AiConceptInterface;
use axenox\GenAI\Interfaces\AiPromptInterface;
use axenox\GenAI\Interfaces\AiSkillInterface;
use axenox\GenAI\Interfaces\AiToolInterface;
use axenox\GenAI\Uxon\AiSkillUxonSchema;
use exface\Core\CommonLogic\Traits\ImportUxonObjectTrait;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\Interfaces\AppInterface;
use exface\Core\Templates\BracketHashStringTemplateRenderer;
use exface\Core\Templates\Placeholders\AppPlaceholders;
use exface\Core\Templates\Placeholders\ConfigPlaceholders;
use exface\Core\Templates\Placeholders\DataRowPlaceholders;
use exface\Core\Templates\Placeholders\FormulaPlaceholders;

/**
 * Configurable skill containing optional instructions, concepts, and tools.
 */
class GenericSkill implements AiSkillInterface
{
    use ImportUxonObjectTrait;

    private AiAgentInterface $agent;
    private AiPromptInterface $prompt;
    private string $placeholder;
    private UxonObject $uxon;
    private string $instructions = '';
    private UxonObject $conceptsUxon;
    /** @var AiSkillInterface[] */
    private array $skills = [];
    private UxonObject $toolsUxon;
    private ?string $renderedInstructions = null;
    private ?array $renderedConcepts = null;
    private ?array $tools = null;
    /** @var \Throwable[] */
    private array $warnings = [];
    private ?string $alias = null;

    /**
     * Creates a skill in the context of the consuming agent and prompt.
     */
    public function __construct(
        AiAgentInterface $agent,
        AiPromptInterface $prompt,
        string $placeholder,
        UxonObject $uxon = null
    ) {
        $this->agent = $agent;
        $this->prompt = $prompt;
        $this->placeholder = $placeholder;
        $this->uxon = $uxon ?? new UxonObject();
        $this->conceptsUxon = new UxonObject();
        $this->toolsUxon = new UxonObject();

        if ($uxon !== null) {
            $this->importUxonObject($uxon);
        }
    }

    /**
     * Resolves this skill's local placeholder to its rendered instructions.
     */
    public function resolve(array $placeholders) : array
    {
        if (! in_array($this->getPlaceholder(), $placeholders, true)) {
            return [];
        }

        return [$this->getPlaceholder() => $this->renderInstructions()];
    }

    /**
     * Returns the local placeholder configured by the consuming agent.
     */
    public function getPlaceholder() : string
    {
        return $this->placeholder;
    }

    /**
     * Returns direct and concept-contributed tools, keyed by function name.
     *
     * @return AiToolInterface[]
     */
    public function getTools() : array
    {
        if ($this->tools === null) {
            $conceptTools = $this->renderConcepts()['tools'];
            $this->tools = [];
            $toolSources = [];
            $this->warnings = [];

            foreach ($conceptTools as $toolName => $toolUxon) {
                $this->tools[$toolName] = AiFactory::createToolFromUxon(
                    $this->agent->getWorkbench(),
                    $toolUxon,
                    $toolName
                );
                $toolSources[$toolName] = 'concept in skill "' . $this->getPlaceholder() . '"';
            }

            foreach ($this->skills as $skill) {
                foreach ($skill->getTools() as $toolName => $tool) {
                    $source = 'nested skill "' . $skill->getPlaceholder() . '"';
                    if (isset($this->tools[$toolName])) {
                        $this->warnings[] = new AiToolConfigurationWarning(
                            'AI tool "' . $toolName . '" from ' . $source
                            . ' overrides the tool from ' . $toolSources[$toolName] . '.'
                        );
                    }
                    $this->tools[$toolName] = $tool;
                    $toolSources[$toolName] = $source;
                }
                $this->warnings = array_merge($this->warnings, $skill->getWarnings());
            }

            foreach ($this->toolsUxon as $toolName => $toolUxon) {
                $tool = AiFactory::createToolFromUxon(
                    $this->agent->getWorkbench(),
                    $toolUxon,
                    $toolName
                );
                $source = 'skill "' . $this->getPlaceholder() . '"';
                if (isset($this->tools[$toolName])) {
                    $this->warnings[] = new AiToolConfigurationWarning(
                        'AI tool "' . $toolName . '" from ' . $source
                        . ' overrides the tool from ' . $toolSources[$toolName] . '.'
                    );
                }
                $this->tools[$toolName] = $tool;
                $toolSources[$toolName] = $source;
            }
        }

        return $this->tools;
    }

    /**
     * Returns tool configuration warnings from this skill and its nested skills.
     *
     * @return \Throwable[]
     */
    public function getWarnings() : array
    {
        $this->getTools();
        return $this->warnings;
    }

    /**
     * Exports the effective skill configuration.
     */
    public function exportUxonObject()
    {
        return $this->uxon;
    }

    /**
     * Returns the UXON schema used by skill editors.
     */
    public static function getUxonSchemaClass() : ?string
    {
        return AiSkillUxonSchema::class;
    }

    /**
     * Sets the optional Markdown instructions resolved by the skill placeholder.
     *
     * @uxon-property instructions
     * @uxon-type string
     */
    protected function setInstructions(string $instructions) : AiSkillInterface
    {
        $this->instructions = $instructions;
        $this->renderedInstructions = null;
        return $this;
    }

    /**
     * Sets the persisted skill alias when this prototype is used in an editor.
     *
     * @uxon-property alias
     * @uxon-type metamodel:axenox.GenAI.AI_SKILL:ALIAS_WITH_NS
     * @uxon-required true
     */
    protected function setAlias(string $alias) : AiSkillInterface
    {
        $this->alias = $alias;
        return $this;
    }

    /**
     * Sets optional concepts used inside the skill instructions.
     *
     * @uxon-property concepts
     * @uxon-type \axenox\GenAI\Common\AbstractConcept
     * @uxon-template {"placeholder_name": {"alias": ""}}
     */
    protected function setConcepts(UxonObject $concepts) : AiSkillInterface
    {
        $this->conceptsUxon = $concepts;
        $this->renderedInstructions = null;
        $this->renderedConcepts = null;
        $this->tools = null;
        return $this;
    }

    /**
     * Sets reusable skills used inside this skill.
     *
     * @uxon-property skills
     * @uxon-type \axenox\GenAI\AI\Skills\GenericSkill[]
     * @uxon-template {"placeholder_name": {"alias": ""}}
     */
    protected function setSkills(UxonObject $skills) : AiSkillInterface
    {
        $this->skills = [];
        foreach ($skills as $placeholder => $skillUxon) {
            $this->skills[] = AiFactory::createSkillFromUxon(
                $this->agent,
                $this->prompt,
                $placeholder,
                $skillUxon
            );
        }
        $this->renderedInstructions = null;
        $this->renderedConcepts = null;
        $this->tools = null;
        return $this;
    }

    /**
     * Sets optional tools contributed by this skill.
     *
     * @uxon-property tools
     * @uxon-type \axenox\GenAI\Common\AbstractAiTool[]
     * @uxon-template {"": {"alias": "", "description": ""}}
     */
    protected function setTools(UxonObject $tools) : AiSkillInterface
    {
        $this->toolsUxon = $tools;
        $this->tools = null;
        return $this;
    }

    /**
     * Renders skill concepts and collects their suggested tools.
     *
     * @return array{renderer: BracketHashStringTemplateRenderer, tools: UxonObject[]}
     */
    private function renderConcepts() : array
    {
        if ($this->renderedConcepts !== null) {
            return $this->renderedConcepts;
        }

        $renderer = $this->createRenderer();
        $conceptTools = [];

        foreach ($this->conceptsUxon as $placeholder => $conceptUxon) {
            $renderedUxon = UxonObject::fromJson($renderer->render($conceptUxon->toJson()));
            $concept = AiFactory::createConceptFromUxon(
                $this->agent,
                $this->prompt,
                $placeholder,
                $renderedUxon
            );
            $renderer->addPlaceholder($concept);

            foreach ($concept->getToolModels() as $toolName => $toolUxon) {
                $conceptTools[$toolName] = $toolUxon;
            }
        }

        foreach ($this->skills as $skill) {
            $renderer->addPlaceholder($skill);
        }

        $this->renderedConcepts = ['renderer' => $renderer, 'tools' => $conceptTools];
        return $this->renderedConcepts;
    }

    /**
     * Renders the instructions once for the current prompt.
     */
    private function renderInstructions() : string
    {
        if ($this->renderedInstructions === null) {
            $rendered = $this->renderConcepts();
            $this->renderedInstructions = $rendered['renderer']->render($this->instructions);
        }

        return $this->renderedInstructions;
    }

    /**
     * Creates a renderer with the same contextual placeholders as an agent.
     */
    private function createRenderer() : BracketHashStringTemplateRenderer
    {
        $workbench = $this->agent->getWorkbench();
        $renderer = new BracketHashStringTemplateRenderer($workbench);
        $renderer->addPlaceholder(new FormulaPlaceholders($workbench, null, null, '='));
        $renderer->addPlaceholder(new ConfigPlaceholders($workbench, '~config:'));

        if (null !== $app = $this->getApp()) {
            $renderer->addPlaceholder(new AppPlaceholders($app, '~app:'));
        }
        if ($this->prompt->hasInputData()) {
            $renderer->addPlaceholder(new DataRowPlaceholders($this->prompt->getInputData(), 0, '~input:'));
        }

        return $renderer;
    }

    /**
     * Resolves the app context from the current prompt.
     */
    private function getApp() : ?AppInterface
    {
        if ($this->prompt->isTriggeredOnPage() && $this->prompt->getPageTriggeredOn()->hasApp()) {
            return $this->prompt->getPageTriggeredOn()->getApp();
        }

        return null;
    }
}