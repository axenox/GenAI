---
description: "Use when working on AI concepts - configurable placeholders in the instructions of our AI agents"
name: "AI concepts"
applyTo: "AI/Concepts/*.php"
---
# AI concepts

## Summary

AI concepts are placeholders in the instructions of our AI agents, that are 
automatically resolved when a prompt is sent. Thus, the LLMs always receive 
up-to-date information, even if instructions themselves are not updated.

## Examples

Here is an example for an agent configuration re-using part of the github  
copilot instructions:

```
{
    "instructions": "You help write SQL migrations\n\n[#migration_instructions#]",
    "concepts": {
        "migration_instructions": {
            "file_paths": [
                 "exface/core/.github/instructions/sql-migrations.instructions.md"
             ],
            "heading_level": 2
        }
    }
}
```

Here is another example for an agent, that always gets a DBML schema of the 
DB from the connection in the input data (the placeholder `[#~input:UID#]` is 
replaced with the UID selected data row in the UI, which is a data 
connection for this particular agent):

```
{
    "instructions": "You help write SQL queries based on the DB schema.
    \n\n## DBML schema\n\n[#dbml_schema#]",
    "concepts": {
        "dbml_schema": {
            "class": "\\axenox\\GenAI\\AI\\Concepts\\SqlDbmlConcept",
            "object_filters": {
                "operator": "AND",
                "conditions": [
                    {
                        "expression": "DATA_SOURCE__CONNECTION",
                        "comparator": "==",
                        "value": "[#~input:UID#]"
                    }
                ]
            }
        }
    }
}
```

## Implementation details

Each concept is a UXON prototype class. Concepts can be created in any app 
and should be placed in the `AI/Concepts` folder for easy autodiscovey. The 
PHP classes should have the suffix `Concept`.

Concepts must implement the `\axenox\GenAI\Interfaces\AiConceptInterface`. 
Most of them extend the `\axenox\GenAI\Common\AbstractContext` class for 
convenience.

Many concepts produce markdown, but theoretically they can have other return 
types if `getDataType()` is implemented properly. 

## Documentation maintenance

- Whenever a concept prototype is added, changed, renamed, or removed, update the concept documentation in `Docs/AI/Concepts/index.md` in the same change.
- Keep the English `Docs/AI/Concepts/index.md` and German `Docs/AI/Concepts/index_german.md` versions synchronized. Every content or navigation change must be applied to both language versions in the same change.
- Keep the documented purpose, use cases, unsuitable use cases, configuration, output, limitations, alias, and UXON prototype link consistent with the implementation.
- If a concept suggests tools or changes when its output is resolved, document that behavior explicitly.
- Concepts provided by other apps must be documented in the owning app. The GenAI documentation only lists prototypes supplied by `axenox.GenAI` and states that further concepts can exist in other apps.

## Global rules

- Read global instructions
    - Technical overview of the platform in `exface/core/.github/instructions/copilot-instructions.md`
    - All UXON prototypes must adhere to `exface/core/.github/instructions/uxon.instructions.md`