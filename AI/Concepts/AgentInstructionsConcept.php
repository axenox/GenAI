<?php
namespace axenox\GenAI\AI\Concepts;

use axenox\GenAI\Common\AbstractConcept;
use axenox\GenAI\Exceptions\AiConceptConfigurationError;
use axenox\GenAI\Factories\AiFactory;

/**
 * Injects the fully rendered instructions (system prompt) of another agent as a placeholder.
 * 
 * This concept instantiates the configured agent and renders its instructions including
 * all its own concepts. This is useful to re-use complete, fully-resolved instruction
 * blocks across multiple agents without duplicating them.
 * 
 * ## Example
 * 
 * ```json
 * {
 *     "instructions": "You are a specialized assistant.\n\n[#base_instructions#]",
 *     "concepts": {
 *         "base_instructions": {
 *             "class": "\\axenox\\GenAI\\AI\\Concepts\\AgentInstructionsConcept",
 *             "agent_alias": "my.App.SomeBaseAgent"
 *         }
 *     }
 * }
 * ```
 */
class AgentInstructionsConcept extends AbstractConcept
{
    private ?string $agentAlias = null;

    /**
     * {@inheritDoc}
     * @see \axenox\GenAI\Common\AbstractConcept::getOutput()
     */
    protected function getOutput(): string
    {
        if ($this->agentAlias === null) {
            throw new AiConceptConfigurationError($this, 'Missing required property `agent_alias` for AgentInstructionsConcept "' . $this->getPlaceholder() . '"');
        }

        $agent = AiFactory::createAgentFromString($this->getWorkbench(), $this->agentAlias);
        return $agent->getSystemPrompt($this->getPrompt());
    }

    /**
     * Alias of the agent whose rendered instructions should be injected.
     * 
     * @uxon-property agent_alias
     * @uxon-type metamodel:axenox.GenAI.AI_AGENT:ALIAS_WITH_NS
     * @uxon-required true
     * 
     * @param string $alias
     * @return AgentInstructionsConcept
     */
    protected function setAgentAlias(string $alias): AgentInstructionsConcept
    {
        $this->agentAlias = $alias;
        return $this;
    }
}
