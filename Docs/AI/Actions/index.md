# Actions

[Deutsch](index_german.md)

## Call Agent

The `axenox.GenAI.CallAgent` action sends its input DataSheet to a configured AI agent and returns the response as a regular action success message rendered from Markdown. Raw HTML from the agent is escaped before rendering. The DataSheet is always included in the user prompt as a Markdown table.

### UXON properties

| Property | Required | Description |
| --- | --- | --- |
| `agent_alias` | Yes | Agent alias, optionally followed by a version, for example `axenox.GenAI.MyAgent:1.0`. |
| `additional_prompt` | No | Additional instructions placed before or after the Markdown table. |
| `additional_prompt_position` | No | `before_data_sheet` or `after_data_sheet`. Defaults to `before_data_sheet`. |

Place the additional prompt before the DataSheet:

```json
{
  "alias": "axenox.GenAI.CallAgent",
  "agent_alias": "my.App.DataReviewer:1.0",
  "additional_prompt": "Review the following records and identify inconsistencies.",
  "additional_prompt_position": "before_data_sheet"
}
```

Place it after the DataSheet:

```json
{
  "alias": "axenox.GenAI.CallAgent",
  "agent_alias": "my.App.DataReviewer:1.0",
  "additional_prompt": "Summarize the records shown above.",
  "additional_prompt_position": "after_data_sheet"
}
```

The action preserves the input DataSheet in the AI prompt context. All input columns and rows are sent to the configured agent. Use an `input_mapper` to include only the data required for the task and to exclude sensitive fields.

## Run Test

The `axenox.GenAI.RunTest` action runs one or more selected `axenox.GenAI.AI_TEST_CASE` records as a deferred action. It calls the agent configured by each test case, stores an `AI_TEST_RUN`, evaluates its criteria, and stores the resulting ratings. The Testing page uses the stored object action `axenox.GenAI.AiTestCaseRunTest`.

### UXON properties

| Property | Required | Description |
| --- | --- | --- |
| `finish_message` | No | Completion message. Defaults to `Testcase erfolgreich ausgeführt`. |
| `repetitions` | No | Number of test runs. Defaults to `1`; repeated runs wait 60 seconds between repetitions. |

```json
{
  "alias": "axenox.GenAI.RunTest",
  "finish_message": "Test run completed",
  "repetitions": 1
}
```

The action requires at least one input row. Test prompts and configured context are sent to the agent selected by each test case. Failures are logged and persisted with the test result so they can be reviewed on the Testing page.