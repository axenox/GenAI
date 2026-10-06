<?php
namespace axenox\GenAI\AI\Skills;

use axenox\GenAI\Common\ToolBox;
use axenox\GenAI\Factories\AiFactory;
use axenox\GenAI\Interfaces\AiAgentInterface;
use axenox\GenAI\Interfaces\AiConceptInterface;
use axenox\GenAI\Interfaces\AiPromptInterface;
use axenox\GenAI\Interfaces\AiSkillInterface;
use axenox\GenAI\Interfaces\AiTaskHandlerInterface;
use axenox\GenAI\Uxon\AiSkillUxonSchema;
use exface\Core\CommonLogic\Traits\ImportUxonObjectTrait;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\Exceptions\RuntimeException;
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

    private AiTaskHandlerInterface $handler;
    private ?AiPromptInterface $prompt;
    private string $placeholder;
    private UxonObject $uxon;
    private string $instructions = '';
    private UxonObject $conceptsUxon;
    /** @var AiSkillInterface[] */
    private array $skills = [];
    private UxonObject $toolsUxon;
    private ?string $renderedInstructions = null;
    /** @var AiConceptInterface[]|null */
    private ?array $concepts = null;
    private ?array $tools = null;
    /** @var \Throwable[] */
    private array $warnings = [];
    private ?string $alias = null;
    /**
     * Creates a skill for a task handler and an optional prompt-rendering context.
     */
    public function __construct(
        AiTaskHandlerInterface $handler,
        ?AiPromptInterface $prompt,
        string $placeholder,
        UxonObject $uxon = null
    ) {
        $this->handler = $handler;
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
        * {@inheritdoc}
        * @see AiSkillInterface::getInstructions()
     */
    public function getInstructions() : string
    {
        return $this->renderInstructions();
    }

    /**
        * {@inheritdoc}
        * @see AiSkillInterface::getPlaceholder()
     */
    public function getPlaceholder() : string
    {
        return $this->placeholder;
    }

    /**
        * {@inheritdoc}
     * @see PlaceholderResolverInterface::resolve()
     */
    public function resolve(array $placeholders) : array
    {
        if (! in_array($this->getPlaceholder(), $placeholders, true)) {
            return [];
        }

        return [$this->getPlaceholder() => $this->getInstructions()];
    }

    /**
        * {@inheritdoc}
        * @see AiSkillInterface::getTools()
     */
    public function getTools() : array
    {
        if ($this->tools === null) {
            $toolBox = new ToolBox($this->handler->getWorkbench());

            if ($this->prompt !== null && $this->handler instanceof AiAgentInterface) {
                foreach ($this->getConcepts() as $concept) {
                    $source = 'concept in skill "' . $this->getPlaceholder() . '"';
                    foreach ($concept->getToolModels() as $toolName => $toolUxon) {
                        $toolBox->append(
                            AiFactory::createToolFromUxon($this->handler->getWorkbench(), $toolUxon, $toolName),
                            $toolName,
                            $source
                        );
                    }
                }
            }

            foreach ($this->skills as $skill) {
                $source = 'nested skill "' . $skill->getPlaceholder() . '"';
                foreach ($skill->getTools() as $toolName => $tool) {
                    $toolBox->append($tool, $toolName, $source);
                }
            }

            foreach ($this->toolsUxon as $toolName => $toolUxon) {
                $source = 'skill "' . $this->getPlaceholder() . '"';
                $toolBox->append(
                    AiFactory::createToolFromUxon($this->handler->getWorkbench(), $toolUxon, $toolName),
                    $toolName,
                    $source
                );
            }

            $this->tools = $toolBox->getTools();
            $this->warnings = array_merge($this->warnings, $toolBox->getWarnings());
            foreach ($this->skills as $skill) {
                $this->warnings = array_merge($this->warnings, $skill->getWarnings());
            }
        }

        return $this->tools;
    }

    /**
        * {@inheritdoc}
        * @see AiSkillInterface::getWarnings()
     */
    public function getWarnings() : array
    {
        $this->getTools();
        return $this->warnings;
    }

    /**
        * {@inheritdoc}
        * @see \exface\Core\Interfaces\iCanBeConvertedToUxon::exportUxonObject()
     */
    public function exportUxonObject()
    {
        return $this->uxon;
    }

    /**
        * {@inheritdoc}
        * @see \exface\Core\Interfaces\iCanBeConvertedToUxon::getUxonSchemaClass()
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
        $this->concepts = null;
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
                $this->handler,
                $this->prompt,
                $placeholder,
                $skillUxon
            );
        }
        $this->renderedInstructions = null;
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
     * Returns the configured concept instances without rendering their output.
     *
     * @return AiConceptInterface[]
     */
    private function getConcepts() : array
    {
        // FIXME remove the inner stat of a GenericSkill defined by $this->prompt. After all, the skill itself does
        // not depend on the $prompt, the resulting instructions - do. So getInstructions() probably need a $prompt
        // parameter.
        // TODO distinguish between concepts and other prompt placeholders - like GenericAssistant does with `getConcepts()`
        // separated from `getPlaceholderResolvers($prompt)`. This will allow other skill prototypes to add their own
        // placeholders easily - similarly to ho SqlAdminAssistant adds its connection-related placeholders.
        if ($this->prompt === null || ! $this->handler instanceof AiAgentInterface) {
            return [];
        }
        if ($this->concepts === null) {
            $this->concepts = [];
            $configRenderer = $this->createRenderer();
            foreach ($this->conceptsUxon as $placeholder => $conceptUxon) {
                $renderedUxon = UxonObject::fromJson($configRenderer->render($conceptUxon->toJson()));
                $this->concepts[] = AiFactory::createConceptFromUxon(
                    $this->handler,
                    $this->prompt,
                    $placeholder,
                    $renderedUxon
                );
            }
        }

        return $this->concepts;
    }

    /**
     * Renders the instructions once for the current prompt.
     */
    private function renderInstructions() : string
    {
        if ($this->prompt === null || ! $this->handler instanceof AiAgentInterface) {
            throw new RuntimeException(
                'Cannot render instructions of skill "' . $this->getPlaceholder() . '" without an AI prompt.'
            );
        }
        if ($this->renderedInstructions === null) {
            $renderer = $this->createRenderer();
            foreach ($this->getConcepts() as $concept) {
                $renderer->addPlaceholder($concept);
            }
            foreach ($this->skills as $skill) {
                $renderer->addPlaceholder($skill);
            }
            $this->renderedInstructions = $renderer->render($this->instructions);
        }

        return $this->renderedInstructions;
    }

    /**
     * Creates a renderer with the same contextual placeholders as an agent.
     */
    private function createRenderer() : BracketHashStringTemplateRenderer
    {
        $workbench = $this->handler->getWorkbench();
        $renderer = new BracketHashStringTemplateRenderer($workbench);
        $renderer->addPlaceholder(new FormulaPlaceholders($workbench, null, null, '='));
        $renderer->addPlaceholder(new ConfigPlaceholders($workbench, '~config:'));

        if (null !== $app = $this->getApp()) {
            $renderer->addPlaceholder(new AppPlaceholders($app, '~app:'));
        }
        if ($this->prompt !== null && $this->prompt->hasInputData()) {
            $renderer->addPlaceholder(new DataRowPlaceholders($this->prompt->getInputData(), 0, '~input:'));
        }

        return $renderer;
    }

    /**
     * Resolves the app context from the current prompt.
     */
    private function getApp() : ?AppInterface
    {
        if ($this->prompt === null) {
            return null;
        }
        if ($this->prompt->isTriggeredOnPage() && $this->prompt->getPageTriggeredOn()->hasApp()) {
            return $this->prompt->getPageTriggeredOn()->getApp();
        }

        return null;
    }
}