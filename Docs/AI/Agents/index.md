# AI Agents

Ein Agent ist die fachliche Einheit, die einen Prompt entgegennimmt und eine Antwort vom LLM erzeugt. In der Konfiguration besteht ein Agent aus zwei Ebenen:

- `AI_AGENT` beschreibt die stabile Identitaet des Agenten, z.B. Name, Alias, Beschreibung und App-Zuordnung.
- `AI_AGENT_VERSION` beschreibt eine konkrete lauffaehige Version dieses Agenten, z.B. Prototyp, LLM-Verbindung, Instructions und `CONFIG_UXON`.

Dadurch kann ein Agent mehrere Versionen haben. Aufrufende Komponenten verwenden den Agent-Alias und optional eine Versionsbedingung. Die Factory waehlt daraus die passende aktivierte Version aus. Neue Prompts, Tools oder Concepts sollten deshalb als neue Agent-Version gepflegt werden, wenn sich das Verhalten nachvollziehbar aendern soll.

## Weiterfuehrende Themen

- [Prompting, Concepts und Tools](prompting.md)

## Aufbau einer Agent-Version

Eine Agent-Version legt fest, wie der Agent zur Laufzeit erzeugt wird:

- `PROTOTYPE_CLASS` zeigt auf die PHP-Klasse des Agenten, z.B. `axenox/genai/AI/Agents/GenericAssistant.php`.
- `DATA_CONNECTION` bzw. die Default-Verbindung bestimmt, welcher AI-Connector bzw. welches Modell verwendet wird.
- `INSTRUCTIONS` enthalten den System-Prompt. Sie koennen Markdown und Platzhalter enthalten.
- `CONFIG_UXON` ist das strukturierte Konfigurationsmodell fuer den Prototyp. Beim `GenericAssistant` werden daraus u.a. Tools, Concepts, Antwortschema und weitere Prototyp-Eigenschaften importiert.

Der Prototyp liefert das Verhalten in PHP. Das UXON-Modell liefert die konkrete Konfiguration fuer eine Version. So kann derselbe Prototyp fuer mehrere Agenten oder Versionen wiederverwendet werden.

## Rolle von `CONFIG_UXON`

`CONFIG_UXON` ist kein zweiter Prompt, sondern die maschinenlesbare Konfiguration des Agent-Prototyps. Beim Laden einer Version wird das UXON aus `AI_AGENT_VERSION.CONFIG_UXON` gelesen. Danach werden Name, Alias und `INSTRUCTIONS` der Version in dieses UXON uebernommen und der konfigurierte Prototyp damit instanziiert.

Ein typischer Ausschnitt sieht so aus:

```json
{
  "tools": {
    "GetObject": {
      "description": "Load information about a metaobject.",
      "arguments": [
        {
          "name": "object_alias",
          "data_type": { "alias": "exface.Core.String" },
          "description": "Fully qualified object alias or UID"
        }
      ]
    }
  },
  "concepts": {
    "sitemap": {
      "alias": "axenox.GenAI.AppDocsConcept",
      "depth": 0,
      "app_alias": "exface.Core",
      "starting_page": "sitemap.md"
    }
  }
}
```

Die Property-Namen im UXON entsprechen den konfigurierbaren Eigenschaften des Prototyps. Beim `GenericAssistant` sind besonders `tools`, `concepts`, `response_json_schema` und weitere dokumentierte UXON-Properties relevant.

## Instructions und Concepts

Concepts sind Platzhalter, die Teile des System-Prompts dynamisch erzeugen. Im UXON werden sie unter `concepts` gepflegt. Der Key ist der Platzhaltername, der in den Instructions verwendet wird.

Beispiel:

```json
{
  "concepts": {
    "introduction": {
      "alias": "axenox.GenAI.AppDocsConcept",
      "depth": 0,
      "app_alias": "exface.Core",
      "starting_page": "Getting_started/introduction.md"
    }
  }
}
```

In den Instructions kann das Concept dann eingebunden werden:

```md
## Introduction to the platform

[#introduction#]
```

Zur Laufzeit rendert der Agent zuerst die Concepts und ersetzt die Platzhalter im Prompt. Concepts eignen sich fuer Kontext, der aus Daten, Dokumentation, Metamodell oder Tool-Ausgaben erzeugt wird. Einige Concepts koennen ausserdem eigene Tool-Modelle bereitstellen; diese werden beim Rendern zusaetzlich in die Tool-Konfiguration des Agenten uebernommen.

## Tools einpflegen

Tools werden im `CONFIG_UXON` unter `tools` gepflegt. Der Key ist der Funktionsname, den das LLM spaeter verwenden kann. Die Tool-Definition beschreibt mindestens Zweck und Argumente. Optional kann ueber `alias` oder `class` ein konkreter Tool-Prototyp angegeben werden. Ohne expliziten Selector versucht die Factory, den Tool-Prototyp ueber den Funktionsnamen zu finden.

Beispiel:

```json
{
  "tools": {
    "GetLogEntry": {
      "arguments": [
        {
          "name": "LogId",
          "data_type": { "alias": "exface.Core.String" },
          "description": "The Log-ID visible to the designer"
        }
      ],
      "description": "Read the log entry for a visible Log-ID."
    }
  }
}
```

Die Beschreibung sollte dem LLM klar sagen, wann das Tool verwendet werden soll und welche Werte erlaubt sind. Argumente sollten moeglichst konkrete Namen, Datentypen und Beschreibungen haben.

## Vorgehen beim Bauen eines Agenten

1. Agent in `AI_AGENT` anlegen und Alias, Name, Beschreibung sowie App-Zuordnung festlegen.
2. Erste Version in `AI_AGENT_VERSION` anlegen.
3. Passenden `PROTOTYPE_CLASS` waehlen, meistens `GenericAssistant` fuer Chat-Assistenten.
4. LLM- bzw. Datenverbindung hinterlegen.
5. Instructions schreiben und darin benoetigte Concept-Platzhalter setzen.
6. Concepts im `CONFIG_UXON` konfigurieren und die Platzhalternamen mit den Instructions abgleichen.
7. Tools im `CONFIG_UXON` konfigurieren, falls der Agent aktiv Daten laden oder Aktionen vorbereiten soll.
8. Agent mit Testfaellen und Conversation-Logs pruefen und Verbesserungen als neue Versionen pflegen.

## Versionierung

Versionen dienen dazu, Agent-Verhalten reproduzierbar zu machen. Eine neue Version ist sinnvoll, wenn sich Instructions, Tools, Concepts, Antwortschema oder Modellverbindung so aendern, dass vorhandene Tests oder produktive Antworten anders ausfallen koennen.

Beim Laden sucht die Factory alle Versionen eines Agenten, sortiert sie absteigend und waehlt die beste Version zur angefragten Versionsbedingung. Falls eine Version keine eigene Verbindung hat, kann eine Verbindung aus einer vorherigen Version uebernommen werden. Trotzdem sollte fuer produktive Agenten klar dokumentiert sein, welche Version aktiv verwendet wird.