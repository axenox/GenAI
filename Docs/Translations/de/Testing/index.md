# Tests

[English](../../../Testing/index.md)

GenAI trennt schnelle Unit-Tests von Tests, die eine konfigurierte ExFace-Installation benötigen.
Während der Entwicklung sollte der kleinstmögliche passende Testumfang ausgeführt werden. Für Code,
der Anwendungs- oder Prozessgrenzen überschreitet, folgen die relevanten Integrationstests.

## Unit-Tests

Unit-Tests prüfen Verhalten, das mit einfachen Werten, Reflection oder Mocks schmaler Interfaces
ausgeführt werden kann. Eine echte, nicht gestartete Workbench darf lokale Services bereitstellen,
aber niemals gemockt werden. Tests mit installierten Anwendungsmodellen, Authentifizierung,
Datenzugriff oder vollständigem Workbench-Lebenszyklus sind Integrationstests. Tests für gemeinsame
Konversationen und Adapter liegen unter `Tests/PHPUnit/Unit/Conversation/` und
`Tests/PHPUnit/Unit/Common/ApiAdapters/`.

In einem eigenständigen GenAI-Checkout werden sie so ausgeführt:

```shell
composer test:unit
```

Bei der Entwicklung von GenAI innerhalb einer ExFace-Installation wird das PHPUnit-Binary der
Installation verwendet:

```shell
vendor/bin/phpunit -c vendor/axenox/genai/phpunit.xml.dist --testsuite unit
```

Konventionen und Ablageorte beschreibt der [Leitfaden für Unit-Tests](../../../../Tests/Unit/Readme.md).

## Tools ohne LLM testen

Mit einer Verbindung auf Basis von `OpenAiToolTester` lassen sich die konfigurierten Tools
eines Agenten direkt ausfuehren. Geben Sie den konfigurierten Toolnamen und Argumente in
Anfuehrungszeichen ein, zum Beispiel:

```text
FileReadTool('axenox/genai/Common/DataQueries/AiQuery.php')
```

Jede Benutzernachricht startet einen neuen Test, auch innerhalb einer bestehenden Konversation.
Die abschliessende Antwort enthaelt nur die Toolergebnisse dieser Nachricht; fruehere Ergebnisse
und Fehler werden nicht erneut ausgegeben. Dateipfade sind relativ zum konfigurierten Basispfad
des Tools (standardmaessig das Vendor-Verzeichnis).

## Integrationstests

Integrationstests verwenden eine echte Workbench, statt den Container und seinen Service-Graphen
zu mocken. Ihre Voraussetzungen hängen von der getesteten Funktion ab und können installierte
Modelle, Authentifizierung, Datenquellen oder externe Dienste umfassen.

Die Suite fuer gemeinsame Konversationen verwendet einen installierten Handler, auf den der
aktuelle CLI-Benutzer zugreifen darf. Sie prueft echte Speicher-Neustarts, alle neun abgeschlossenen
API-Wechsel, gespeicherte Bilder, Diagnosezaehler, Feedback, Sequenzfortsetzung ab dem Maximum,
doppelte Sequenzen mit stabiler Sortierung und konkurrierende MCP-Gruppenvergabe.
Sie ruft weder Tools noch externe LLMs auf. Erzeugte Datensaetze und
Temporaerdateien werden entfernt.

```powershell
$env:GENAI_TEST_HANDLER = 'your.App.mcp_endpoint'
php vendor/bin/phpunit -c vendor/axenox/genai/phpunit.xml.dist --testsuite integration
```

Die isolierte MySQL-Migrationspruefung klont nur die drei Audit-Tabellen, prueft eindeutige
Zusammenfuehrungen, mehrdeutige Verlaeufe und die Aufhebung der Sequenzeindeutigkeit ohne
Neunummerierung. Sie prueft auch das Speichern doppelter Sequenzen und entfernt ihre Schattentabellen:

```powershell
.\Install\Sql\Tests\verify-mysql.ps1
```

Dieser Befehl wird im GenAI-Paketverzeichnis einer konfigurierten Installation ausgefuehrt.
PostgreSQL- und SQL-Server-Migrationen muessen weiterhin auf diesen Datenbanksystemen geprueft werden.

Der MCP-Endpoint-Smoke-Test startet den echten STDIO-Server und benötigt einen konfigurierten
Endpoint:

```powershell
Tests/Integration/Mcp/assert-endpoint-smoke.ps1 -EndpointSelector axenox.GenAI.ide_mcp_server
```

Das geprüfte Protokollverhalten ist unter [MCP-Server testen](../MCP/index.md#automatische-prüfung)
beschrieben.