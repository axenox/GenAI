# Unit tests

Unit tests must not start or mock the ExFace Workbench. They cover code that can be exercised through
plain values, reflection, or narrow interface mocks. Put unit test classes below `Tests/Unit` and
name them with the `Test.php` suffix.

Run the suite from a standalone GenAI checkout with:

```shell
composer test:unit
```

When GenAI is developed inside an ExFace installation, run the root PHPUnit binary instead:

```shell
vendor/bin/phpunit -c vendor/axenox/genai/phpunit.xml.dist --testsuite unit
```

If a test needs application models, authentication, data access, or a configured Workbench, place it
with the relevant integration tests and use a real Workbench instead of mocking the container and its
service graph.