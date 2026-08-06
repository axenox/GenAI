# AI Agents

[Deutsch](index_german.md)

An agent is the functional unit that receives a prompt and generates a response from the LLM. In the configuration, an agent consists of two levels:

- `AI_AGENT` describes the agent's stable identity, such as its name, alias, description, and app assignment.
- `AI_AGENT_VERSION` describes a specific executable version of that agent, such as its prototype, LLM connection, instructions, and `CONFIG_UXON`.

This allows an agent to have multiple versions. Calling components use the agent alias and, optionally, a version constraint. The factory then selects the appropriate enabled version. New prompts, tools, or concepts should therefore be maintained as new agent versions whenever behavioral changes need to remain traceable.

## Related topics

- [Prompting, concepts, and tools](prompting.md)
- [Tool reference](../Tools/index.md)
- [Concept reference](../Concepts/index.md)

## Structure of an agent version

An agent version defines how the agent is created at runtime:

- `PROTOTYPE_CLASS` points to the agent's PHP class, for example `axenox/genai/AI/Agents/GenericAssistant.php`.
- `DATA_CONNECTION`, or the default connection, determines which AI connector or model is used.
- `INSTRUCTIONS` contains the system prompt. It can include Markdown and placeholders.
- `CONFIG_UXON` is the structured configuration model for the prototype. For `GenericAssistant`, it is used to import tools, concepts, the response schema, and other prototype properties.

The prototype implements the behavior in PHP. The UXON model provides the specific configuration for a version. This allows the same prototype to be reused for multiple agents or versions.

## Role of `CONFIG_UXON`

`CONFIG_UXON` is not a second prompt. It is the machine-readable configuration of the agent prototype. When a version is loaded, the UXON is read from `AI_AGENT_VERSION.CONFIG_UXON`. The version's name, alias, and `INSTRUCTIONS` are then added to this UXON, which is used to instantiate the configured prototype.

A typical excerpt looks like this:

```json
{
  "tools": {
    "GetObject": {
      "description": "Load information about a metaobject.",
      "arguments": [
        {
          "name": "object_alias",
          "data_type": { "alias": "exface.Core.String" },
          "description": "Fully qualified object alias or UID"
        }
      ]
    }
  },
  "concepts": {
    "sitemap": {
      "alias": "axenox.GenAI.AppDocsConcept",
      "depth": 0,
      "app_alias": "exface.Core",
      "starting_page": "sitemap.md"
    }
  }
}
```

The property names in the UXON correspond to the prototype's configurable properties. For `GenericAssistant`, the most relevant properties include `tools`, `concepts`, `response_json_schema`, and other documented UXON properties.

## Instructions and concepts

Concepts are placeholders that dynamically generate parts of the system prompt. They are maintained under `concepts` in the UXON. The key is the placeholder name used in the instructions.

Example:

```json
{
  "concepts": {
    "introduction": {
      "alias": "axenox.GenAI.AppDocsConcept",
      "depth": 0,
      "app_alias": "exface.Core",
      "starting_page": "Getting_started/introduction.md"
    }
  }
}
```

The concept can then be included in the instructions:

```md
## Introduction to the platform

[#introduction#]
```

At runtime, the agent first renders the concepts and replaces the placeholders in the prompt. Concepts are suitable for context generated from data, documentation, the metamodel, or tool output. Some concepts can also provide their own tool models; during rendering, these are added to the agent's tool configuration.

## Configuring tools

Tools are maintained under `tools` in `CONFIG_UXON`. The key is the function name that the LLM can use later. A tool definition describes at least its purpose and arguments. A specific tool prototype can optionally be selected via `alias` or `class`. Without an explicit selector, the factory attempts to find the tool prototype by its function name.

Example:

```json
{
  "tools": {
    "GetLogEntry": {
      "arguments": [
        {
          "name": "LogId",
          "data_type": { "alias": "exface.Core.String" },
          "description": "The Log-ID visible to the designer"
        }
      ],
      "description": "Read the log entry for a visible Log-ID."
    }
  }
}
```

The description should clearly tell the LLM when to use the tool and which values are permitted. Arguments should have names, data types, and descriptions that are as specific as possible.

## Building an agent

1. Create the agent in `AI_AGENT` and define its alias, name, description, and app assignment.
2. Create the first version in `AI_AGENT_VERSION`.
3. Select an appropriate `PROTOTYPE_CLASS`, usually `GenericAssistant` for chat assistants.
4. Configure the LLM or data connection.
5. Write the instructions and add the required concept placeholders.
6. Configure concepts in `CONFIG_UXON` and match the placeholder names to those in the instructions.
7. Configure tools in `CONFIG_UXON` if the agent needs to actively load data or prepare actions.
8. Test the agent with test cases and conversation logs, and maintain improvements as new versions.

## Versioning

Versions make agent behavior reproducible. A new version is appropriate when instructions, tools, concepts, the response schema, or the model connection change in ways that may affect existing tests or production responses.

When loading an agent, the factory retrieves all of its versions, sorts them in descending order, and selects the best match for the requested version constraint. If a version has no connection of its own, it can inherit one from a previous version. Nevertheless, the version currently used by a production agent should be clearly documented.