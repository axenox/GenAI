<?php
namespace axenox\GenAI\AI\Concepts;

use axenox\GenAI\Common\AbstractConcept;
use axenox\GenAI\Exceptions\AiConceptConfigurationError;
use axenox\GenAI\Interfaces\AiToolInterface;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\Facades\DocsFacade\MarkdownPrinters\UxonPrototypeMarkdownPrinter;

/**
 * Renders introductions for all tools of the current agent.
 *
 * Tool descriptions, mandatory tool rules and UXON prototype descriptions are rendered separately.
 * Each part can be enabled or disabled individually with UXON boolean properties.
 */
class ToolIntroductionConcept extends AbstractConcept
{
    private int $headingLevel = 2;
    private bool $showDescription = true;
    private bool $showRules = true;
    private bool $showPrototypeDescription = true;

    protected function getOutput(): string
    {
        $sections = [];

        foreach ($this->getAgent()->getTools() as $tool) {
            $description = $this->getToolDescription($tool);
            $rules = $this->getToolRules($tool);
            $prototypeDescription = $this->getPrototypeDescription($tool);
            $content = [];

            if (!$this->willShowDescription()) {
                $description = '';
            } elseif ($description === '') {
                $description = $prototypeDescription;
                $prototypeDescription = '';
            }

            if (!$this->willShowRules()) {
                $rules = '';
            }

            if (!$this->willShowPrototypeDescription()) {
                $prototypeDescription = '';
            }

            if ($description !== '') {
                $content[] = $description;
            }

            if ($rules !== '') {
                $content[] = $this->renderSubHeading('Rules') . "\n\n" . $rules;
            }

            if ($prototypeDescription !== '') {
                $content[] = $this->renderSubHeading('Prototype description') . "\n\n" . $prototypeDescription;
            }

            if ($content === []) {
                continue;
            }

            $sections[] = $this->renderHeading($tool->getName()) . "\n\n" . implode("\n\n", $content);
        }

        if ($sections === []) {
            return '';
        }

        return implode("\n\n---\n\n", $sections);
    }

    private function getToolDescription(AiToolInterface $tool): string
    {
        try {
            return trim($tool->getDescription());
        } catch (\Throwable $e) {
            return '';
        }
    }

    private function getToolRules(AiToolInterface $tool): string
    {
        try {
            return trim($tool->getRules() ?? '');
        } catch (\Throwable $e) {
            return '';
        }
    }

    private function getPrototypeDescription(AiToolInterface $tool): string
    {
        try {
            $printer = new UxonPrototypeMarkdownPrinter($tool->getWorkbench(), '\\' . get_class($tool));
            return trim($printer->getDescription());
        } catch (\Throwable $e) {
            return '';
        }
    }

    private function renderHeading(string $title): string
    {
        return str_repeat('#', $this->getHeadingLevel()) . ' ' . $title;
    }

    private function renderSubHeading(string $title): string
    {
        return str_repeat('#', $this->getHeadingLevel() + 1) . ' ' . $title;
    }

    /**
     * Number of `#` characters used for each tool heading.
     *
     * @uxon-property heading_level
     * @uxon-type integer
     * @uxon-template 2
     *
     * @param int $level
     * @return ToolIntroductionConcept
     */
    protected function setHeadingLevel(int $level): ToolIntroductionConcept
    {
        if ($level < 1) {
            throw new AiConceptConfigurationError($this, 'heading_level must be greater than 0.');
        }

        $this->headingLevel = $level;
        return $this;
    }

    /**
     * Set to FALSE to hide the tool description text.
     *
     * When the tool has no description, the prototype description is used as a fallback
     * as long as this option stays enabled.
     *
     * @uxon-property show_description
     * @uxon-type boolean
     * @uxon-default true
     *
     * @param bool $show
     * @return ToolIntroductionConcept
     */
    protected function setShowDescription(bool $show): ToolIntroductionConcept
    {
        $this->showDescription = $show;
        return $this;
    }

    /**
     * Set to FALSE to hide the mandatory rules section for each tool.
     *
     * @uxon-property show_rules
     * @uxon-type boolean
     * @uxon-default true
     *
     * @param bool $show
     * @return ToolIntroductionConcept
     */
    protected function setShowRules(bool $show): ToolIntroductionConcept
    {
        $this->showRules = $show;
        return $this;
    }

    /**
     * Set to FALSE to hide the UXON prototype description of each tool.
     *
     * @uxon-property show_prototype_description
     * @uxon-type boolean
     * @uxon-default true
     *
     * @param bool $show
     * @return ToolIntroductionConcept
     */
    protected function setShowPrototypeDescription(bool $show): ToolIntroductionConcept
    {
        $this->showPrototypeDescription = $show;
        return $this;
    }

    protected function getHeadingLevel(): int
    {
        return $this->headingLevel;
    }

    protected function willShowDescription(): bool
    {
        return $this->showDescription;
    }

    protected function willShowRules(): bool
    {
        return $this->showRules;
    }

    protected function willShowPrototypeDescription(): bool
    {
        return $this->showPrototypeDescription;
    }

    /**
     * @return UxonObject[]
     */
    public function getToolModels(): array
    {
        return [];
    }
}