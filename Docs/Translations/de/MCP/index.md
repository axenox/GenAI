# MCP-Server

[English](../../../MCP/index.md)

Der GenAI-MCP-Server stellt Tools aktivierter, versionierter `McpServer`-Task-Handler aus dem
ExFace-Modell bereit. Jeder Prozess bedient genau einen Endpoint-Selektor und veröffentlicht nur die
direkt an diesem Endpoint konfigurierten oder von zugeordneten Skills beigesteuerten Tools.

## Voraussetzungen

- PHP 8.1 oder neuer muss als `php` verfügbar sein.
- Installierte Releases stellen `vendor/bin/mcp` über Composer bereit. Bei direkter Entwicklung in
  diesem Paket `vendor/axenox/genai/bin/mcp` verwenden oder `bin/mcp` im Paketverzeichnis ausführen.
- Alle Anwendungs- und Diagnoseausgaben müssen über `STDERR` erfolgen. Jede nicht leere Zeile auf
  `STDOUT` ist eine MCP-JSON-RPC-Nachricht.

## Endpoint konfigurieren

Eine aktivierte Agent-Version mit folgenden Eigenschaften erstellen:

- `PROTOTYPE_CLASS`: `axenox/genai/AI/Agents/McpServer.php`
- keine Datenverbindung
- eine exakte semantische Version, beispielsweise `0.1.0`
- direkte `tools` in `CONFIG_UXON`, zugeordnete Skills oder beides

Zum Beispiel:

```json
{
  "conversation_inactivity_timeout_seconds": 300,
  "conversation_grouping_scope": "session",
  "devmode": false,
  "tools": {
    "SearchModelComponents": {
      "alias": "axenox.GenAI.ModelSearchTool",
      "description": "Searches model components and their usages."
    }
  }
}
```

Zugeordnete Skills stellen ihre direkten und verschachtelten Tools bereit. MCP rendert keine
Skill-Instructions und lädt keine promptabhängigen Concepts. Direkt am Endpoint konfigurierte Tools
überschreiben gleichnamige Skill-Tools.

Der Start-Scope löst die Versionsbedingung einmalig auf. Jeder registrierte Tool-Handler behält
diesen exakten Selektor und erstellt den Endpoint in einem frischen, mit dem Betriebssystembenutzer
authentifizierten Workbench-Scope neu. Nach Änderungen an Tools, Skills, Skill-Zuordnungen oder der
Endpoint-Konfiguration muss der MCP-Prozess neu gestartet werden.

## Konversationsprotokoll und Gruppierung

MCP verwendet die gemeinsame Konversationsübersicht ohne LLM-Verbindung. Jede `tools/call`-Anfrage
erzeugt **vor** SDK-Validierung oder Ausführung eine `tool`-Nachricht. Das typisierte Ergebnis
ergänzt dieselbe Nachricht. Gleichzeitige Anfragen bleiben getrennte Nachrichten. Detaildatensätze
zeigen `IN_PROGRESS`, `SUCCEEDED` oder `FAILED`; eine unvollständige kombinierte Nachricht bedeutet
lediglich, dass der Abschluss nicht protokolliert wurde, nicht dass eine Wiederholung sicher ist.

Parallele Anfragen duerfen dieselbe Nachrichtensequenznummer haben. Jede Konversationsinstanz
liest beim Laden ihrer Metadaten das aktuelle Maximum und zaehlt danach lokal weiter; ein
gemeinsamer Sequenzzaehler wird nicht gespeichert. Der Verlauf wird nach Sequenz und danach nach
Nachrichten-UID sortiert. Ergebnisse werden ueber Nachrichten- und Aufrufidentitaeten zugeordnet,
nicht ueber Sequenznummern.
Dies ist keine globale zeitliche Reihenfolge: Eine Anfrage mit einem aelteren geladenen Maximum
kann spaeter mit einer kleineren Sequenz speichern. Zur zeitlichen Einordnung dienen die Zeitstempel.

Konversationen haben `TYPE = mcp`, eine explizite `TOPIC_ID` und einen deterministischen Titel aus
Endpoint-Name, erstem angefragten Tool und Startzeit. Sie enthalten nur für das Framework sichtbare
Anfragen und Ergebnisse, keine externen KI-Instructions, Benutzerprompts, Antworten, Tokenzahlen
oder Abrechnungsdaten. Ohne explizite Chat-ID ist das Protokoll eine **MCP-Sitzung**, nicht genau ein
VS-Code-Chat.

| UXON-Eigenschaft | Standard | Bedeutung |
| --- | --- | --- |
| `conversation_inactivity_timeout_seconds` | `300` | Positives ganzzahliges Inaktivitätsfenster, das bei jedem Anfrageeingang verlängert wird. An der Grenze beginnt eine neue Konversation. |
| `conversation_grouping_scope` | `"session"` | `"session"` trennt MCP-Sitzungen; `"user"` kombiniert Sitzungen desselben authentifizierten Benutzers und derselben exakten Endpoint-Version. |
| `devmode` | `false` | Neue Konversationen unabhängig von einer Modellverbindung als Entwicklungsaktivität kennzeichnen. Bestehende behalten ihre Klassifizierung. |

Beispielsweise gehören Anfragen um 14:00, 14:04 und 14:08 zusammen; um 14:14 beginnt eine neue
Konversation. Benutzergruppierung kann parallele Clients und Chats zusammenfassen. Die Grenzen
von Endpoint-Version und authentifiziertem Benutzer gelten immer. `TOPIC_ID` dient der Korrelation,
niemals als Berechtigungsnachweis. Eindeutige aktive Gruppenschlüssel und bedingte DataSheet-Updates
sichern die Gruppenvergabe, ohne während der Tool-Ausführung eine Transaktion offen zu halten.
Titeländerungen und Bewertungen beeinflussen die Aktivitätszeit nicht.

Clients können eine nicht leere Zeichenkette als `_meta["axenox.GenAI/chat_id"]` übermitteln.
Unterschiedliche Chat-IDs werden innerhalb des konfigurierten Bereichs nie zusammengeführt;
Gruppen mit Chat-ID laufen nicht ab. Es wird nicht angenommen, dass VS Code diese Erweiterung
automatisch sendet. Fortschrittstokens, Anfrage-IDs und Clientnamen sind keine Chat-IDs.
Bei modernen zustandslosen STDIO-Anfragen bildet eine UUID je Prozess/Verbindung die Sitzungsgrenze;
die native SDK-Sitzungs-UUID je Anfrage wird separat gespeichert. JSON-RPC-Anfrage-IDs behalten
ihren Zeichenketten-/Ganzzahltyp und sind keine Ausführungsidentitäten.

Beobachtbare Ablehnungen unbekannter Tools oder ungültiger Schemas werden als fehlgeschlagene
Versuche mit korrigierenden Warnungen gespeichert, ohne Tool-Ausführung oder separate
Konversationsfehler. Tool-Berechtigungsablehnungen folgen derselben Regel, ohne geschützte Details
zu speichern oder Endpoint-Zugriff zu gewähren. Für ungültige Eingaben, die vor Entstehung eines
SDK-`CallToolRequest` abgewiesen werden, wird kein Tool-Eintrag zugesichert.

Das initiale Schreiben der kombinierten Nachricht ist zwingend: Schlägt es fehl, wird die
Ausführung gesperrt. Fehler beim Schreiben von Details oder Abschlüssen werden unabhängig gemeldet
und verwerfen keine bereits im Speicher vorhandenen Ergebnisse. Konvertierungs-/Übertragungsfehler
behalten das ursprüngliche Ausführungsergebnis bei. Protokollierungsfehler lösen keine automatische
Wiederholung, Datenreparatur oder erneute Seiteneffekte aus. Echte Ausführungs- und Infrastrukturfehler
erscheinen als separate Konversationsfehler, soweit die Speicherung noch verfügbar ist.

### Argumenttreue und Lebenszyklus

Argumentbindung, Standardwerte und Parameter-Parsing liegen in der gemeinsamen Tool-Methode
`normalizeArguments()`, aufgerufen durch den Task-Handler statt durch den MCP-Adapter.
Nur ausgelassene Parameter erhalten Standardwerte. Ausgelassene optionale Parameter ohne
Standardwert werden `null`; explizites `null` bleibt bei optionalen Parametern erhalten und
wird bei Pflichtparametern abgewiesen. Pflichtparameter mit Standardwert duerfen ausgelassen
werden. Das angebotene MCP-Schema folgt dieser Regel, erhaelt benutzerdefinierte Schemas und
erlaubt `null` fuer optionale Parameter. Unbekannte Argumente, fehlende Pflichtwerte und
Parameter-Parsingfehler verhindern den Aufruf.

Ursprüngliche, im SDK sichtbare benannte Argumente und separat geparste positionale
Ausführungsargumente werden gespeichert. Das offizielle SDK dekodiert JSON assoziativ; Unterschiede
zwischen verschachtelten `{}` und `[]` können daher bereits vor GenAI verloren gehen. Erneut
kodierte SDK-Argumente sind nicht das ursprüngliche rohe JSON. GenAI ersetzt weder das SDK-Framing
noch beansprucht es für diese Eingaben Originaltreue auf Transportebene.

SDK-Events öffnen einen authentifizierten Empfangs-Scope, speichern die Anfrage und schließen
diese Workbench. Eine operationslokale Bridge bindet skalare Korrelationsdaten an die tatsächliche
Anfrage und deren Ausführungs-Fiber. Die Ausführung öffnet eine neue authentifizierte Workbench
und lädt die autorisierte Konversation. Die langlebige Bridge hält keine Workbench-, Task-Handler-,
Tool- oder Konversationsinstanzen; Operationsdaten werden bei SDK-Antwort oder -Fehler entfernt.
Schema-Validierung und Protokollantworten bleiben Aufgabe des offiziellen SDK.

Tools erhalten einen `axenox\GenAI\Common\Mcp\AiMcpTask`, der Core-`GenericTask` erweitert.
Das optionale dritte Konstruktorargument ist ein `UxonObject`, das über die geerbte Methode
`importUxonObject()` importiert wird. Typisierte Eigenschaften mit geschützten, UXON-annotierten
Settern stellen `endpoint_selector`, `conversation_id`, `message_id`, `call_id`, `mcp_session_id`,
`mcp_request_id` und `mcp_chat_id` bereit. Der Export erhält diese Werte und generische Task-Eingaben.
Der Aufruf-Handler überträgt vertrauenswürdige Bridge-Metadaten explizit in diese UXON; Tool-Argumente
des Clients werden nicht als Task-Konfiguration importiert. Optionale IDs sind standardmäßig
`null`; Anfrage-IDs behalten ihren Zeichenketten-/Ganzzahltyp.

## Endpoint starten und prüfen

Den Endpoint mit seinem Namespace-Alias und seiner Version ausführen:

```powershell
mcp-inspector php .\bin\mcp axenox.GenAI.ide_mcp_server
```

Falls erforderlich, den Inspector einmalig mit Node.js 22.19 oder neuer installieren:

```powershell
npm install --global @modelcontextprotocol/inspector
```

Tools nicht interaktiv auflisten und aufrufen:

```powershell
mcp-inspector --cli php .\bin\mcp axenox.GenAI.ide_mcp_server --method tools/list --format json
mcp-inspector --cli php .\bin\mcp axenox.GenAI.ide_mcp_server --method tools/call --tool-name SearchModelComponents --tool-arg search_query=AI_AGENT
```

Für den Isolationstest einen zweiten `McpServer`-Endpoint mit anderen Tools erstellen und
`tools/list` für beide Selektoren aufrufen. Jedes Ergebnis darf nur die Tools des jeweiligen
Endpoints enthalten:

```powershell
mcp-inspector --cli php .\bin\mcp axenox.GenAI.ide_mcp_server --method tools/list
mcp-inspector --cli php .\bin\mcp customer.App.data_tools:0.1.0 --method tools/list
```

## Automatische Prüfung

Die Workbench-unabhängigen MCP-Unit-Tests ausführen:

```shell
composer test:unit
```

Die vollständige Teststrategie und der Befehl für eine ExFace-Installation sind unter
[Tests](../Testing/index.md) beschrieben.

Für den Endpoint `ide_mcp_server` mit dem oben gezeigten Tool `SearchModelComponents` ausführen:

```powershell
.\Tests\Integration\Mcp\assert-endpoint-smoke.ps1 -EndpointSelector axenox.GenAI.ide_mcp_server
```

Der Smoke-Test prüft generierte Schema-Metadaten, die Umwandlung benannter in positionale
Argumente, einen echten schreibgeschützten Aufruf von `ModelSearchTool`, die SDK-Ablehnung
fehlender, falsch typisierter und unbekannter Argumente, korrigierbare Tool-Fehler, die weitere
Verarbeitung nach Fehlern und sauberes `STDOUT`. `ModelSearchTool` deklariert derzeit weder
Enum-Werte noch Standardwerte für Argumente. Diese Schemafälle benötigen daher ein konfiguriertes
Tool, das solche Metadaten veröffentlicht.

`McpConversationContractTest` prüft zusätzlich Gruppierungsgrenzen, die Trennung expliziter Chats,
Anfrage-/Fiber-Isolation, gesperrte Ausführung bei fehlender initialer Speicherung, Ablehnungen
durch das offizielle SDK vor der Ausführung und Konvertierung dauerhafter Diagnosen. Diese
Workbench-freien Tests beweisen weder konkurrierende Datenbankvergabe noch Autorisierung.
Vor dem Live-Smoke-Test die Konversationsschema-/Modellmigrationen anwenden; andernfalls bleiben
Tool-Aufrufe absichtlich gesperrt, statt ohne Protokoll ausgeführt zu werden. In der konfigurierten
Installation außerdem initiale und abgeschlossene Nachrichten, abgewiesene Versuche, gemeinsame
Gruppierung und überlappende Anfragen im gespeicherten Protokoll prüfen.

Die zugrunde liegende JSON-RPC-Session kann auch direkt in den Endpoint geleitet werden:

```powershell
Get-Content .\Tests\Integration\Mcp\endpoint-smoke.jsonl | php .\bin\mcp axenox.GenAI.ide_mcp_server
```

Das Schließen von `STDIN` beendet den Server. Protokollantworten erscheinen auf `STDOUT`; PHP- und
Serverdiagnosen gehören auf `STDERR`.

## VS Code

Den Endpoint in `.vscode/mcp.json` eintragen. Wenn die ExFace-Installation nicht der
Workspace-Stamm ist, einen absoluten Pfad verwenden:

```json
{
  "servers": {
    "exface-ide": {
      "type": "stdio",
      "command": "php",
      "args": [
        "c:/wamp/www/exface/exface/vendor/axenox/genai/bin/mcp",
        "axenox.GenAI.ide_mcp_server"
      ]
    }
  }
}
```

**MCP: List Servers** ausführen, `exface-ide` starten und **Show Output** prüfen. Die
Tool-Auswahl darf nur die für den Endpoint konfigurierten Tools enthalten.

Bei einem installierten GenAI-Release ist der kürzere Pfad
`c:/wamp/www/exface/exface/vendor/bin/mcp` gleichwertig. Nach Änderungen am PHP-Code oder an der
Modellkonfiguration den Server neu starten. Ein vom Client gemeldeter Parserfehler bedeutet in der
Regel, dass Anwendungsausgaben nach `STDOUT` gelangt sind.