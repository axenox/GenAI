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
- `skills`: benannte Skills, deren gerenderte Instructions über lokale Platzhalter eingefügt werden können.
- `tools`: benannte Tool-Konfigurationen, die dem Agenten bereitgestellt werden.

Concepts ergänzen einen Skill um Hintergrundinformationen. Andere Skills können unter `skills` genauso wie bei einem Agenten konfiguriert werden:

```json
{
    "skills": {
        "lookup": {
            "alias": "my.App.lookup"
        }
    }
}
```

Die Tools aus `my.App.lookup` werden automatisch in den aktuellen Skill übernommen. Um zusätzlich seine Instructions zu verwenden, wird der lokale Name `lookup` wie ein Concept in die Instructions des aktuellen Skills eingefügt:

```markdown
Verwende die folgenden Anweisungen für die Suche:

[#lookup#]
```

Der eingebundene Skill bereitet zuerst seine eigenen Concepts und verschachtelten Skills auf, bevor seine Instructions eingefügt werden. Der aktuelle Skill kann die übernommenen Instructions und Tools danach genauso wie ein Agent verwenden. Fehlt der Platzhalter, werden die Tools trotzdem übernommen.

Tool-Namen sollten möglichst eindeutig sein. Kommt derselbe Name mehrfach vor, hat der später eingebundene Skill Vorrang. Ein direkt im aktuellen Skill konfiguriertes Tool hat Vorrang vor seinen eingebundenen Skills. Ein direkt am Agenten konfiguriertes Tool hat Vorrang vor allen Skill-Tools. Wenn dabei ein Tool ersetzt wird, wird eine Warnung in der Conversation gespeichert.

## Eigene Prototypen

Apps können eigene Skill-Prototypen unter `AI/Skills/*.php` bereitstellen. Ein Prototyp muss `AiSkillInterface` implementieren; die Erweiterung des Verhaltens von `GenericSkill` ist der übliche Ausgangspunkt. Der ausgewählte Prototyp bestimmt die UXON-Eigenschaften im Power-UI-Editor.