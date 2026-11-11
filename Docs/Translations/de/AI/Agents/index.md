# KI-Agenten

[English](../../../../AI/Agents/index.md)

Ein Agent ist die funktionale Einheit, die einen Prompt empfängt und eine Antwort vom LLM erzeugt. In der Konfiguration besteht ein Agent aus zwei Ebenen:

- `AI_AGENT` beschreibt die stabile Identität des Agenten, beispielsweise seinen Namen, Alias, seine Beschreibung und die Zuordnung zu einer App.
- `AI_AGENT_VERSION` beschreibt eine konkrete ausführbare Version dieses Agenten, beispielsweise ihren Prototyp, die LLM-Verbindung, Anweisungen und `CONFIG_UXON`.

Dadurch kann ein Agent mehrere Versionen besitzen. Aufrufende Komponenten verwenden den Alias des Agenten und optional eine Versionsbedingung. Die Factory wählt anschließend die passende aktivierte Version aus. Neue Prompts, Tools oder Concepts sollten daher immer dann als neue Agentenversionen gepflegt werden, wenn Verhaltensänderungen nachvollziehbar bleiben müssen.

## Task-Handler und MCP-Endpunkte

`AiTaskHandlerInterface` enthält die gemeinsame Identität sowie das Workbench- und Tool-Hosting.
Normale KI-Agenten erweitern diesen Vertrag über `AiAgentInterface` um Prompts, Konversationen und
Modellverbindungen. Der Prototyp `McpServer` ist ein Task-Handler, aber kein KI-Agent: Er stellt
konfigurierte Tools bereit, ohne Prompts vorzutäuschen oder eine LLM-Verbindung zu benötigen.

Für MCP-Endpunkte `axenox/genai/AI/Agents/McpServer.php` als `PROTOTYPE_CLASS` verwenden. Tools
können direkt in `CONFIG_UXON` konfiguriert oder durch Skills bereitgestellt werden, die der
Endpunktversion zugeordnet sind. Skill-Instructions, Skill-Concepts und Modellverbindungen werden
für diesen Prototyp nicht geladen.

Die [MCP-Konfigurationsreferenz](../../MCP/index.md#konversationsprotokoll-und-gruppierung) beschreibt
Konversationsgruppierung, Inaktivitätszeitfenster, Entwicklungsmodus und Chat-IDs von Clients.

## Gemeinsamer Konversationsverlauf

Agenten und MCP-Endpunkte verwenden dasselbe autorisierte Protokoll, gebunden an die genaue
Handler-Version und den angemeldeten Benutzer. `TYPE` unterscheidet `agent` und `mcp`;
`TOPIC_ID` dient nur der externen Zuordnung, niemals als Zugangsberechtigung. Der
Entwicklungsmodus wird unabhaengig von einer Modellverbindung gespeichert. Testlaeufe und
deaktivierte Agentenversionen verwenden standardmaessig den Entwicklungsmodus; eine explizite
Einstellung hat Vorrang.

Jeder empfangene Tool-Batch erzeugt vor der Ausfuehrung genau eine `tool`-Nachricht. Danach
werden die typisierten Ergebnisse derselben Nachricht ergaenzt. Einzelaufrufe stehen bereits
vor Batch-Abschluss mit `IN_PROGRESS`, `SUCCEEDED` oder `FAILED` zur Detailansicht bereit.
Eine fehlende Abschlusszeit bedeutet **Abschluss nicht protokolliert**, nicht fehlgeschlagene
Ausfuehrung oder Erlaubnis zur Wiederholung. Manuelles Aktualisieren zeigt geaenderte Details.

Jede Modellanfrage verwendet eine neue Query, die aus den aktiven Nachrichten der Konversation
(`getMessagesActive()`) erzeugt wird. Nachrichten laufen immer durch die Konversation: dort
gespeicherte Tool-Ergebnisse sind automatisch Teil der naechsten Query. Anweisungen,
Benutzernachrichten, Assistenteninhalte, abgeschlossene Tool-Runden und Anhaenge werden ohne
erneute Tool-Ausfuehrung aus dem Speicher rekonstruiert.

**Jede Konversation kann mit jeder unterstuetzten API fortgesetzt werden** - z. B. nachdem ein
Administrator die LLM-Verbindung eines Agenten gewechselt hat. API-Adapter verweigern nicht,
sondern schwaechen den Kontext ab und speichern eine Warnung in der Konversation:

- Anhaenge, die die Ziel-API nicht annimmt, und historische Anhaenge, die nicht mehr geladen werden
  koennen, werden durch einen kurzen Hinweis ersetzt, z. B.
  *"[photo.heic was attached but cannot be sent to this model]"*,
- Tool-Batches ohne Ergebnisse (z. B. unterbrochene Laeufe) werden ausgelassen - Aufrufe und
  Ergebnisse gemeinsam,
- Aufruf-IDs, die die API nicht akzeptiert, werden innerhalb der Anfrage durch sichere eindeutige
  IDs ersetzt.

Unvollstaendige Antworten (Token-Limit, pausierter Turn) behalten ihren Text und werden mit dem
Beendigungsgrund `truncated` gespeichert. Tools werden niemals erneut ausgefuehrt, um den Verlauf zu
reparieren.

Anhaenge von Benutzernachrichten werden nativ gesendet, wo die API sie unterstuetzt: JPEG-, PNG-,
GIF- und WebP-Bilder fuer alle drei APIs, PDF fuer Responses und Claude, Klartext fuer Claude. Die
Responses API akzeptiert ausserdem Text- und Quellcodedateien, Word-, RTF- und OpenDocument-Texte,
PowerPoint-Praesentationen sowie Excel- oder CSV-Tabellen. Andere Typen werden zu einem Hinweis. Von
einem Modell erzeugte Dateien (z. B. Bilder der Responses API) werden wie Benutzeranhaenge als
Anhaenge der Antwort gespeichert.

LLM-Verbrauch gehoert ausschliesslich zu Nachrichten mit LLM-Antworten. MCP-Aufrufe erhalten
keine erfundenen Modellnamen, Tokenzahlen oder Kosten. Tool-Fehler bleiben auslieferbare
Ergebnisse mit zugeordneten separaten Fehlereintraegen. Jede Tool-Warnung erzeugt ebenfalls eine
zugeordnete separate `warning`-Nachricht, ohne Fehlerbewertung oder Fehlerprotokoll-ID.
Reine Warnungen gelten als erfolgreich,
ausser wenn der Aufruf abgewiesen wurde. Protokollierungs- und Auslieferungsfehler aendern das
Ausfuehrungsergebnis nicht.

Warnungs- und Fehlerzaehler zaehlen nur separate Nachrichten mit der entsprechenden Rolle,
niemals Diagnosen im Tool-Ergebnis-JSON. Vorhandene separate Diagnosen werden weiterhin gezaehlt;
nur im JSON gespeicherte Warnungen werden nicht automatisch nachgetragen. Verlaufsmigrationen
erhalten Ergebnisfehler und -warnungen fuer die Rekonstruktion, nicht fuer die Zaehlerberechnung.

Verlaufsmigrationen nur bei gestoppten Konversationsschreibern ausfuehren. Eindeutig zugeordnete
alte Tool-Anfragen und Ergebnisse werden zu einer Zeile zusammengefasst: Ergebnis-UID und
`MESSAGE` bleiben unveraendert; `DATA` erhaelt geordnete Aufrufe und String-Ergebnisse.
Originalzeilen und Nutzdaten bleiben unter `DATA.legacy` erhalten. Aufrufdetails und LLM-Verbrauch
werden vor dem Loeschen der Anfragezeile dem Ergebnis zugeordnet. Andere Nachrichtentexte
bleiben unveraendert. Sequenznummern werden normalisiert, eindeutig und nicht-nullbar;
bereits fortgeschrittene Vergabezaehler werden niemals zurueckgesetzt.

Fehlende Ergebnisse, ungueltige Argumente, defektes JSON oder mehrdeutiger Verbrauch lassen
die betroffenen Paare unveraendert. Die Migration erfindet keine Ergebnisse und fuehrt Tools nicht
erneut aus; solche unvollstaendigen Batches werden beim Fortsetzen aus dem Modellkontext
ausgelassen. Die Fortsetzung erfordert die urspruengliche Handler-Version.

PHP-Verbraucher erhalten ueber `AiResponse::getConversation()` den gemeinsamen Verlauf und
ueber `AiResponse::getMessages()` nur die in diesem Antwort-Turn erzeugten Eintraege.
`AiFactory::createConversation($handler, $conversationId, $row)` erwartet eine DataSheet-Zeile
von `axenox.GenAI.AI_CONVERSATION` mit Attributaliasen wie `TYPE`, `DEVMODE`, `TITLE` und
`CONNECTION` als Schluesseln. Neue Zeilen benoetigen `TYPE`; ohne `DEVMODE` gilt false.
`DATA` muss, falls angegeben, bereits JSON sein. Handler, Version und angemeldeter Benutzer
werden intern gesetzt. Beim Laden per ID wird nur ein angegebenes `TYPE` geprueft;
andere Zeilenwerte ueberschreiben keine gespeicherten Metadaten.
Tool-Nachrichten implementieren `AiToolCallMessageInterface` und bieten geordnete `getCalls()`
und nach Aufruf-ID indizierte `getToolResults()`. Ergebnisse behalten ihren vollstaendigen Wert
und geordnete `getErrors()` / `getWarnings()` nach dem Laden; Exceptions existieren nur frisch.

### Nachrichten, Queries und API-Adapter

Jede Komponente hat eine klare Verantwortung, damit neue APIs oder Nachrichtentypen keine
Aenderungen an anderer Stelle erfordern:

| Komponente | Verantwortlich fuer |
|---|---|
| `AiConversation` | Einzige Komponente, die Nachrichten, Anhaenge, Tool-Aufrufdetails und opake Adapterdaten speichert und laedt, inklusive alter Verlaeufe. Waehlt den aktiven Modellkontext. |
| `AiMessageInterface` und Untertypen | Anbieterunabhaengige Fakten einer Nachricht: Kontextrolle, Text, Anhaenge, Tool-Aufrufe und Ergebnisse. |
| Agent | Speichert Prompts und Antworten ueber die Konversation, erzeugt Queries, fuehrt Tools aus. |
| `AiQueryInterface` | Ein Austausch mit dem LLM: ausgewaehlte Nachrichten, Anfrageeinstellungen, Antwortnachricht, Verbrauch und Warnungen. |
| Request-Adapter | Erzeugt die API-Anfrage aus Nachrichten und Einstellungen einer Query. |
| Response-Adapter | Erzeugt eine generische Nachricht, den Beendigungsgrund und den Verbrauch aus der API-Antwort. |

API-Adapter verwenden niemals Nachrichtentypen. Jede Nachricht teilt ihre **Kontextrolle** ueber
`isInstructions()`, `isInput()` oder `isOutput()` mit; Nachrichten, bei denen alle drei false
liefern (z. B. Warnungen und Fehler), sind nur fuer Designer bestimmt und werden nie an ein LLM
gesendet. Tool-Aufrufe sind Ausgabenachrichten mit `AiToolCallMessageInterface`. Ein neuer
Nachrichtentyp benoetigt daher eine Konstante mit Label in `AiMessageTypeDataType` und eine
Nachrichtenklasse - aber keine Adapteraenderungen.

`getText()` liefert den Modellkontext - immer Text. Eine strukturierte Antwort ist der vom LLM
erzeugte JSON-Text. `toMarkdown()` ist eine separate Darstellung fuer Designer und wird in der
Spalte `MESSAGE` gespeichert. Bei Antworten zeigt `MESSAGE` die Antwort so, wie der Agent sie
praesentiert (z. B. ueber `response_answer_path` extrahiert); die vollstaendige Antwort wird aus
`DATA` wiederhergestellt.

Response-Adapter melden nur generische Werte: Beendigungsgruende sind Konstanten von
`AiFinishReasonDataType` (`completed`, `tool_calls`, `truncated`, `refused`, `error`), der
Verbrauch wird ueber die Query gemeldet (Tokens, Modell, Kosten). Zitate werden zu einer
Quellenliste, Ablehnungen zu Text; nicht unterstuetzte Inhalte werden mit einer Warnung verworfen.
Vom Anbieter selbst ausgefuehrte Tools (z. B. eingebaute Websuche) sind bereits im Antworttext
enthalten.

Ein Adapter kann optionale **Adapterdaten** an einer Antwortnachricht ablegen, z. B.
Reasoning-Items von OpenAI oder signierte Thinking-Bloecke von Claude. Die Konversation speichert
sie als opaken Wert. Nur der zugehoerige Adapter liest sie - und nur, wenn sie vom aktuellen Modell
stammen und noch zur generischen Nachricht passen. Andernfalls wird die Anfrage allein aus
generischen Nachrichten erzeugt, genau so, als haette es die Daten nie gegeben. Aeltere
`provider`-Daten in `DATA` werden ignoriert.

Das `DATA`-Format und alle Altformate verarbeitet der interne `AiMessageSerializer`, den nur
`AiConversation` verwendet. Adapter, Agenten und Queries lesen `DATA` niemals.

Fuer eine neue API werden ein Request-Adapter (`HttpRequestAdapterInterface`) und ein
Response-Adapter (`HttpResponseAdapterInterface`) implementiert und im Connector ausgewaehlt.
Dabei helfen die anbieterunabhaengigen Methoden von `ConversationApiAdapterTrait`;
Antwortnachrichten werden mit `AiFactory::createMessage()` oder `AiFactory::createToolCallMessage()`
erzeugt. Sonst muss nichts geaendert werden.

## Verwandte Themen

- [Prompting, Concepts und Tools](prompting.md)
- [Skill-Referenz](../Skills/index.md)
- [Tool-Referenz](../Tools/index.md)
- [Concept-Referenz](../Concepts/index.md)

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

Die Eigenschaftsnamen im UXON entsprechen den konfigurierbaren Eigenschaften des Prototyps. Bei `GenericAssistant` gehören `tools`, `concepts`, `response_json_schema` und weitere dokumentierte UXON-Eigenschaften zu den wichtigsten Eigenschaften. Skills werden separat der Agent-Version zugeordnet und nicht in `CONFIG_UXON` gespeichert.

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

## Skills konfigurieren

Skills sind wiederverwendbare, nicht versionierte Konfigurationen, die in der Skill-Liste einer Agent-Version zugeordnet werden. Ihre Instructions werden nach den Agent-Instructions an den System-Prompt angehängt und ihre Tools automatisch bereitgestellt. Konfiguration, Platzhalter für verschachtelte Skills und Kollisionsregeln sind in der [Skill-Referenz](../Skills/index.md) beschrieben.

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
7. Ordnen Sie wiederverwendbare Skills in der Skill-Liste der Agent-Version zu; ihre Instructions und Tools werden automatisch eingebunden.
8. Konfigurieren Sie Tools in `CONFIG_UXON`, wenn der Agent aktiv Daten laden oder Aktionen vorbereiten muss.
9. Testen Sie den Agenten mit Testfällen und Konversationsprotokollen und pflegen Sie Verbesserungen als neue Versionen.

## Versionierung

Versionen machen das Verhalten eines Agenten reproduzierbar. Eine neue Version ist sinnvoll, wenn sich Anweisungen, Tools, Concepts, das Antwortschema oder die Modellverbindung so ändern, dass bestehende Tests oder Produktivantworten betroffen sein können.

Beim Laden eines Agenten ruft die Factory alle seine Versionen ab, sortiert sie absteigend und wählt die beste Übereinstimmung für die angeforderte Versionsbedingung aus. Besitzt eine Version keine eigene Verbindung, kann sie eine Verbindung von einer vorherigen Version erben. Dennoch sollte die aktuell von einem produktiven Agenten verwendete Version eindeutig dokumentiert sein.