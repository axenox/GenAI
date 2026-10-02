# Testing

[Deutsch](../Translations/de/Testing/index.md)

GenAI separates fast unit tests from tests that require a configured ExFace installation. Run the
smallest applicable test scope while developing, then run the relevant integration checks for code
that crosses application or process boundaries.

## Unit tests

Unit tests cover behavior that can be exercised through plain values, reflection, or narrow
interface mocks. They must not start or mock the ExFace Workbench. Tests that need application
models, authentication, data access, or Workbench services are integration tests instead.

From a standalone GenAI checkout, run:

```shell
composer test:unit
```

When developing GenAI inside an ExFace installation, run the root PHPUnit binary:

```shell
vendor/bin/phpunit -c vendor/axenox/genai/phpunit.xml.dist --testsuite unit
```

See the [unit test contributor guide](../../Tests/Unit/Readme.md) for test placement and conventions.

## Integration tests

Integration tests should use a real Workbench instead of mocking the container and its service
graph. Their prerequisites depend on the tested feature and can include installed models,
authentication, data sources, or external services.

The MCP endpoint smoke test starts the real STDIO server and requires a configured endpoint:

```powershell
Tests/Integration/Mcp/assert-endpoint-smoke.ps1 -EndpointSelector axenox.GenAI.ide_mcp_server
```

See [MCP server testing](../MCP/index.md#automated-verification) for the verified protocol behavior.