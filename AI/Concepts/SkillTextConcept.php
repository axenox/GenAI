<?php
namespace axenox\GenAI\AI\Concepts;

use axenox\GenAI\Common\AbstractConcept;
use axenox\GenAI\Common\Selectors\AiSkillSelector;
use axenox\GenAI\Exceptions\AiConceptConfigurationError;
use axenox\GenAI\Factories\AiFactory;
use axenox\GenAI\Interfaces\AiSkillInterface;
use exface\Core\CommonLogic\UxonObject;

/**
 * Renders only the text of a configured skill and optionally wraps it with start/end markers.
 *
 * Use this concept when a skill should be embedded into instructions without creating a visible
 * markdown heading block. The optional markers are written in English and can be toggled on or off.
 *
 * Example:
 *
 * ```json
 * {
 *   "concepts": {
 *     "core_skill": {
 *       "class": "\\axenox\\GenAI\\AI\\Concepts\\SkillTextConcept",
 *       "skill_alias": "exface.Core.CoreKnowledge",
 *       "show_markers": true,
 *       "start_marker": "--- START SKILL ---",
 *       "end_marker": "--- END SKILL ---"
 *     }
 *   }
 * }
 * ```
 */
class SkillTextConcept extends AbstractConcept
{
    private ?string $skillAlias = null;
    private bool $showMarkers = true;
    private string $startMarker = '--- START SKILL: ';
    private string $endMarker = '--- END SKILL: ';
    private ?AiSkillInterface $skill = null;

    /**
     * {@inheritDoc}
     * @see \axenox\GenAI\Common\AbstractConcept::getOutput()
     */
    protected function getOutput(): string
    {
        if ($this->skillAlias === null || trim($this->skillAlias) === '') {
            throw new AiConceptConfigurationError(
                $this,
                'Missing required property `skill_alias` for SkillTextConcept "' . $this->getPlaceholder() . '".'
            );
        }

        $text = $this->getSkill()->getInstructions();
        $text = trim((string) $text);

        if ($text === '') {
            return '';
        }

        if ($this->showMarkers === false) {
            return $text;
        }

        $skillName = $this->getSkillName();
        return $this->startMarker . $skillName . ' ---' . "\n" . $text . "\n" . $this->endMarker . $skillName . ' ---';
    }

    /**
     * Alias of the skill whose plain text should be rendered.
     *
     * @uxon-property skill_alias
     * @uxon-type metamodel:axenox.GenAI.AI_SKILL:ALIAS_WITH_NS
     * @uxon-required true
     *
     * @param string $alias
     * @return SkillTextConcept
     */
    protected function setSkillAlias(string $alias): SkillTextConcept
    {
        $alias = trim($alias);
        if ($alias === '') {
            throw new AiConceptConfigurationError($this, 'Invalid `skill_alias` value for SkillTextConcept: empty aliases are not allowed.');
        }

        $this->skillAlias = $alias;
        $this->skill = null;
        return $this;
    }
    /**
     * Returns warnings produced while preparing the rendered skill.
     *
     * @return \Throwable[]
     */
    public function getWarnings(): array
    {
        return $this->getSkill()->getWarnings();
    }

    /**
     * Shortcut for setting the skill alias directly as a string value.
     *
     * @param string|UxonObject $value
     * @return SkillTextConcept
     */
    protected function setSkill(string|UxonObject $value): SkillTextConcept
    {
        if ($value instanceof UxonObject) {
            $value = $value->getProperty('alias') ?? $value->getProperty('skill_alias');
        }

        if (! is_string($value)) {
            throw new AiConceptConfigurationError($this, 'Invalid `skill` value for SkillTextConcept: expected a skill alias string or UXON object.');
        }

        return $this->setSkillAlias($value);
    }

    /**
     * Set to FALSE to render only the raw skill text without English markers.
     *
     * @uxon-property show_markers
     * @uxon-type boolean
     * @uxon-default true
     *
     * @param bool $value
     * @return SkillTextConcept
     */
    protected function setShowMarkers(bool $value): SkillTextConcept
    {
        $this->showMarkers = $value;
        return $this;
    }

    /**
     * English start marker written before the skill text.
     *
     * @uxon-property start_marker
     * @uxon-type string
     * @uxon-default "--- START SKILL: <name> ---"
     *
     * @param string $marker
     * @return SkillTextConcept
     */
    protected function setStartMarker(string $marker): SkillTextConcept
    {
        $marker = trim($marker);
        if ($marker === '') {
            throw new AiConceptConfigurationError($this, 'Invalid `start_marker` value for SkillTextConcept: empty markers are not allowed.');
        }

        $this->startMarker = $marker;
        return $this;
    }

    /**
     * English end marker written after the skill text.
     *
     * @uxon-property end_marker
     * @uxon-type string
     * @uxon-default "--- END SKILL: <name> ---"
     *
     * @param string $marker
     * @return SkillTextConcept
     */
    protected function setEndMarker(string $marker): SkillTextConcept
    {
        $marker = trim($marker);
        if ($marker === '') {
            throw new AiConceptConfigurationError($this, 'Invalid `end_marker` value for SkillTextConcept: empty markers are not allowed.');
        }

        $this->endMarker = $marker;
        return $this;
    }

    private function getSkillName(): string
    {
        if ($this->skillAlias === null || trim($this->skillAlias) === '') {
            return 'Skill';
        }

        $parts = explode('.', $this->skillAlias);
        return trim((string) end($parts)) ?: 'Skill';
    }

    /**
     * Loads the configured skill once for rendering and metadata access.
     */
    private function getSkill(): AiSkillInterface
    {
        if ($this->skill === null) {
            $this->skill = AiFactory::createSkillFromSelector(
                new AiSkillSelector($this->getWorkbench(), (string) $this->skillAlias),
                $this->getAgent(),
                $this->getPrompt(),
                $this->getPlaceholder()
            );
        }

        return $this->skill;
    }
}
