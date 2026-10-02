# Den MCP-Server testen

[English](index.md)

Der Diagnose-Selektor stellt ein fest konfiguriertes Tool namens `diagnostics` bereit. Andere
Selektoren laden aktivierte, versionierte Task-Handler aus dem ExFace-Modell und veröffentlichen nur
deren konfigurierte Tools.

## Voraussetzungen

- PHP 8.1 oder neuer muss als `php` verfügbar sein.
- Installierte Releases stellen `vendor/bin/mcp` über Composer bereit. Bei direkter Entwicklung in
  diesem Paket `vendor/axenox/genai/bin/mcp` verwenden oder `bin/mcp` im Paketverzeichnis ausführen.
- Alle Diagnoseausgaben müssen über `STDERR` erfolgen. Jede nicht leere Zeile auf `STDOUT` ist eine
  MCP-JSON-RPC-Nachricht.

## Automatischer Smoke-Test unter Windows

In `vendor/axenox/genai` ausführen:

```powershell
.\Tests\Mcp\assert-smoke.ps1
```

Das Skript initialisiert eine Session, listet Tools auf, ruft sie auf, sendet einen ungültigen
Tool-Aufruf und anschließend einen Ping. Es prüft, dass jede Zeile auf `STDOUT` gültiges JSON ist,
`diagnostics` aufgerufen werden kann, der ungültige Aufruf einen MCP-Fehler liefert und der Prozess
weitere Anfragen verarbeitet.

## Benchmark für den Workbench-Lebenszyklus

Beide Strategien in getrennten PHP-Prozessen ausführen:

```powershell
php .\Tests\Mcp\benchmark-workbench.php 5
```

Die Strategie `fresh` startet und stoppt für jede simulierte Operation eine Workbench. Die
Strategie `long-lived` verwendet eine Workbench für alle Operationen. Die Ausgabe enthält gesamte
und durchschnittliche Laufzeit sowie den maximal zusätzlich belegten Speicher. Das Ergebnis ist
eine lokale Messung und kein Grund, die Isolation der Operationen zu schwächen. Vor einer
Optimierung muss die Messung auf repräsentativen Systemen wiederholt werden.

## Phase 2: Adapter für AI-Tools

Einen aktivierten Test-Agenten mit dem Selektor `axenox.GenAI.mcp_phase_2_test:0.1.0` und folgendem
UXON erstellen:

```json
{
  "tools": {
    "search_model": {
      "alias": "axenox.GenAI.ModelSearchTool",
      "description": "Searches model components and their usages."
    }
  }
}
```

Danach ausführen:

```powershell
.\Tests\Mcp\assert-adapter-smoke.ps1
```

Der Test prüft generierte Schema-Metadaten, die Umwandlung benannter in positionale Argumente,
einen echten schreibgeschützten Aufruf von `ModelSearchTool`, die SDK-Ablehnung fehlender, falscher
und unbekannter Argumente, korrigierbare Tool-Fehler, frische Workbench-Scopes, die weitere
Verarbeitung nach Fehlern und sauberes `STDOUT`. Bei Bedarf kann mit `-AgentSelector` ein anderer
Selektor angegeben werden. `ModelSearchTool` deklariert derzeit weder Enum-Werte noch Standardwerte
für Argumente. Diese Schemafälle benötigen daher ein konfiguriertes Tool, das solche Metadaten
tatsächlich veröffentlicht.

## Phase 3: MCP-Endpunkte

Zuerst die Trennung der PHP-Interfaces prüfen:

```powershell
php .\Tests\Mcp\assert-task-handler-contract.php
```

Eine aktivierte Agent-Version mit folgenden Eigenschaften erstellen:

- `PROTOTYPE_CLASS`: `axenox/genai/AI/Agents/McpServer.php`
- keine Datenverbindung
- eine exakte semantische Version, beispielsweise `0.1.0`
- direkte `tools` in `CONFIG_UXON`, zugeordnete Skills oder beides

Zugeordnete Skills stellen ihre direkten und verschachtelten Tools bereit. MCP rendert keine
Skill-Instructions und lädt keine promptabhängigen Concepts. Direkt am Endpunkt konfigurierte Tools
überschreiben gleichnamige Skill-Tools. Nach Änderungen an Tools, Skills oder Skill-Zuordnungen muss
der MCP-Prozess neu gestartet werden.

Den Endpunkt mit seinem Namespace-Alias und seiner Version starten:

```powershell
mcp-inspector php .\bin\mcp axenox.GenAI.model_development:0.1.0
```

Der Start-Scope löst die Versionsbedingung einmalig auf. Jeder registrierte Tool-Handler behält
diesen exakten Selektor und erstellt den Endpunkt in einem frischen, mit dem Betriebssystembenutzer
authentifizierten Workbench-Scope neu. Modelländerungen werden erst nach einem Neustart des
MCP-Prozesses wirksam.

Die von einem zugeordneten Skill bereitgestellten Tools können nicht interaktiv aufgelistet werden:

```powershell
mcp-inspector --cli php .\bin\mcp axenox.GenAI.model_development:0.1.0 --method tools/list --format json
```

Für den Isolationstest einen zweiten `McpServer`-Endpunkt mit anderen Tools erstellen und
`tools/list` für beide Selektoren aufrufen. Jedes Ergebnis darf nur die Tools des jeweiligen
Endpunkts enthalten:

```powershell
mcp-inspector --cli php .\bin\mcp axenox.GenAI.model_development:0.1.0 --method tools/list
mcp-inspector --cli php .\bin\mcp customer.App.data_tools:0.1.0 --method tools/list
```

## Direkte Smoke-Session

```powershell
Get-Content .\Tests\Mcp\smoke.jsonl | php .\bin\mcp axenox.genai:diagnostics
```

Das Schließen von `STDIN` beendet den Server. Protokollantworten erscheinen auf `STDOUT`; PHP- und
Serverdiagnosen gehören auf `STDERR`.

## MCP Inspector

Den Inspector mit Node.js 22.19 oder neuer einmalig installieren:

```powershell
npm install --global @modelcontextprotocol/inspector
```

Den Web-Inspector in `vendor/axenox/genai` starten:

```powershell
mcp-inspector php .\bin\mcp axenox.genai:diagnostics
```

Die vom Inspector ausgegebene URL öffnen, verbinden, das Tool `diagnostics` auswählen und mit einem
leeren Argumentobjekt ausführen.

Für eine nicht interaktive Prüfung:

```powershell
mcp-inspector --cli php .\bin\mcp axenox.genai:diagnostics --method tools/list
mcp-inspector --cli php .\bin\mcp axenox.genai:diagnostics --method tools/call --tool-name diagnostics --tool-arg emitWarning=false
```

Das konfigurierte Phase-2-Tool prüfen:

```powershell
mcp-inspector --cli php .\bin\mcp axenox.GenAI.mcp_phase_2_test:0.1.0 --method tools/list
mcp-inspector --cli php .\bin\mcp axenox.GenAI.mcp_phase_2_test:0.1.0 --method tools/call --tool-name search_model --tool-arg search_query=AI_AGENT
```

## VS Code

Diesen Server in `.vscode/mcp.json` eintragen. Wenn die ExFace-Installation nicht der
Workspace-Stamm ist, einen absoluten Pfad verwenden:

```json
{
  "servers": {
    "exface-diagnostics": {
      "type": "stdio",
      "command": "php",
      "args": [
        "c:/wamp/www/exface/exface/vendor/axenox/genai/bin/mcp",
        "axenox.genai:diagnostics"
      ]
    }
  }
}
```

**MCP: List Servers** ausführen, `exface-diagnostics` starten und **Show Output** prüfen. In der
Tool-Auswahl muss `diagnostics` erscheinen. Ein Aufruf muss `status: ok`, den Endpoint-Selektor,
die PHP-Version und `transport: stdio` zurückgeben.

Bei einem installierten GenAI-Release ist der kürzere Pfad
`c:/wamp/www/exface/exface/vendor/bin/mcp` gleichwertig.

Nach PHP-Codeänderungen den Server neu starten. Ein vom Client gemeldeter Parserfehler bedeutet in
der Regel, dass Anwendungsausgaben nach `STDOUT` gelangt sind.