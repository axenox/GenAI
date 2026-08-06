# Tips and tricks for agent prompts

[Deutsch](prompting_german.md)

These notes complement the general description of agent versions without repeating the basics from the overview.

## Start with minimal context

A short shared context is often more valuable than a long block of documentation. The agent first needs the terminology, target audience, and boundaries of its environment. It can load details later as needed.

More depth is not automatically better. A small, well-linked start page can be rendered as part of the initial context. For larger areas, a concise introduction combined with on-demand loading is usually more robust.

## Concepts as background context

Good concepts provide information that the agent almost always needs without requiring the user to supply it. This includes stable background information, relevant rules, existing structures, or current context from the user interface.

A good placeholder name indicates why the section exists. Ambiguous names may expose context without making it useful.

## Documentation for orientation, tools for detail

Rendered documentation is useful for orientation. A documentation tool is useful for details. Together, they avoid two extremes: too little context in the prompt and too much complete documentation at once.

Links should be treated as paths to follow, not as something the model is expected to guess. If a visible link appears relevant to the answer, the agent should be allowed to follow it.

## Tools need a clear purpose

A tool is more likely to be used when the prompt describes its purpose. The model should be able to recognize what kind of uncertainty the tool can reduce.

The tool description should therefore read less like a technical label and more like decision guidance: What can the tool verify, what input is permitted, and when is its result better than guessing?

## Briefly explain visible tools

An automatically rendered tool overview is helpful, but it does not replace domain-specific guidance on where a tool fits into the workflow. A short rule in the prompt is often enough: verify before deriving, read before asserting, and load context before saving.

## Organize context by stability

Stable information belongs in concepts, while changing information belongs in tools. This keeps the prompt small without forcing the agent to work blindly.

This separation makes responses more robust. The agent starts with enough context for orientation while remaining flexible when a question requires more detail.
