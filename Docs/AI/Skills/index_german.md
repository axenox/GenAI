# KI-Skills

[English](index.md)

KI-Skills sind wiederverwendbare, nicht versionierte Bausteine für Agenten. Ein Skill kann Instructions, Concepts und Tools enthalten. Alle drei Bestandteile sind optional.

Skills werden in Power UI unter **Administration > AI > AI Skills** verwaltet. Jeder Datensatz besitzt einen App-bezogenen Alias, einen PHP-Prototyp sowie optionale Markdown-Instructions und eine optionale UXON-Konfiguration. `GenericSkill` ist der Standardprototyp.

## Skill verwenden

Skills werden einem Agenten als benannte Map hinzugefügt. Beispiel:

```json
{
    "skills": {
        "test": {
            "alias": "axenox.GenAI.test"
        }
    }
}
```

Der Schlüssel der Map ist der lokale Platzhaltername. Um die Skill-Instructions in den Agent-Prompt einzufügen, wird der Skill wie ein Concept verwendet:

```markdown
Du bist ein hilfreicher Assistent.

[#test#]
```

Der Platzhalter ist optional. Fehlt `[#test#]`, werden die Skill-Instructions nicht in den Prompt eingefügt. Der Skill wird trotzdem geladen und seine Tools bleiben für den Agenten verfügbar.

## Skill-Konfiguration

Ein Skill wird ähnlich wie ein normaler Agent konfiguriert: Die Instructions enthalten den Prompt-Text, während `CONFIG_UXON` die strukturierte Konfiguration enthält. Siehe die UXON-Prototypen für [`GenericAssistant`](api/docs/exface/Core/Docs/UXON/UXON_prototypes.md?selector=%5Caxenox%5CGenAI%5CAI%5CAgents%5CGenericAssistant) und [`GenericSkill`](api/docs/exface/Core/Docs/UXON/UXON_prototypes.md?selector=%5Caxenox%5CGenAI%5CAI%5CSkills%5CGenericSkill).

Der Standardprototyp `GenericSkill` akzeptiert folgende optionale Eigenschaften in `CONFIG_UXON`:

- `concepts`: benannte Concept-Konfigurationen für die Skill-Instructions.
- `tools`: benannte Tool-Konfigurationen, die dem Agenten bereitgestellt werden.

Concept-Platzhalter können in den Markdown-Instructions des Skills genauso wie in einem Agenten verwendet werden. Concepts können zusätzlich Tools bereitstellen. Ein Skill mit leeren Instructions, ohne Concepts und ohne Tools ist gültig und liefert einen leeren String.

Bei gleichen Tool-Namen überschreibt ein späterer Skill in der `skills`-Map einen früheren Skill. Direkt am Agenten konfigurierte Tools überschreiben Tools aus Skills.

## Eigene Prototypen

Apps können eigene Skill-Prototypen unter `AI/Skills/*.php` bereitstellen. Ein Prototyp muss `AiSkillInterface` implementieren; die Erweiterung des Verhaltens von `GenericSkill` ist der übliche Ausgangspunkt. Der ausgewählte Prototyp bestimmt die UXON-Eigenschaften im Power-UI-Editor.