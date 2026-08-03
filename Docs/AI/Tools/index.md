# Agent tool reference

[Deutsch](index_german.md)

Tools let an agent retrieve information or perform a bounded operation while it is processing a request. Unlike concepts, tools are not evaluated automatically when the prompt is built. The model decides whether to call them based on the current question, the tool description, and the instructions of the agent.

Use a tool for information that is too detailed, too volatile, or too expensive to include in every prompt. A good tool has one clear responsibility, narrowly scoped permissions, and a description that tells the model which uncertainty the call can resolve. This page documents all tool prototypes currently provided by `axenox.GenAI` and explains when and how to use them.

## Choosing a tool

| Requirement | Recommended tool |
| --- | --- |
| Inspect a known file | `FileReadTool` |
| Find files or text when the location is not known | `FileSearchTool` |
| Understand a directory structure | `FolderReadTool` |
| Change a small part of an existing file | `FilePatchTool` |
| Create or completely replace a file | `FileWriteTool` |
| Run a tightly controlled local command | `CommandLineTool` |
| Read or save ExFace object data | `DataSheetReadTool` or `DataSheetImportTool` |
| Read ExFace documentation | `GetDocsTool` |
| Inspect model or UXON metadata | One of the `Model*InfoTool` tools |
| Inspect a concrete page or widget instance | `UiWidgetInfoTool` |
| Provide deterministic test output | `MockTool` |

## Common configuration

Every tool is configured below `tools` in `CONFIG_UXON`. The object key becomes the function name visible to the model. Choose a short, action-oriented name that describes the outcome, for example `ReadObjectData` or `FindSourceFile`.

A definition selects its prototype with `alias` or `class` and can override the generated `name`, `description`, and `arguments`. Prefer an alias because it remains independent of the PHP namespace. The description should explain when the model should call the tool, not merely repeat its name.

```json
{
  "tools": {
    "GetObject": {
      "alias": "axenox.GenAI.ModelObjectInfoTool",
      "description": "Find and describe a metaobject.",
      "arguments": [
        {
          "name": "search_term",
          "data_type": { "alias": "exface.Core.String" },
          "description": "Object UID, alias, or name"
        }
      ]
    }
  }
}
```

The built-in argument templates are used when `arguments` is omitted. Override them only when the agent needs more specific terminology, examples, or a restricted schema. Tool instructions should also state when a call is mandatory, for example: "Read the object definition before proposing UXON that references its attributes."

## File access configuration

`CommandLineTool`, `FileReadTool`, `FileWriteTool`, `FilePatchTool`, `FolderReadTool`, and `FileSearchTool` share these properties:

| Property | Default | Description |
| --- | --- | --- |
| `base_path` | Vendor directory | Base directory for relative paths. |
| `use_vendor_folder_as_base` | `true` | Uses the ExFace vendor directory as the default base; `false` uses the workbench base directory. Relative `base_path` values are resolved below the selected default. |
| `allowed_paths` | Unrestricted within the base path | Glob-like allowlist for accessible paths. Use the narrowest paths the agent requires. |

Paths are validated against the configured base and allowlist before access. This prevents a relative path from escaping the permitted area. Always configure `allowed_paths` for production agents. Grant access to the smallest directory tree that supports the task; narrow access improves security and also reduces unnecessary disk I/O and prompt content.

## `CommandLineTool`

**Alias:** `axenox.GenAI.CommandLineTool` | [UXON prototype](api/docs/exface/Core/Docs/UXON/UXON_prototypes.md?selector=%5Caxenox%5CGenAI%5CAI%5CTools%5CCommandLineTool)

**Purpose.** Executes a command in a validated working directory and returns the console output as Markdown.

**Use when.** The agent must run a diagnostic, validator, test, or build command and no purpose-built tool provides the same operation. Typical examples are syntax checks or a narrowly selected test command.

**Do not use when.** Do not expose a general-purpose shell for routine business operations, unrestricted filesystem exploration, or destructive administration. Prefer a dedicated tool whenever the operation has a stable input and output contract.

| UXON property | Default | Description |
| --- | --- | --- |
| `allowed_commands` | `[]` | Exact commands or regular-expression patterns that may run. |
| `blocked_commands` | `[]` | Commands or patterns that must not run. A block takes precedence over an allow rule. |
| `command_timeout` | `60` | Maximum execution time in seconds. |

| Argument | Required | Description |
| --- | --- | --- |
| `command` | Yes | Command line to execute. |
| `folder` | No | Working directory relative to the configured base path. |

**How to use.** Configure an explicit `allowed_commands` list, a defensive `blocked_commands` list, and narrow file access settings. The model supplies the complete command and, optionally, a working folder. Block rules take precedence over allow rules; an empty allowlist otherwise permits every command not explicitly blocked.

**Result and limits.** The tool returns captured console output in a Markdown code block. Invalid commands, denied folders, failures, and timeouts produce a tool error. Keep the timeout finite and never rely on the model to decide whether an unrestricted command is safe.

## `FileReadTool`

**Alias:** `axenox.GenAI.FileReadTool` | [UXON prototype](api/docs/exface/Core/Docs/UXON/UXON_prototypes.md?selector=%5Caxenox%5CGenAI%5CAI%5CTools%5CFileReadTool)

**Purpose.** Reads a known text file and returns its content with file metadata and language-aware Markdown formatting.

**Use when.** The agent already knows which source file, configuration, or document contains the required detail. It is the preferred tool for verifying exact implementation or configuration before making a claim or change.

**Do not use when.** If the path is unknown, use `FileSearchTool` or `FolderReadTool` first. Do not read an entire large file when a relevant line range is sufficient.

| UXON property | Default | Description |
| --- | --- | --- |
| `include_instructions_for_github_copilot` | `true` | Appends applicable `.github/instructions/*.instructions.md` content for the requested file. |

| Argument | Required | Description |
| --- | --- | --- |
| `path` | Yes | File path relative to the configured base path. |
| `start_with_line` | No | First line to return, using one-based line numbers. |
| `max_lines` | No | Maximum number of lines to return. |

**How to use.** Restrict accessible paths in the tool configuration. The model supplies a relative `path` and can paginate with `start_with_line` and `max_lines`. Leave `include_instructions_for_github_copilot` enabled when applicable repository instructions should accompany source files.

**Result and limits.** The tool returns the selected content as Markdown. Missing, unreadable, or denied files produce an error. Pagination keeps large files from consuming excessive context.

## `FileWriteTool`

**Alias:** `axenox.GenAI.FileWriteTool` | [UXON prototype](api/docs/exface/Core/Docs/UXON/UXON_prototypes.md?selector=%5Caxenox%5CGenAI%5CAI%5CTools%5CFileWriteTool)

**Purpose.** Creates a new file or completely replaces an existing file inside the permitted path area.

**Use when.** The complete target content is known, such as for a new generated artifact or an intentionally replaced small file.

**Do not use when.** Do not use it for a small edit to an existing file because unchanged content can be lost. Use `FilePatchTool` for targeted modifications.

| Argument | Required | Description |
| --- | --- | --- |
| `path` | Yes | Target path relative to the configured base path. |
| `content` | Yes | Complete content to write. |

**How to use.** Configure a narrow `allowed_paths` list. The model sends the relative path and the complete final content in one call.

**Result and limits.** The tool returns a plain status string. Existing content is overwritten rather than merged, and write or path validation failures produce an error.

## `FilePatchTool`

**Alias:** `axenox.GenAI.FilePatchTool` | [UXON prototype](api/docs/exface/Core/Docs/UXON/UXON_prototypes.md?selector=%5Caxenox%5CGenAI%5CAI%5CTools%5CFilePatchTool)

**Purpose.** Applies one or more exact `SEARCH`/`REPLACE` blocks to a file without rewriting unrelated content.

**Use when.** The agent needs to make a small, reviewable change to an existing source, configuration, or documentation file. It is safer and more efficient than sending the complete file through `FileWriteTool`.

**Do not use when.** Do not use it when the original text is unknown or ambiguous. Read the relevant file first so the search block can match exactly.

| Argument | Required | Description |
| --- | --- | --- |
| `path` | Yes | Target path relative to the configured base path. |
| `patch` | Yes | Patch containing exact search and replacement blocks. |

```text
<<<<<<< SEARCH
exact text, including whitespace
=======
replacement text
>>>>>>> REPLACE
```

**How to use.** The model supplies a relative path and one or more patch blocks. Search text is case-sensitive and whitespace-sensitive, so each block should be copied from the current file and be small enough to review but unique enough to identify one location. An empty search section can create a file or append content.

**Result and limits.** Blocks are applied in order and only the first occurrence of each search text is replaced. Malformed blocks and unmatched search text produce an error instead of guessing a location.

## `FolderReadTool`

**Alias:** `axenox.GenAI.FolderReadTool` | [UXON prototype](api/docs/exface/Core/Docs/UXON/UXON_prototypes.md?selector=%5Caxenox%5CGenAI%5CAI%5CTools%5CFolderReadTool)

**Purpose.** Lists a directory as a nested Markdown tree.

**Use when.** The agent needs a quick structural overview before choosing files to read, for example when entering an unfamiliar app or locating a likely implementation area.

**Do not use when.** Do not recursively list a large package merely to find a filename or text occurrence. `FileSearchTool` is more efficient for a concrete search.

| UXON property | Default | Description |
| --- | --- | --- |
| `depth` | `0` | Maximum recursion depth; `0` means unlimited. |
| `exclude_dot_paths` | `true` | Omits files and directories whose names begin with a dot. |

| Argument | Required | Description |
| --- | --- | --- |
| `path` | Yes | Directory relative to the configured base path. |

**How to use.** Configure a narrow base path and set a finite `depth` for large trees. The model supplies the relative directory path.

**Result and limits.** The result is a nested Markdown list. Unlimited depth can produce large responses and unnecessary disk access; dot paths are omitted by default.

## `FileSearchTool`

**Alias:** `axenox.GenAI.FileSearchTool` | [UXON prototype](api/docs/exface/Core/Docs/UXON/UXON_prototypes.md?selector=%5Caxenox%5CGenAI%5CAI%5CTools%5CFileSearchTool)

**Purpose.** Finds files by directory pattern and filename, with an optional text or regular-expression search inside matching files.

**Use when.** The agent knows what it is looking for but not the exact file. It is suitable for locating a class, configuration key, method call, or documentation phrase before reading the relevant files.

**Do not use when.** If the exact file is already known, call `FileReadTool` directly. Avoid using broad recursive searches as a substitute for a clear search term.

| UXON property | Default | Description |
| --- | --- | --- |
| `include_extract_line` | `true` | Includes matching line extracts when a content query is supplied. |

| Argument | Required | Description |
| --- | --- | --- |
| `path` | Yes | Folder or folder pattern to search. |
| `name` | No | Filename glob; defaults to all files. |
| `query` | No | Text or regular expression to find in file contents. |

**How to use.** The model supplies a folder pattern, an optional filename glob, and an optional content query. A single `*` matches one path segment and `**` can span multiple levels. Use `include_extract_line` to control whether matching lines are included.

**Result and limits.** The result lists matching paths and, when requested, matching line extracts. Avoid an unbounded `**` search from the vendor root. Narrow paths reduce execution time, disk access, and response size.

## `DataSheetReadTool`

**Alias:** `axenox.GenAI.DataSheetReadTool` | [UXON prototype](api/docs/exface/Core/Docs/UXON/UXON_prototypes.md?selector=%5Caxenox%5CGenAI%5CAI%5CTools%5CDataSheetReadTool)

**Purpose.** Reads ExFace object data using a DataSheet UXON query.

**Use when.** The agent needs current business or model data and already knows the target object and relevant attributes. The query can select columns, filter rows, sort or aggregate results, and paginate large result sets.

**Do not use when.** Do not guess object or attribute aliases. Discover and verify them with `ModelObjectInfoTool` first. Do not request complete objects or unbounded row sets when only a few fields or records are needed.

| Argument | Required | Description |
| --- | --- | --- |
| `data_sheet` | Yes | DataSheet UXON object describing the query. |

```json
{
  "object_alias": "exface.Core.OBJECT",
  "columns": [
    { "name": "ALIAS", "attribute_alias": "ALIAS" },
    { "name": "NAME", "attribute_alias": "NAME" }
  ],
  "filters": {
    "operator": "AND",
    "conditions": [
      { "expression": "ALIAS", "comparator": "[", "value": "exface.Core" }
    ]
  },
  "rows_limit": 25,
  "rows_offset": 0
}
```

**How to use.** The model passes one DataSheet UXON object containing `object_alias`, selected `columns`, and optional `filters`, `sorters`, `aggregators`, `rows_limit`, and `rows_offset`. Agent instructions should require a reasonable row limit for objects that may contain many records.

**Result and limits.** The result contains JSON-formatted rows and metadata in Markdown. Normal ExFace object permissions and DataSheet read restrictions apply. Invalid object aliases, expressions, or filters produce errors.

## `DataSheetImportTool`

**Alias:** `axenox.GenAI.DataSheetImportTool` | [UXON prototype](api/docs/exface/Core/Docs/UXON/UXON_prototypes.md?selector=%5Caxenox%5CGenAI%5CAI%5CTools%5CDataSheetImportTool)

**Purpose.** Validates and saves one or more DataSheet payloads to explicitly configured ExFace objects.

**Use when.** The agent must create or update structured business data and the permitted targets and fields can be defined in advance. It is appropriate for bounded workflows such as recording a reviewed result or creating one known type of record.

**Do not use when.** Do not expose unrestricted write access or allow the model to choose arbitrary objects and attributes. Use a read tool when no persistent change is required.

| UXON property | Description |
| --- | --- |
| `save_as` | Defines one permitted target DataSheet schema. |
| `data_schemas` | Defines multiple permitted target schemas. Each schema can specify an object, columns, and subsheets. |

| Argument | Required | Description |
| --- | --- | --- |
| `data_sheet` | Yes | One DataSheet UXON object or an array of DataSheet objects. |

**How to use.** Configure either `save_as` for one target schema or `data_schemas` for several permitted schemas. Restrict each schema to the exact objects, columns, and subsheets the agent may modify. The model then supplies a matching DataSheet object or array of objects.

**Result and limits.** The tool uses the normal DataSheet save operation and returns imported row counts. ExFace authorization and validation remain active. Invalid rows are reported as exceptions where processing can continue; critical failures stop the import.

## `GetTimeTool`

**Alias:** `axenox.GenAI.GetTimeTool` | [UXON prototype](api/docs/exface/Core/Docs/UXON/UXON_prototypes.md?selector=%5Caxenox%5CGenAI%5CAI%5CTools%5CGetTimeTool)

**Purpose.** Returns the current server date and time as an ExFace date-time value.

**Use when.** The answer depends on "now", a relative date, a deadline, or scheduling logic. Calling the tool avoids relying on stale model context or a client clock in another timezone.

**How to use.** Expose the tool without prototype-specific configuration or arguments. The result is the current server value; agent instructions should clarify the relevant timezone when users may interpret the value differently.

## `GetDocsTool`

**Alias:** `axenox.GenAI.GetDocsTool` | [UXON prototype](api/docs/exface/Core/Docs/UXON/UXON_prototypes.md?selector=%5Caxenox%5CGenAI%5CAI%5CTools%5CGetDocsTool)

**Purpose.** Loads an ExFace documentation page as Markdown through the documentation facade.

**Use when.** The agent has a documentation link or URI and needs the detailed page before answering. It pairs well with `AppDocsConcept`: the concept supplies a small overview, while this tool follows only the links relevant to the current question.

**Do not use when.** Do not preload an entire documentation tree through repeated calls. Read the smallest page that can answer the question.

| Argument | Required | Description |
| --- | --- | --- |
| `uri` | Yes | Documentation URI, normally below `api/docs`. |

**How to use.** The model passes a local documentation URI beginning with `api/docs`. `AppDocsConcept` automatically suggests this tool to the agent, so it does not need to be configured a second time unless its name or description must be customized.

**Result and limits.** The result is Markdown. PHP targets are rendered with the code Markdown printer; other targets are resolved by the documentation facade. Absolute HTTPS URLs mentioned by the built-in argument template are not currently accepted by the security check.

## `GetLogEntryTool`

**Alias:** `axenox.GenAI.GetLogEntryTool` | [UXON prototype](api/docs/exface/Core/Docs/UXON/UXON_prototypes.md?selector=%5Caxenox%5CGenAI%5CAI%5CTools%5CGetLogEntryTool)

**Purpose.** Loads one ExFace log entry and formats its details as Markdown.

**Use when.** A support or diagnostic agent must explain a known log ID, inspect the associated exception, or use concrete runtime evidence to identify a failure.

**Do not use when.** Do not expose it to agents without an operational support use case. Log data can contain internal paths, user data, request values, or other sensitive details.

| Argument | Required | Description |
| --- | --- | --- |
| `LogId` | Yes | Log entry identifier shown by the application. |
| `LogFilePath` | No | Log file path relative to the installation. |

**How to use.** The model supplies the visible `LogId` and, only when necessary, a log file path relative to the installation. Agent instructions should tell the model to read the entry before diagnosing it and to avoid reproducing unrelated sensitive values.

**Result and limits.** `LogEntryMarkdownPrinter` produces the Markdown result. Access should be limited to agents and users with an appropriate support role.

## `GetPrintPreviewTool`

**Alias:** `axenox.GenAI.GetPrintPreviewTool` | [UXON prototype](api/docs/exface/Core/Docs/UXON/UXON_prototypes.md?selector=%5Caxenox%5CGenAI%5CAI%5CTools%5CGetPrintPreviewTool)

**Purpose.** Executes a configured print action and returns the rendered document preview as HTML.

**Use when.** The agent needs to inspect the actual content of an invoice, report, label, or other document before summarizing, checking, or discussing it.

**Do not use when.** Do not use it merely to read object attributes that are available through `DataSheetReadTool`. Rendering is more expensive and can expose the complete document content.

| UXON property | Default | Description |
| --- | --- | --- |
| `print_action` | Required | Alias of the print action to execute. |
| `print_data` | Optional | DataSheet UXON used as input for the action. |
| `cache_previews` | `false` | Reuses previews while the relevant input rows remain unchanged. |

**How to use.** Configure a print-capable `print_action` and a narrowly filtered `print_data` template. Use `[#~argument:0#]`, `[#~argument:1#]`, and subsequent indices to insert tool arguments. When invoked by `ToolCallConcept`, `[#~input:FIELD#]` can read a field from the first input row. Enable caching only when reusing previews is appropriate for the underlying data.

**Result and limits.** The result is the HTML body of the preview. The configured action must support preview rendering, and normal action permissions remain in force.

## `ModelObjectInfoTool`

**Alias:** `axenox.GenAI.ModelObjectInfoTool` | [UXON prototype](api/docs/exface/Core/Docs/UXON/UXON_prototypes.md?selector=%5Caxenox%5CGenAI%5CAI%5CTools%5CModelObjectInfoTool)

**Purpose.** Finds ExFace metaobjects and returns their model documentation.

**Use when.** The agent needs to discover an object alias, verify available attributes and relations, or understand an object before constructing DataSheet queries or UXON.

**Do not use when.** If the exact non-object component type and selector are already known, `ModelComponentInfoTool` is more direct.

| Argument | Required | Description |
| --- | --- | --- |
| `search_term` | Yes | Object UID, fully qualified alias, partial alias, or name. |

**How to use.** The model supplies a UID, fully qualified alias, partial alias, or human-readable name. Values beginning with `0x` are treated as UIDs, likely full aliases are matched exactly, and other values search object names and aliases.

**Result and limits.** Exact matches are returned first, followed by generated Markdown for every match. Broad terms may return several objects, so use the most specific known selector.

## `ModelComponentInfoTool`

**Alias:** `axenox.GenAI.ModelComponentInfoTool` | [UXON prototype](api/docs/exface/Core/Docs/UXON/UXON_prototypes.md?selector=%5Caxenox%5CGenAI%5CAI%5CTools%5CModelComponentInfoTool)

**Purpose.** Returns registry documentation for a known ExFace model component such as an action, object, or page.

**Use when.** The component type and selector are already known and the agent needs authoritative metadata before referencing or configuring the component.

**Do not use when.** It is not a broad discovery search. Use `ModelObjectInfoTool` when an object still needs to be identified, or a specialized widget tool for widget details.

| Argument | Required | Description |
| --- | --- | --- |
| `component` | Yes | Component type, such as `action`, `object`, or `page`. |
| `selector` | Yes | Alias or selector of the component. |

**How to use.** The model passes the registry component type and its selector. Use canonical aliases where possible.

**Result and limits.** The result is the documentation returned by the component registry. Unknown component types or selectors cannot be resolved.

## `ModelUxonPrototypeTool`

**Alias:** `axenox.GenAI.ModelUxonPrototypeTool` | [UXON prototype](api/docs/exface/Core/Docs/UXON/UXON_prototypes.md?selector=%5Caxenox%5CGenAI%5CAI%5CTools%5CModelUxonPrototypeTool)

**Purpose.** Generates documentation for the configurable UXON properties of a PHP prototype.

**Use when.** The agent is about to create or edit UXON for an action, widget, behavior, connector, data type, tool, concept, or another prototype and must verify available properties, types, defaults, and descriptions.

**Do not use when.** This tool documents a prototype class, not a concrete page instance or metaobject. Use `UiWidgetInfoTool` or `ModelObjectInfoTool` for those cases.

| Argument | Required | Description |
| --- | --- | --- |
| `selector` | Yes | PHP class name or prototype file path. |

**How to use.** The model passes either a fully qualified PHP class beginning with `\` or a PHP file path relative to the vendor directory. Aliases are not currently supported as selectors.

**Result and limits.** `UxonPrototypeMarkdownPrinter` returns the prototype description and indexed UXON properties. The quality of the result depends on the prototype's annotations being available in the model.

## `ModelWidgetTypeInfoTool`

**Alias:** `axenox.GenAI.ModelWidgetTypeInfoTool` | [UXON prototype](api/docs/exface/Core/Docs/UXON/UXON_prototypes.md?selector=%5Caxenox%5CGenAI%5CAI%5CTools%5CModelWidgetTypeInfoTool)

**Purpose.** Documents one widget type, including its UXON properties, callable widget functions, and presets.

**Use when.** The agent is designing widget UXON and needs widget-specific information beyond a generic prototype property list.

**Do not use when.** Do not use it to inspect the current UXON of a concrete page or dialog. Use `UiWidgetInfoTool` for an instantiated widget reached through a URL.

| Argument | Required | Description |
| --- | --- | --- |
| `path` | Yes | Widget PHP file path or a supported core widget type. |

**How to use.** The model supplies the widget PHP file path or a supported core widget type. A file path is the most explicit selector.

**Result and limits.** The tool combines indexed UXON annotations with widget-function and preset metadata. Missing or incomplete model annotations lead to incomplete documentation.

## `UiWidgetInfoTool`

**Alias:** `axenox.GenAI.UiWidgetInfoTool` | [UXON prototype](api/docs/exface/Core/Docs/UXON/UXON_prototypes.md?selector=%5Caxenox%5CGenAI%5CAI%5CTools%5CUiWidgetInfoTool)

**Purpose.** Loads the UXON model and Markdown description of a concrete page, dialog, or nested widget through an ExFace facade.

**Use when.** The agent must understand the current UI structure before modifying a page, referring to visible controls, or diagnosing a widget configuration.

**Do not use when.** If only the generic properties of a widget type are needed, use `ModelWidgetTypeInfoTool` instead. Avoid loading an entire page when a known `widget_id` can target the relevant part.

| Argument | Required | Description |
| --- | --- | --- |
| `url` | Yes | Page alias, facade URL, or query string. |
| `widget_id` | No | ID of a nested widget; omit it to document the root widget. |

**How to use.** The model supplies a page alias, facade URL, or query string and can optionally select a nested `widget_id`. The URL must be resolvable by a facade that supports widget lookup.

**Result and limits.** The result describes the resolved widget and its UXON. Missing pages, unknown widget IDs, and unsupported routing are returned as warnings or errors.

## `MockTool`

**Alias:** `axenox.GenAI.MockTool` | [UXON prototype](api/docs/exface/Core/Docs/UXON/UXON_prototypes.md?selector=%5Caxenox%5CGenAI%5CAI%5CTools%5CMockTool)

**Purpose.** Returns predefined content without performing the real operation represented by a tool.

**Use when.** Agent tests and prompt development need deterministic output, or a workflow must be evaluated before the real integration exists.

**Do not use when.** Mock output is not a production data source and must not be presented as current system state.

| UXON property | Description |
| --- | --- |
| `request_response_pairs` | Ordered request matchers and their responses; the first match wins. |
| `sample_response` | Fallback response when no pair matches. |

**How to use.** Configure `sample_response` as a deterministic fallback and override the argument definition to resemble the tool under test. `request_response_pairs` is intended to select responses by request, but its current annotation and conversion implementation are incomplete.

**Result and limits.** The tool returns Markdown from the selected mock response. Until request-pair handling is corrected, rely on `sample_response` for predictable behavior.

## Failure behavior

Tools return a typed value together with zero or more exceptions. Runtime errors cover invalid arguments, denied paths, failed commands, I/O failures, and invalid model operations. Warnings can represent partial results or missing optional data. Prompt implementations may surface these exceptions to the LLM as warnings.

Tool access does not replace ExFace authorization. Data operations, actions, facades, logs, files, and commands must still be limited to the permissions and paths required by the agent's use case.