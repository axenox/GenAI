# AI skills

[Deutsch](index_german.md)

AI skills are reusable, non-versioned building blocks for agents. A skill can contain instructions, concepts, and tools. All three parts are optional.

Skills are managed in Power UI under **Administration > AI > AI Skills**. Each record has an app-scoped alias, a PHP prototype, optional Markdown instructions, and optional UXON configuration. `GenericSkill` is the standard prototype.

## Using a skill

Add skills to an agent as a named map. Example:

```json
{
    "skills": {
        "test": {
            "alias": "axenox.GenAI.test"
        }
    }
}
```

The map key is the local placeholder name. To include the skill instructions in the agent prompt, use it like a concept:

```markdown
You are a helpful assistant.

[#test#]
```

The placeholder is optional. If `[#test#]` is absent, the skill instructions are not added to the prompt. The skill is still loaded and its tools remain available to the agent.

## Skill configuration

A skill is configured similarly to a normal agent: its instructions contain the prompt text, while `CONFIG_UXON` contains its structured configuration. See the UXON prototypes for [`GenericAssistant`](api/docs/exface/Core/Docs/UXON/UXON_prototypes.md?selector=%5Caxenox%5CGenAI%5CAI%5CAgents%5CGenericAssistant) and [`GenericSkill`](api/docs/exface/Core/Docs/UXON/UXON_prototypes.md?selector=%5Caxenox%5CGenAI%5CAI%5CSkills%5CGenericSkill).

The standard `GenericSkill` accepts these optional properties in `CONFIG_UXON`:

- `concepts`: named concept configurations used inside the skill instructions.
- `tools`: named tool configurations contributed to the agent.

Concept placeholders can be used in the skill's Markdown instructions in the same way as in an agent. Concepts may also contribute tools. A skill with empty instructions, no concepts, and no tools is valid and resolves to an empty string.

When tool names collide, a later skill in the agent's `skills` map overrides an earlier skill. Tools configured directly on the agent override tools contributed by skills.

## Custom prototypes

Apps can provide custom skill prototypes under `AI/Skills/*.php`. A prototype must implement `AiSkillInterface`; extending the behavior of `GenericSkill` is the normal starting point. The selected prototype controls the UXON properties offered by the Power UI editor.