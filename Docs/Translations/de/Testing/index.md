# Tests

[English](../../../Testing/index.md)

GenAI trennt schnelle Unit-Tests von Tests, die eine konfigurierte ExFace-Installation benötigen.
Während der Entwicklung sollte der kleinstmögliche passende Testumfang ausgeführt werden. Für Code,
der Anwendungs- oder Prozessgrenzen überschreitet, folgen die relevanten Integrationstests.

## Unit-Tests

Unit-Tests prüfen Verhalten, das mit einfachen Werten, Reflection oder Mocks schmaler Interfaces
ausgeführt werden kann. Sie dürfen die ExFace Workbench weder starten noch mocken. Tests, die
Anwendungsmodelle, Authentifizierung, Datenzugriff oder Workbench-Services benötigen, sind
stattdessen Integrationstests.

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

## Integrationstests

Integrationstests verwenden eine echte Workbench, statt den Container und seinen Service-Graphen
zu mocken. Ihre Voraussetzungen hängen von der getesteten Funktion ab und können installierte
Modelle, Authentifizierung, Datenquellen oder externe Dienste umfassen.

Der MCP-Endpoint-Smoke-Test startet den echten STDIO-Server und benötigt einen konfigurierten
Endpoint:

```powershell
Tests/Integration/Mcp/assert-endpoint-smoke.ps1 -EndpointSelector axenox.GenAI.ide_mcp_server
```

Das geprüfte Protokollverhalten ist unter [MCP-Server testen](../MCP/index.md#automatische-prüfung)
beschrieben.