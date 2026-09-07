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
| Inspect Git changes and history | `GitTool` |
| Validate PHP syntax | `DevLintPHPTool` |
| Read or save ExFace object data | `DataSheetReadTool` or `DataSheetImportTool` |
| Retrieve the physical schema of an SQL connection | `SqlDbmlTool` |
| Find object data by a configured attribute | `ModelObjectSearchTool` |
| List, store, or retrieve agent memory for the current user | `NotesListTool`, `NotesWriteTool`, `NotesSearchTool`, or `NotesReadTool` |
| Read ExFace documentation | `GetDocsTool` |
| Inspect model or UXON metadata | One of the `Model*InfoTool` tools |
| Find context-aware UXON properties and values | `UxonAutosuggestTool` |
| Validate generated UXON | `UxonValidateTool` |
| Understand the menu and screens of an app | `UiOverviewTool` |
| Inspect a concrete page or widget instance | `UiWidgetInfoTool` |
| Provide deterministic test output | `MockTool` |
| Search where model entities are referenced | `ModelSearchTool` |

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

`CommandLineTool`, `GitTool`, `DevLintPHPTool`, `FileReadTool`, `FileWriteTool`, `FilePatchTool`, `FolderReadTool`, and `FileSearchTool` share these properties:

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

## `GitTool`

**Alias:** `axenox.GenAI.GitTool` | [UXON prototype](api/docs/exface/Core/Docs/UXON/UXON_prototypes.md?selector=%5Caxenox%5CGenAI%5CAI%5CTools%5CGitTool)

**Purpose.** Runs predefined Git operations in a validated repository folder. Its safe defaults let an agent inspect current changes and search commit history without enabling file-changing operations.

**Use when.** The agent needs to validate a working tree, inspect diffs, find previous work, or view an earlier version of a file. Prefer this tool over `CommandLineTool` for Git because designers configure operation names instead of command regexes.

**Do not use when.** Do not enable mutating operations unless the agent explicitly needs them and its workflow includes suitable review safeguards. The default configuration does not allow staging, commits, branch changes, network synchronization, or other repository modifications.

| UXON property | Default | Description |
| --- | --- | --- |
| `allowed_commands` | `["status", "diff", "log", "show", "blame", "grep"]` | Predefined Git operation names. Supported read operations also include `rev-list`, `rev-parse`, `ls-files`, `ls-tree`, `shortlog`, and `describe`. Mutating operations such as `stage`, `commit`, `switch`, `pull`, and `push` require explicit opt-in. An empty list denies every command. |
| `command_timeout` | `60` | Maximum execution time in seconds. |

| Argument | Required | Description |
| --- | --- | --- |
| `command` | Yes | Complete Git command beginning with `git` and an enabled operation. |
| `folder` | No | Repository folder relative to the configured base path. |

**How to use.** Usually keep the default operation list and restrict `allowed_paths` to the repositories the agent may inspect. To grant another operation, add its predefined name to `allowed_commands`; `stage` maps to `git add`. Unknown names are rejected as configuration errors. The generated validation patterns reject shell operators and options that write command output or invoke external diff and pager helpers.

**Result and limits.** The tool returns Git output in a Markdown code block. It does not parse Git output into structured data. Explicitly enabled mutating commands retain their normal Git behavior and should only be exposed to agents designed to make repository changes.

## `DevLintPHPTool`

**Alias:** `axenox.GenAI.DevLintPHPTool` | [UXON prototype](api/docs/exface/Core/Docs/UXON/UXON_prototypes.md?selector=%5Caxenox%5CGenAI%5CAI%5CTools%5CDevLintPHPTool)

**Purpose.** Validates one PHP file with the current PHP runtime's built-in lint mode without executing the file.

**Use when.** An autonomous development agent has created or changed a PHP file. Run it before considering the change complete to catch parse errors cheaply and locally.

**Do not use when.** PHP lint only checks syntax. It does not verify types, dependencies, coding standards, tests, or runtime behavior, and it does not accept JavaScript or other file types.

| Argument | Required | Description |
| --- | --- | --- |
| `path` | Yes | Path to a `.php` file relative to the configured base path. |

**How to use.** Restrict `allowed_paths` to the source trees the agent may validate. The tool fixes the executable and lint option internally; the model can only provide a validated relative file path and cannot add PHP or shell options.

**Result and limits.** The tool returns PHP's lint output in a Markdown code block. A syntax error is a normal diagnostic result so the agent can repair it. Missing, unreadable, denied, and non-PHP files produce a tool error, as does failure to start the PHP process.

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
exact text, including whitespace
replacement text
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

## `SqlDbmlTool`

**Alias:** `axenox.GenAI.SqlDbmlTool` | [UXON prototype](api/docs/exface/Core/Docs/UXON/UXON_prototypes.md?selector=%5Caxenox%5CGenAI%5CAI%5CTools%5CSqlDbmlTool)

**Purpose.** Generates a physical DBML schema from the table-like ExFace metaobjects assigned to an SQL data connection.

**Use when.** An agent needs table names, column names, data types, enum values, and relationships for a connection only on demand. This avoids adding a potentially large schema to every prompt through `SqlDbmlConcept`.

**Do not use when.** Do not use it for non-SQL connections, objects backed by custom SQL statements, or executable DDL. Use `MetamodelDbmlConcept` when conceptual metaobject names are required in every prompt.

| Argument | Required | Description |
| --- | --- | --- |
| `connection` | Yes | UID or namespaced alias of the SQL data connection. |
| `data_address_search` | No | Case-insensitive text to search for in metaobject data addresses. |

**How to use.** Pass the configured connection UID or alias. Omit `data_address_search` to retrieve all table-like objects on the connection. To limit a large schema, pass literal text contained in the physical table addresses: for example, `dbo.` selects objects in the `dbo` schema and `order_` selects objects whose addresses contain that table-name fragment.

**Result and limits.** The result is DBML prefixed with the detected SQL engine. Relationships are emitted only when both objects are present in the result. Missing connections, non-SQL connections, and selections without matching table objects produce a tool error. The tool reads the ExFace metamodel; it does not inspect the live database schema.

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

**Configuration.** The tool exposes three UXON properties that control formatting and context:

| UXON property | Type | Default | Description |
| --- | --- | --- | --- |
| `output_mode` | enum | `markdown_table` | One of `markdown_table`, `markdown`, or `json`. |
| `header_level` | integer | `2` | Markdown heading level used for section headers, allowed range 1-6. Invalid values trigger a warning and fall back to `2`. |
| `include_object_description` | boolean | `true` for `markdown_table`, else `false` | Adds a short object description block after the rendered data if available. |

**Output modes.** The tool supports three renderings: `markdown_table` (default), `markdown`, and `json`. `markdown_table` is best for multi-row result sets. `markdown` switches to a record-by-record summary for empty, single-row, or wide results, where a compact table is not the clearest representation.

**Return value.** The tool returns a string result created by `renderOutput()`. The final output always starts with a brief sentence such as `Read data of object ...`, followed by the selected payload (`markdown_table`, `markdown`, or `json`), and then optionally appends the object description block if enabled.

**Warnings and recoverable issues.** Unsupported or invalid configuration values are treated as warnings rather than fatal errors. The tool falls back to the safe default and keeps the response running. Empty result sets also produce a warning, while failed object-description rendering is swallowed and logged as a warning without breaking the tool result.

## `ModelObjectSearchTool`

**Alias:** `axenox.GenAI.ModelObjectSearchTool` | [UXON prototype](api/docs/exface/Core/Docs/UXON/UXON_prototypes.md?selector=%5Caxenox%5CGenAI%5CAI%5CTools%5CModelObjectSearchTool)

**Purpose.** Searches the object configured in `data_sheet` by the first configured column. By default, it searches `exface.Core.OBJECT` by `NAME`.

**Use when.** The agent needs a compact list of rows matching a user-provided value in one predefined attribute.

**Do not use when.** Do not use it for advanced model analysis or alias/UID lookup across many criteria. Use `ModelObjectInfoTool` or `DataSheetReadTool` for richer or broader queries.

| UXON property | Default | Description |
| --- | --- | --- |
| `data_sheet` | `exface.Core.OBJECT` with `NAME` as its first column | Complete DataSheet UXON defining the searched object and returned attributes. The first column is the search attribute. It may also contain additional filters, sorters, and a row limit. |

| Argument | Required | Description |
| --- | --- | --- |
| `object_name` | Yes | Value matched exactly against the first configured DataSheet column. |

**Default search configuration.** The first configured column is always used as the search attribute and is also returned in the result. The default object is `exface.Core.OBJECT`; its first column and search attribute is `NAME`. The remaining default returned attributes are `UID`, `ALIAS`, `ALIAS_WITH_NS`, `LABEL`, `SHORT_DESCRIPTION`, `APP`, `READABLE_FLAG`, `WRITABLE_FLAG`, `DATA_SOURCE`, `PARENT_OBJECT`, `HAS_DEFAULT_EDITOR`, and `INHERIT_DATA_SOURCE_BASE_OBJECT`.

**How to use.** Put the attribute to search first in `data_sheet.columns`, followed by any other attributes to return. Pass its search value in `object_name`. For example, an `axenox.GenAI.AI_AGENT` DataSheet beginning with `NAME` searches agents by name; one beginning with `UID` searches them by UID. Filters configured in the DataSheet are applied in addition to this generated search filter. Reads are capped at 100 rows. `ToolIntroductionConcept` lists the effective search object, first-column search attribute, and returned attributes or expressions for each configured instance.

**Result and limits.** The tool returns the configured DataSheet columns as a Markdown table. Available columns depend on the configured object's metamodel. Empty matches are returned as a warning-style message.

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

## `NotesListTool`

**Alias:** `axenox.GenAI.NotesListTool` | [UXON prototype](api/docs/exface/Core/Docs/UXON/UXON_prototypes.md?selector=%5Caxenox%5CGenAI%5CAI%5CTools%5CNotesListTool)

**Purpose.** Lists the types and topics of all long-term notes for the invoking agent and authenticated user without exposing their contents.

**Use when.** The agent needs a compact overview of its available notes, for example as prompt context before deciding whether a targeted search is useful. The tool has no arguments.

**Result and limits.** Returns a Markdown table with the columns `Type` and `Topic`, sorted by type and topic. User and agent filters are always derived from the current request and cannot be supplied by the model. Note bodies and UIDs are not read or returned.

## `NotesWriteTool`

**Alias:** `axenox.GenAI.NotesWriteTool` | [UXON prototype](api/docs/exface/Core/Docs/UXON/UXON_prototypes.md?selector=%5Caxenox%5CGenAI%5CAI%5CTools%5CNotesWriteTool)

**Purpose.** Stores a typed long-term note for the invoking agent and authenticated user. Memories retain reusable context; suggestions record potential improvements, missing tools, or other opportunities to improve work on a topic.

**Use when.** An agent should remember a stable preference, decision, or other reusable fact across conversations. Use a short, stable topic so later writes can replace the note by exact topic, or supply a UID returned by a notes tool to overwrite a known note explicitly.

**Do not use when.** Do not store secrets, transient conversation details, or information the user did not ask or expect the agent to retain.

| Argument | Required | Description |
| --- | --- | --- |
| `topic` | Yes | Short topic that identifies the note within the current user and agent scope. |
| `note` | Yes | Complete note body. It replaces the existing body when the topic already exists. |
| `uid` | No | UID of a known note to overwrite explicitly. The note must belong to the current user and agent. |
| `type` | No | `memory` (default) for reusable context or `suggestion` for potential improvements and missing capabilities. |

**Result and limits.** When `uid` is supplied, the matching scoped note is overwritten with the supplied topic and body; an unknown or out-of-scope UID produces a not-found error. Without `uid`, the tool updates an exact topic match or creates a new note. Updates carry all system attributes read with the note so timestamp conflict checks remain active. The tool returns the saved note UID. User and agent UIDs are derived from the current request and cannot be supplied by the model.

## `NotesReadTool`

**Alias:** `axenox.GenAI.NotesReadTool` | [UXON prototype](api/docs/exface/Core/Docs/UXON/UXON_prototypes.md?selector=%5Caxenox%5CGenAI%5CAI%5CTools%5CNotesReadTool)

**Purpose.** Reads one long-term note by UID for the invoking agent and authenticated user.

**Use when.** `NotesSearchTool` or `NotesWriteTool` supplied a note UID and the agent needs the complete topic and body.

**Do not use when.** Do not guess UIDs or use this tool to discover notes. Search first when the relevant UID is unknown.

| Argument | Required | Description |
| --- | --- | --- |
| `note_uid` | Yes | UID of the note to read. |

**Result and limits.** The result contains the type, topic, and complete note body as Markdown. The lookup always includes hidden user and agent filters. Missing and out-of-scope UIDs produce the same not-found error to prevent information disclosure.

## `NotesSearchTool`

**Alias:** `axenox.GenAI.NotesSearchTool` | [UXON prototype](api/docs/exface/Core/Docs/UXON/UXON_prototypes.md?selector=%5Caxenox%5CGenAI%5CAI%5CTools%5CNotesSearchTool)

**Purpose.** Searches long-term note topics and bodies for the invoking agent and authenticated user.

**Use when.** The agent needs to discover whether it has relevant memory before answering or updating a note.

**Do not use when.** If a note UID is already known, use `NotesReadTool` directly.

| Configuration | Default | Description |
| --- | --- | --- |
| `excerpt_length` | `300` | Maximum number of note-body characters included in each result. Must be greater than zero. |

| Argument | Required | Description |
| --- | --- | --- |
| `query` | No | Text to find in either note topics or note bodies. Leave empty to return all notes. |
| `type` | No | `all` (default), `memory`, or `suggestion`. Concrete types restrict the search results. |

**Result and limits.** Each match includes its UID, type, topic, and a single-line excerpt limited by `excerpt_length`. The excerpt is centered around the search text when it occurs literally in the note, helping the model select the relevant UID before calling `NotesReadTool` for the complete content. The tool never searches notes belonging to another user or agent.

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

## `ModelSearchTool`

**Alias:** `axenox.GenAI.ModelSearchTool` | [UXON prototype](api/docs/exface/Core/Docs/UXON/UXON_prototypes.md?selector=%5Caxenox%5CGenAI%5CAI%5CTools%5CModelSearchTool)

**Purpose.** Searches the ExFace metamodel for references and usage locations using the predefined object `exface.Core.SEARCH_RESULT`.

**Use when.** The agent needs to find where an object alias, action alias, page alias, or another model term is referenced inside model UXON.

**Do not use when.** Do not use it for generic business data retrieval. Use `DataSheetReadTool` when you need custom object access or custom columns beyond model-search defaults.

| Argument | Required | Description |
| --- | --- | --- |
| `search_query` | Yes | Search term for the model search. |
| `object_type` | No | Optional type filter such as `exf_object`, `exf_attribute`, `exf_page`, or `exf_object_action`. |
| `rows_limit` | No | Optional maximum row count. Defaults to `50`. |
| `rows_offset` | No | Optional pagination offset. Defaults to `0`. |

**How to use.** The tool wraps `DataSheetReadTool` and predefines `object_alias`, columns, and the UXON search filter. You only provide the search term and optional narrowing arguments.

**What it looks like.** The AI enters a search term, for example `search_query = "\"exface.Core.USER\""`, and receives matching usage rows.

**Result and limits.** The result is rendered like `DataSheetReadTool` output and includes matched model entities with usage context fields such as object name, instance name, and instance alias.

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

## `ModelPrototypeSearchTool`

**Alias:** `axenox.GenAI.ModelPrototypeSearchTool` | [UXON prototype](api/docs/exface/Core/Docs/UXON/UXON_prototypes.md?selector=%5Caxenox%5CGenAI%5CAI%5CTools%5CModelPrototypeSearchTool)

**Purpose.** Searches for UXON prototype classes of a component type by alias.

**Use when.** The agent knows the kind of component, such as an action, behavior, or data type, but needs to discover its prototype selector before creating UXON.

**Do not use when.** If the PHP class or prototype file path is already known, use `ModelPrototypeInfoTool` directly.

| UXON property | Default | Description |
| --- | --- | --- |
| `include_prototype_info_if_not_more_results_than` | `1` | Automatically appends the output of `ModelPrototypeInfoTool` when the search returns no more than this number of results. Set to `0` to disable enrichment. |

| Argument | Required | Description |
| --- | --- | --- |
| `search_query` | Yes | Prototype alias without namespace. |
| `component` | Yes | Searchable component type, such as `action`, `behavior`, or `data_type`. |
| `rows_limit` | No | Optional maximum row count. Defaults to `50`. |
| `rows_offset` | No | Optional pagination offset. Defaults to `0`. |

**How to use.** Pass a component type and the most specific known alias fragment. By default, a single match includes both the search row and the prototype's UXON documentation, avoiding a second tool call.

**Result and limits.** The result is a Markdown table containing prototype selectors. When the configured result threshold is met, the corresponding UXON prototype documentation is appended. Broad searches return only the table unless the threshold is raised.

## `ModelPrototypeInfoTool`

**Alias:** `axenox.GenAI.ModelPrototypeInfoTool` | [UXON prototype](api/docs/exface/Core/Docs/UXON/UXON_prototypes.md?selector=%5Caxenox%5CGenAI%5CAI%5CTools%5CModelPrototypeInfoTool)

**Purpose.** Generates documentation for the configurable UXON properties of a PHP prototype.

**Use when.** The agent is about to create or edit UXON for an action, widget, behavior, connector, data type, tool, concept, or another prototype and must verify available properties, types, defaults, and descriptions.

**Do not use when.** This tool documents a prototype class, not a concrete page instance or metaobject. Use `UiWidgetInfoTool` or `ModelObjectInfoTool` for those cases.

| Argument | Required | Description |
| --- | --- | --- |
| `selector` | Yes | PHP class name or prototype file path. |

**How to use.** The model passes either a fully qualified PHP class beginning with `\` or a PHP file path relative to the vendor directory. Aliases are not currently supported as selectors.

**Result and limits.** `UxonPrototypeMarkdownPrinter` returns the prototype description and indexed UXON properties. The quality of the result depends on the prototype's annotations being available in the model.

## `UxonAutosuggestTool`

**Alias:** `axenox.GenAI.UxonAutosuggestTool` | [UXON prototype](api/docs/exface/Core/Docs/UXON/UXON_prototypes.md?selector=%5Caxenox%5CGenAI%5CAI%5CTools%5CUxonAutosuggestTool)

**Purpose.** Returns the same context-aware property names, values, templates, presets, details, and model entries as the UXON editor autosuggest.

**Use when.** An agent is creating or editing UXON and needs to discover valid properties or values for a specific node. It is particularly useful before generating attributes, relations, component aliases, enum values, or nested UXON structures.

**Do not use when.** Do not use autosuggest as final validation or assume every suggestion is valid outside the supplied context. Use `UxonValidateTool` after assembling the UXON.

| Argument | Required | Description |
| --- | --- | --- |
| `uxon` | Yes | Complete UXON object being edited. |
| `path` | Yes | Array of property names and array indexes from the root to the node being edited. |
| `input` | Yes | Suggestion type: `field`, `value`, `preset`, `details`, or `modelbrowser`. |
| `text` | No | Text typed so far, used to filter value and model-browser suggestions. |
| `object` | No | Alias or UID of the root metaobject that supplies object context. |
| `prototype` | No | Fully qualified root prototype class or PHP file path relative to the vendor folder. |
| `schema` | No | UXON schema class or schema name used to interpret the UXON. |

**How to use.** Pass the complete current UXON because sibling and parent properties can determine the applicable prototype and valid values. Use `field` to request property names and templates, and `value` to request values for the property addressed by `path`. Use `preset` for predefined structures, `details` for property documentation, and `modelbrowser` for structured metamodel entries. Supply reliable object and prototype context whenever available.

**Result and limits.** The result is JSON produced by the core `UxonAutosuggest` action. Field suggestions contain `values` and `templates`; value suggestions contain `values`; presets, details, and model-browser calls return their mode-specific structures. Empty suggestions can mean that no value is known for the supplied context. Action failures are returned as tool errors.

## `UxonValidateTool`

**Alias:** `axenox.GenAI.UxonValidateTool` | [UXON prototype](api/docs/exface/Core/Docs/UXON/UXON_prototypes.md?selector=%5Caxenox%5CGenAI%5CAI%5CTools%5CUxonValidateTool)

**Purpose.** Validates generated UXON and returns structured diagnostics that an agent can use to correct likely configuration errors.

**Use when.** An agent has created or changed UXON for a widget, action, behavior, connector, or another configurable prototype. Call it before returning or applying the UXON when the relevant schema or prototype context is known.

**Do not use when.** Do not treat the result as authoritative runtime validation. The validator creates mock components and can report false positives or miss context-dependent errors.

| Argument | Required | Description |
| --- | --- | --- |
| `uxon` | Yes | UXON object to validate. |
| `schema` | No | UXON schema class or schema name used to interpret the UXON. |
| `object` | No | Alias or UID of the root metaobject that supplies object context. |
| `prototype` | No | Fully qualified root prototype class or PHP file path relative to the vendor folder. |

**How to use.** Pass the generated UXON and as much reliable context as is available. A prototype may be supplied as `\exface\Core\Widgets\DataTable` or `exface/core/Widgets/DataTable.php`. The explicit tool call always runs validation and is not disabled by the `DEBUG.AUTOMATIC_UXON_VALIDATION` setting used by the editor action.

**Result and limits.** The result is a JSON array of objects with `path` and `message` properties. An empty array means that no issues were detected, not that the UXON is guaranteed to work. Invalid tool input or a validator failure is returned as a tool error.

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

## `UiOverviewTool`

**Alias:** `axenox.GenAI.UiOverviewTool` | [UXON prototype](api/docs/exface/Core/Docs/UXON/UXON_prototypes.md?selector=%5Caxenox%5CGenAI%5CAI%5CTools%5CUiOverviewTool)

**Purpose.** Produces a Markdown overview of the platform's main menu and of the screens of a given app. The main menu is listed completely with a page link for every entry, while the pages of the app of interest and the dialogs reachable from them are described in detail.

**Use when.** The agent needs to understand which screens an app offers, what a user can do on them, and how to navigate to further pages. The page links in the menu can be passed to `UiWidgetInfoTool` for deeper inspection.

**Do not use when.** To inspect the UXON of a single, already known page or dialog, use `UiWidgetInfoTool` directly.

| Argument | Required | Description |
| --- | --- | --- |
| `app` | Yes | Alias of the app whose pages are described in detail (for example `exface.Core`). |
| `depth` | No | How deep to follow dialogs opened by buttons inside the app's pages. Defaults to `10`. |

**How to use.** The model supplies the app alias and optionally a recursion depth. The menu is built the same way as the `NavMenu` widget, starting from the default server root page.

**Result and limits.** Each screen chapter lists the meta objects shown on the screen and all buttons available to the user. Dialogs are documented recursively until the depth budget is exhausted; only menu-visible pages appear in the overview.

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