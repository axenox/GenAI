---
description: "Use when working on reusable AI skills, skill prototypes, or GenericAssistant skill configuration"
name: "AI skills"
applyTo: "AI/Skills/*.php"
---
# AI skills

AI skills are persisted, non-versioned agent building blocks with a selectable PHP prototype. They can provide optional instructions, concepts, and tools.

## Implementation rules

- Place skill prototypes in an app's `AI/Skills` folder.
- Implement `\axenox\GenAI\Interfaces\AiSkillInterface`.
- Keep instructions, concepts, and tools optional. An empty skill must remain valid.
- Append instructions of skills assigned directly to an agent after the agent instructions.
- Resolve nested skill instructions only when their local placeholder is present in the parent skill instructions.
- Make nested skill tools available independently of whether their placeholder is used.
- Reference persisted skills by namespaced alias in `GenericAssistant.skills`.
- Add a docblock to every new method and document configurable properties with UXON annotations.

## Documentation maintenance

Update `Docs/AI/Skills/index.md` and `Docs/Translations/de/AI/Skills/index.md` together whenever skill behavior or configuration changes.