# AI skills

[Deutsch](index_german.md)

AI skills are reusable, non-versioned building blocks for agents. A skill can contain instructions, concepts, and tools. All three parts are optional.

Skills are managed in Power UI under **Administration > AI > AI Skills**. A skill can be global or owned by an app. Each record also has a PHP prototype, optional Markdown instructions, and optional UXON configuration. `GenericSkill` is the standard prototype.

## Using a skill

Assign skills in the skill list of an agent version. Their instructions are appended to the system prompt after the agent instructions, and their tools are made available automatically. Agent instructions do not need skill placeholders.

Placeholders are supported for skills nested inside another skill. This allows the parent skill to place nested instructions at a specific position while importing the nested tools independently.

## Skill configuration

A skill is configured similarly to a normal agent: its instructions contain the prompt text, while `CONFIG_UXON` contains its structured configuration. See the UXON prototypes for [`GenericAssistant`](api/docs/exface/Core/Docs/UXON/UXON_prototypes.md?selector=%5Caxenox%5CGenAI%5CAI%5CAgents%5CGenericAssistant) and [`GenericSkill`](api/docs/exface/Core/Docs/UXON/UXON_prototypes.md?selector=%5Caxenox%5CGenAI%5CAI%5CSkills%5CGenericSkill).

The standard `GenericSkill` accepts these optional properties in `CONFIG_UXON`:

- `concepts`: named concept configurations used inside the skill instructions.
- `skills`: named nested skills whose rendered instructions can be inserted through local placeholders.
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

## MCP endpoints

Skills assigned to an `McpServer` endpoint act as reusable tool bundles. Direct and nested skill
tools are advertised through MCP without creating an AI prompt. Skill instructions and concepts,
including tools contributed by concepts, are prompt-dependent and are therefore not loaded by MCP.
Tools configured directly on the endpoint take precedence over tools with the same name from a
skill.

Restart the MCP process after changing a skill or its assignment. Skills are non-versioned, so a
skill change affects every endpoint version that uses it after restart.

## Custom prototypes

Apps can provide custom skill prototypes under `AI/Skills/*.php`. A prototype must implement `AiSkillInterface`; extending the behavior of `GenericSkill` is the normal starting point. The selected prototype controls the UXON properties offered by the Power UI editor.