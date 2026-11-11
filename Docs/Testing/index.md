# Testing

[Deutsch](../Translations/de/Testing/index.md)

GenAI separates fast unit tests from tests that require a configured ExFace installation. Run the
smallest applicable test scope while developing, then run the relevant integration checks for code
that crosses application or process boundaries.

## Unit tests

Unit tests cover behavior that can be exercised through plain values, reflection, or narrow
interface mocks. A real, unstarted Workbench may supply local services, but must never be mocked.
Tests that need installed application models, authentication, data access, or the full Workbench
lifecycle are integration tests instead. Shared conversation and adapter tests live under
`Tests/PHPUnit/Unit/Conversation/` and `Tests/PHPUnit/Unit/Common/ApiAdapters/`.

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

The shared-conversation suite uses an installed handler authorized for the current CLI user.
It verifies real storage-only restarts, all nine completed API transitions, retained images,
diagnostic counters, feedback, MAX-based sequence continuation, duplicate sequences with stable
ordering, and concurrent MCP grouping allocation. It does not
invoke tools or call live LLM providers. Generated records and temporary files are removed.

```powershell
$env:GENAI_TEST_HANDLER = 'your.App.mcp_endpoint'
php vendor/bin/phpunit -c vendor/axenox/genai/phpunit.xml.dist --testsuite integration
```

The isolated MySQL legacy migration fixture clones only the three audit tables, checks
reliable merges, ambiguous histories, and removal of sequence uniqueness without renumbering.
It also verifies that duplicate sequences can be stored, then removes its shadow tables:

```powershell
.\Install\Sql\Tests\verify-mysql.ps1
```

Run that command from the GenAI package directory in a configured installation.
PostgreSQL and SQL Server migrations still require validation on those database engines.

The MCP endpoint smoke test starts the real STDIO server and requires a configured endpoint:

```powershell
Tests/Integration/Mcp/assert-endpoint-smoke.ps1 -EndpointSelector axenox.GenAI.ide_mcp_server
```

See [MCP server testing](../MCP/index.md#automated-verification) for the verified protocol behavior.