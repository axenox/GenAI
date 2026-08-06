# Agent concept reference

[Deutsch](index_german.md)

Concepts provide context that an agent should receive automatically with its instructions. They resolve named placeholders while the prompt is being built, before the request is sent to the LLM. This makes them suitable for information that is required in every relevant conversation and should not depend on the model deciding to call a tool.

Use concepts for stable background knowledge, mandatory rules, a small current schema, or context derived from the input row. Do not use them to preload large or rarely needed information: concept output increases prompt construction time and is included in the model context even when the current question does not need it. In those cases, expose a tool and let the model retrieve details on demand.

This page documents all concept prototypes currently provided by `axenox.GenAI` and explains when and how to use them.

## Configuring a concept

A concept is configured below `concepts` in `CONFIG_UXON`. The object key is the placeholder name and should describe the content, for example `platform_docs` or `database_schema`. Insert that key into `INSTRUCTIONS` as `[#placeholder_name#]` at the exact position where the rendered content should appear.

```json
{
  "concepts": {
    "platform_docs": {
      "alias": "axenox.GenAI.AppDocsConcept",
      "app_alias": "exface.Core",
      "starting_page": "index.md",
      "depth": 0
    }
  }
}
```

```md
## Platform documentation

[#platform_docs#]
```

Concept output is generated when the prompt is rendered and cached for that concept instance. Place the placeholder below a descriptive heading so the model understands why the generated content is present. Some concepts can also suggest tools that the agent should expose; `AppDocsConcept`, for example, adds a documentation retrieval tool for linked details.

## `AppDocsConcept`

**Alias:** `axenox.GenAI.AppDocsConcept` | [UXON prototype](api/docs/exface/Core/Docs/UXON/UXON_prototypes.md?selector=%5Caxenox%5CGenAI%5CAI%5CConcepts%5CAppDocsConcept)

**Purpose.** Renders one entry page from an installed app's Markdown documentation into the agent instructions and can optionally include linked pages.

**Use when.** Every conversation needs a compact introduction to an app, its terminology, or its documentation structure. A shallow page containing links is especially useful because it gives the model orientation while `GetDocsTool` can retrieve details later.

**Do not use when.** Do not render a complete documentation tree into every prompt. Large depth values increase I/O, latency, and context size even for unrelated questions.

| UXON property | Default | Description |
| --- | --- | --- |
| `app_alias` | Required | App whose documentation is loaded. |
| `starting_page` | `index.md` | First documentation page to render. |
| `depth` | `0` | Number of linked documentation levels to include; `0` renders only the starting page. |
| `hide_title` | `false` | Removes the first top-level page title. |
| `heading_level` | Unchanged | Normalizes the first heading to the selected level and shifts nested headings accordingly. |

**How to use.** Set `app_alias` and choose a focused `starting_page`. Start with `depth: 0`; increase it only when linked pages are small and required for nearly every request. Use `hide_title` and `heading_level` to fit the rendered page below the surrounding instruction heading.

**Result and limits.** The documentation Markdown printer produces the inserted content. The concept also suggests `GetDocsTool`, allowing the model to follow relevant links on demand. Resolution fails when the app or starting page has no documentation.

## `MarkdownFilesConcept`

**Alias:** `axenox.GenAI.MarkdownFilesConcept` | [UXON prototype](api/docs/exface/Core/Docs/UXON/UXON_prototypes.md?selector=%5Caxenox%5CGenAI%5CAI%5CConcepts%5CMarkdownFilesConcept)

**Purpose.** Loads one or more Markdown files and inserts their combined content into the instructions in a defined order.

**Use when.** Agent rules or domain knowledge are maintained as reusable Markdown files, such as repository instructions, coding conventions, or a short business glossary. This keeps shared material outside individual agent records.

**Do not use when.** Do not include volatile data or large reference manuals that are only occasionally needed. Use a suitable retrieval tool for those details.

| UXON property | Default | Description |
| --- | --- | --- |
| `file_paths` | Required | Ordered list of relative or absolute Markdown file paths. |
| `base_path` | Vendor directory | Base directory used for relative paths. |
| `heading_level` | Unchanged | Normalizes headings to begin at the selected level. |
| `strip_front_matter` | `false` | Removes YAML or TOML front matter before rendering. |

**How to use.** List the required files in `file_paths` in the order they should appear. Relative paths are resolved below `base_path`, which defaults to the vendor directory. Use `strip_front_matter` when metadata should not reach the model and `heading_level` to integrate file headings into the surrounding instructions.

**Result and limits.** The result is the concatenated Markdown content. Missing configuration or unreadable files prevent useful resolution. Keep the list small because every included file contributes to each prompt.

## `MetamodelDbmlConcept`

**Alias:** `axenox.GenAI.MetamodelDbmlConcept` | [UXON prototype](api/docs/exface/Core/Docs/UXON/UXON_prototypes.md?selector=%5Caxenox%5CGenAI%5CAI%5CConcepts%5CMetamodelDbmlConcept)

**Purpose.** Generates a compact DBML schema from selected ExFace metaobjects, including attributes, enum values, and relationships where available.

**Use when.** A data-oriented agent must understand the same bounded object model in nearly every request, for example when producing queries or explaining relations for one app.

**Do not use when.** Do not inject the complete metamodel. If objects vary by question, let the agent discover them with `ModelObjectInfoTool` instead.

| UXON property | Description |
| --- | --- |
| `object_filters` | ExFace condition group used to select the objects included in the schema. |

```json
{
  "alias": "axenox.GenAI.MetamodelDbmlConcept",
  "object_filters": {
    "operator": "AND",
    "conditions": [
      { "expression": "APP__ALIAS", "comparator": "==", "value": "my.App" }
    ]
  }
}
```

**How to use.** Configure a non-empty `object_filters` condition group that selects only the relevant app, namespace, connection, or object set. Filters may contain prompt input placeholders such as `[#~input:UID#]` when the schema depends on the first input row.

**Result and limits.** The result is DBML suitable for model context, not executable database DDL. Rendering broad selections increases database work and prompt size. Objects backed by custom SQL statements may not be representable as normal DBML tables.

## `SqlDbmlConcept`

**Alias:** `axenox.GenAI.SqlDbmlConcept` | [UXON prototype](api/docs/exface/Core/Docs/UXON/UXON_prototypes.md?selector=%5Caxenox%5CGenAI%5CAI%5CConcepts%5CSqlDbmlConcept)

**Purpose.** Generates DBML only for table-like metaobjects backed by SQL data connectors and identifies the database engine in the output.

**Use when.** An SQL assistant needs the physical table-oriented schema for one selected connection or app in every conversation. It is more precise than `MetamodelDbmlConcept` when the task is specifically about SQL.

**Do not use when.** Do not use it for non-SQL connectors, custom SQL objects, or general conceptual object documentation.

**How to use.** Supply the same narrowly scoped `object_filters` condition group as for `MetamodelDbmlConcept`. Filtering by a connection from `[#~input:FIELD#]` is useful when an AI chat is opened for a selected data connection.

**Result and limits.** The concept emits DBML for supported SQL objects and engines such as MySQL, MariaDB, Oracle, and Microsoft SQL Server. Resolution fails when no suitable SQL objects match the configuration.

## `ToolCallConcept`

**Alias:** `axenox.GenAI.ToolCallConcept` | [UXON prototype](api/docs/exface/Core/Docs/UXON/UXON_prototypes.md?selector=%5Caxenox%5CGenAI%5CAI%5CConcepts%5CToolCallConcept)

**Purpose.** Invokes a tool during prompt construction and inserts the tool result directly into the instructions.

**Use when.** Dynamic information must already be present before the model can decide what to do, for example the selected log entry, a schema determined by the input row, or a small current directory overview.

**Do not use when.** Do not use it for expensive, broad, or optional calls. A normal agent tool is preferable when the information is only needed for some questions. Never use silent empty output as the only enforcement mechanism for a critical rule.

| UXON property | Description |
| --- | --- |
| `tool_name` | Name of a tool already configured on the agent. |
| `tool_definition` | Inline UXON definition for a temporary tool. Its function name defaults to the concept placeholder. |
| `arguments` | Ordered values passed to the selected tool. |

**How to use.** Set `tool_name` to call a tool already exposed by the agent, or provide an inline `tool_definition` for a tool that the model itself should not call. Pass positional values through `arguments`. Input placeholders such as `[#~input:FIELD#]` can derive configuration or arguments from the first prompt input row. Omitting both tool selectors is a configuration error.

**Result and limits.** Tool front matter is converted to Markdown before insertion. Exceptions are added to prompt warnings when supported. Other failures are logged and produce empty concept output. Because the call runs before every model response, keep it inexpensive, narrowly filtered, and bounded.

## `ToolIntroductionConcept`

**Alias:** `axenox.GenAI.ToolIntroductionConcept` | [UXON prototype](api/docs/exface/Core/Docs/UXON/UXON_prototypes.md?selector=%5Caxenox%5CGenAI%5CAI%5CConcepts%5CToolIntroductionConcept)

**Purpose.** Generates a Markdown overview of the tools currently exposed by the agent, including descriptions, rules, and prototype information.

**Use when.** An agent has several tools whose built-in rules or prototype descriptions should be visible in the system instructions without manually duplicating them. This is useful while tool collections evolve between agent versions.

**Do not use when.** Do not treat an automatically generated catalog as a replacement for workflow instructions. The agent still needs concise rules describing which tool to use first and when a call is mandatory.

| UXON property | Default | Description |
| --- | --- | --- |
| `heading_level` | `2` | Heading level used for each tool section. |
| `show_description` | `true` | Includes the tool's LLM-facing description. |
| `show_rules` | `true` | Includes mandatory rules returned by the tool. |
| `show_prototype_description` | `true` | Includes prototype documentation, or uses it as a fallback when no description exists. |

**How to use.** Insert the concept placeholder where the tool overview should appear. Select the desired detail with `show_description`, `show_rules`, and `show_prototype_description`, and set `heading_level` to fit the surrounding document structure.

**Result and limits.** The result contains one Markdown section per tool; tools without selected content are skipped. Enabling every detail for many tools can create a large system prompt, so include only information the model needs to choose and operate the tools correctly.

## `MockConcept`

**Alias:** `axenox.GenAI.MockConcept` | [UXON prototype](api/docs/exface/Core/Docs/UXON/UXON_prototypes.md?selector=%5Caxenox%5CGenAI%5CAI%5CConcepts%5CMockConcept)

**Purpose.** Replaces a concept placeholder with a fixed value.

**Use when.** Tests must isolate prompt behavior from changing files, data, schemas, or tool output. It is also useful for testing how an agent responds to a known context fragment.

**Do not use when.** Do not use fixed mock content as production context or present it as current system state.

| UXON property | Description |
| --- | --- |
| `value` | Required output that replaces the placeholder. |

**How to use.** Configure the exact `value` that should replace the placeholder and keep the same placeholder name used by the real concept under test.

**Result and limits.** The configured string is returned unchanged, making tests deterministic. It does not validate or simulate the behavior of the real data source.

## `UiWidgetInfoConcept`

**Alias:** `axenox.GenAI.UiWidgetInfoConcept` | [UXON prototype](api/docs/exface/Core/Docs/UXON/UXON_prototypes.md?selector=%5Caxenox%5CGenAI%5CAI%5CConcepts%5CUiWidgetInfoConcept)

**Status.** This prototype is present in the package but is not implemented yet. Its output method returns no widget documentation and it exposes no configurable UXON properties.

**Current recommendation.** Do not use it in an active agent configuration. Use `UiWidgetInfoTool` when the model should inspect a page or widget on demand. If widget context must be injected automatically, call that tool through `ToolCallConcept` with a bounded URL and optional widget ID.

## Choosing a concept

| Requirement | Concept |
| --- | --- |
| Include a small section of app documentation | `AppDocsConcept` |
| Include maintained Markdown instruction files | `MarkdownFilesConcept` |
| Describe selected metaobjects as a schema | `MetamodelDbmlConcept` |
| Describe SQL-backed table objects only | `SqlDbmlConcept` |
| Insert current tool output during prompt construction | `ToolCallConcept` |
| Introduce all tools exposed by the agent | `ToolIntroductionConcept` |
| Supply deterministic test context | `MockConcept` |

Choose a concept only when its output is required before the model can answer. Prefer tools for details that are large, volatile, sensitive, expensive to retrieve, or only occasionally needed. A common pattern is to use a concept for a small linked overview and a tool for the linked details.