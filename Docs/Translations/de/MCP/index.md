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

Den PHP-Task-Handler-Vertrag prüfen:

```powershell
php .\Tests\Mcp\assert-task-handler-contract.php
```

Für den Endpoint `ide_mcp_server` mit dem oben gezeigten Tool `SearchModelComponents` ausführen:

```powershell
.\Tests\Mcp\assert-endpoint-smoke.ps1 -EndpointSelector axenox.GenAI.ide_mcp_server
```

Der Smoke-Test prüft generierte Schema-Metadaten, die Umwandlung benannter in positionale
Argumente, einen echten schreibgeschützten Aufruf von `ModelSearchTool`, die SDK-Ablehnung
fehlender, falsch typisierter und unbekannter Argumente, korrigierbare Tool-Fehler, die weitere
Verarbeitung nach Fehlern und sauberes `STDOUT`. `ModelSearchTool` deklariert derzeit weder
Enum-Werte noch Standardwerte für Argumente. Diese Schemafälle benötigen daher ein konfiguriertes
Tool, das solche Metadaten veröffentlicht.

Die zugrunde liegende JSON-RPC-Session kann auch direkt in den Endpoint geleitet werden:

```powershell
Get-Content .\Tests\Mcp\endpoint-smoke.jsonl | php .\bin\mcp axenox.GenAI.ide_mcp_server
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