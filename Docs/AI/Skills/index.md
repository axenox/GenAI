# AI skills

[Deutsch](index_german.md)

AI skills are reusable, non-versioned building blocks for agents. A skill can contain instructions, concepts, and tools. All three parts are optional.

Skills are managed in Power UI under **Administration > AI > AI Skills**. A skill can be global or owned by an app. Each record also has a PHP prototype, optional Markdown instructions, and optional UXON configuration. `GenericSkill` is the standard prototype.

## Using a skill

Assign skills in the skill list of an agent version. The local skill alias automatically becomes the placeholder. To include the instructions of a skill with alias `test` in the agent prompt, use it like a concept:

```markdown
You are a helpful assistant.

[#test#]
```

The placeholder is optional. If `[#test#]` is absent, the skill instructions are not added to the prompt. The skill is still loaded and its tools remain available to the agent.

## Skill configuration

A skill is configured similarly to a normal agent: its instructions contain the prompt text, while `CONFIG_UXON` contains its structured configuration. See the UXON prototypes for [`GenericAssistant`](api/docs/exface/Core/Docs/UXON/UXON_prototypes.md?selector=%5Caxenox%5CGenAI%5CAI%5CAgents%5CGenericAssistant) and [`GenericSkill`](api/docs/exface/Core/Docs/UXON/UXON_prototypes.md?selector=%5Caxenox%5CGenAI%5CAI%5CSkills%5CGenericSkill).

The standard `GenericSkill` accepts these optional properties in `CONFIG_UXON`:

- `concepts`: named concept configurations used inside the skill instructions.
- `skills`: named skills whose rendered instructions can be inserted through local placeholders.
- `tools`: named tool configurations contributed to the agent.

Concepts add background information to a skill. Nested skills can still be configured below `skills` inside the skill's own `CONFIG_UXON`:

```json
{
    "skills": {
        "lookup": {
            "alias": "my.App.lookup"
        }
    }
}
```

The tools of `my.App.lookup` are imported automatically into the current skill. To also use its instructions, insert the local name `lookup` into the current skill instructions like a concept:

```markdown
Use the following lookup instructions:

[#lookup#]
```

The included skill prepares its own concepts and nested skills before its instructions are inserted. The current skill can then use the imported instructions and tools exactly as an agent can. If the placeholder is omitted, the tools are still imported.

Tool names should be unique where possible. If the same name occurs more than once, the later nested skill takes precedence. A tool configured directly in the current skill takes precedence over its included skills, and a tool configured directly on the agent takes precedence over all skill tools. A warning is stored in the conversation when a tool is replaced this way.

## Custom prototypes

Apps can provide custom skill prototypes under `AI/Skills/*.php`. A prototype must implement `AiSkillInterface`; extending the behavior of `GenericSkill` is the normal starting point. The selected prototype controls the UXON properties offered by the Power UI editor.