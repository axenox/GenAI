# KI-Agenten

[English](index.md)

Ein Agent ist die funktionale Einheit, die einen Prompt empfängt und eine Antwort vom LLM erzeugt. In der Konfiguration besteht ein Agent aus zwei Ebenen:

- `AI_AGENT` beschreibt die stabile Identität des Agenten, beispielsweise seinen Namen, Alias, seine Beschreibung und die Zuordnung zu einer App.
- `AI_AGENT_VERSION` beschreibt eine konkrete ausführbare Version dieses Agenten, beispielsweise ihren Prototyp, die LLM-Verbindung, Anweisungen und `CONFIG_UXON`.

Dadurch kann ein Agent mehrere Versionen besitzen. Aufrufende Komponenten verwenden den Alias des Agenten und optional eine Versionsbedingung. Die Factory wählt anschließend die passende aktivierte Version aus. Neue Prompts, Tools oder Concepts sollten daher immer dann als neue Agentenversionen gepflegt werden, wenn Verhaltensänderungen nachvollziehbar bleiben müssen.

## Verwandte Themen

- [Prompting, Concepts und Tools](prompting_german.md)
- [Tool-Referenz](../Tools/index_german.md)
- [Concept-Referenz](../Concepts/index_german.md)

## Aufbau einer Agentenversion

Eine Agentenversion legt fest, wie der Agent zur Laufzeit erstellt wird:

- `PROTOTYPE_CLASS` verweist auf die PHP-Klasse des Agenten, zum Beispiel `axenox/genai/AI/Agents/GenericAssistant.php`.
- `DATA_CONNECTION` oder die Standardverbindung bestimmt, welcher KI-Connector beziehungsweise welches Modell verwendet wird.
- `INSTRUCTIONS` enthält den System-Prompt. Er kann Markdown und Platzhalter enthalten.
- `CONFIG_UXON` ist das strukturierte Konfigurationsmodell für den Prototyp. Bei `GenericAssistant` wird es verwendet, um Tools, Concepts, das Antwortschema und weitere Prototyp-Eigenschaften zu importieren.

Der Prototyp implementiert das Verhalten in PHP. Das UXON-Modell stellt die konkrete Konfiguration für eine Version bereit. Dadurch kann derselbe Prototyp für mehrere Agenten oder Versionen wiederverwendet werden.

## Rolle von `CONFIG_UXON`

`CONFIG_UXON` ist kein zweiter Prompt, sondern die maschinenlesbare Konfiguration des Agentenprototyps. Beim Laden einer Version wird das UXON aus `AI_AGENT_VERSION.CONFIG_UXON` gelesen. Anschließend werden Name, Alias und `INSTRUCTIONS` der Version zu diesem UXON hinzugefügt. Mit dem Ergebnis wird der konfigurierte Prototyp instanziiert.

Ein typischer Ausschnitt sieht wie folgt aus:

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

Die Eigenschaftsnamen im UXON entsprechen den konfigurierbaren Eigenschaften des Prototyps. Bei `GenericAssistant` gehören `tools`, `concepts`, `response_json_schema` und weitere dokumentierte UXON-Eigenschaften zu den wichtigsten Eigenschaften.

Für Agenten, die zusätzlich eine strukturierte Selbstreflexion liefern sollen, kann `feedback_mode` aktiviert werden. In diesem Modus wird das JSON-Antwortschema um ein `feedback`-Objekt erweitert, das `tool_reasoning`, `new_tools` und `improvement_suggestions` enthält. Dadurch kann das LLM den Ablauf begründen, Toolaufrufe erklären, fehlende Tools mit Begründung vorschlagen und konkrete Verbesserungen formulieren.

## Anweisungen und Concepts

Concepts sind Platzhalter, die Teile des System-Prompts dynamisch erzeugen. Sie werden im UXON unter `concepts` gepflegt. Der Schlüssel ist der in den Anweisungen verwendete Platzhaltername.

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

Das Concept kann anschließend in die Anweisungen eingebunden werden:

```md
## Introduction to the platform

[#introduction#]
```

Zur Laufzeit rendert der Agent zunächst die Concepts und ersetzt die Platzhalter im Prompt. Concepts eignen sich für Kontext, der aus Daten, Dokumentation, dem Metamodell oder Tool-Ausgaben erzeugt wird. Einige Concepts können außerdem eigene Tool-Modelle bereitstellen; beim Rendern werden diese der Tool-Konfiguration des Agenten hinzugefügt.

## Tools konfigurieren

Tools werden in `CONFIG_UXON` unter `tools` gepflegt. Der Schlüssel ist der Funktionsname, den das LLM später verwenden kann. Eine Tool-Definition beschreibt mindestens ihren Zweck und ihre Argumente. Optional kann über `alias` oder `class` ein bestimmter Tool-Prototyp ausgewählt werden. Ohne explizite Auswahl versucht die Factory, den Tool-Prototyp anhand seines Funktionsnamens zu finden.

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

Die Beschreibung sollte dem LLM eindeutig vermitteln, wann es das Tool verwenden soll und welche Werte zulässig sind. Argumente sollten möglichst konkrete Namen, Datentypen und Beschreibungen besitzen.

## Einen Agenten erstellen

1. Erstellen Sie den Agenten in `AI_AGENT` und definieren Sie Alias, Name, Beschreibung und App-Zuordnung.
2. Erstellen Sie die erste Version in `AI_AGENT_VERSION`.
3. Wählen Sie eine geeignete `PROTOTYPE_CLASS`, für Chat-Assistenten in der Regel `GenericAssistant`.
4. Konfigurieren Sie das LLM oder die Datenverbindung.
5. Verfassen Sie die Anweisungen und fügen Sie die erforderlichen Concept-Platzhalter hinzu.
6. Konfigurieren Sie die Concepts in `CONFIG_UXON` und stimmen Sie die Platzhalternamen mit denen in den Anweisungen ab.
7. Konfigurieren Sie Tools in `CONFIG_UXON`, wenn der Agent aktiv Daten laden oder Aktionen vorbereiten muss.
8. Testen Sie den Agenten mit Testfällen und Konversationsprotokollen und pflegen Sie Verbesserungen als neue Versionen.

## Versionierung

Versionen machen das Verhalten eines Agenten reproduzierbar. Eine neue Version ist sinnvoll, wenn sich Anweisungen, Tools, Concepts, das Antwortschema oder die Modellverbindung so ändern, dass bestehende Tests oder Produktivantworten betroffen sein können.

Beim Laden eines Agenten ruft die Factory alle seine Versionen ab, sortiert sie absteigend und wählt die beste Übereinstimmung für die angeforderte Versionsbedingung aus. Besitzt eine Version keine eigene Verbindung, kann sie eine Verbindung von einer vorherigen Version erben. Dennoch sollte die aktuell von einem produktiven Agenten verwendete Version eindeutig dokumentiert sein.