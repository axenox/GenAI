# Agentic workflow architecture

This roadmap describes how scheduled, hardcoded autonomous actions can evolve into configurable and auditable agentic workflows. The first use case is a PowerUI implementation workflow that reads Jira tickets, routes them by state, and invokes specialized planning, testing, and implementation agents.

The design separates deterministic orchestration from probabilistic agent work. The workflow controls which step runs, validates its result, persists state, and selects the next node. An agent only performs the bounded task assigned to its node and returns a structured result.

## Goals

- Split monolithic autonomous actions into independently executable PowerUI actions.
- Give workflow actions a stable contract that a future engine can invoke.
- Support action, agent, decision, iteration, waiting, human-intervention, and exit nodes.
- Persist every run, ticket, node attempt, input, output, artifact, decision, warning, and error.
- Present a workflow run as one large conversation without requiring agents to communicate directly.
- Resume interrupted runs and retry individual steps without duplicating completed side effects.
- Use sequential execution initially and controlled parallel execution later.
- Keep authorization and data access inside the normal ExFace action mechanisms.

## Non-goals for the first release

- A graphical workflow editor.
- Arbitrary user-authored loops or executable PHP expressions.
- Direct conversations between agents.
- Allowing an LLM to choose unrestricted actions or workflow transitions.
- Replacing scheduled actions, the task API, or existing AI conversation persistence.

## Architectural principles

### Deterministic orchestration

Routing, retries, limits, permissions, and state transitions must be decided by workflow configuration and PHP code. Agent output may provide a business outcome, but the workflow validates that outcome against the node's declared outcomes before following an edge.

Technical failures and business outcomes are different concepts. `needs_input` or `implementation_failed` can be valid business outcomes. Timeouts, connector failures, invalid JSON, and uncaught exceptions are technical failures handled by the node's error policy.

### Actions are the execution boundary

Each operation is implemented as a regular PowerUI action and instantiated through `ActionFactory`. Workflow code must not instantiate action classes directly or call their protected implementation methods. This retains task validation, authorization, transactions, logging, and compatibility with UI, CLI, queue, and scheduled execution.

Existing `ActionChain` and `CallAction` are useful composition primitives, but they are not the workflow engine. They do not persist node attempts, suspend and resume runs, enforce idempotency, or model general graph transitions.

### Durable state before configuration

The initial workflow may remain a hardcoded orchestrator, but it must execute the same action and result contracts planned for the configurable engine. Durable run tracking should be introduced before moving the graph into UXON. This provides production evidence for the eventual node model and avoids designing an editor around untested abstractions.

### Artifacts instead of hidden context

Agents do not need to talk directly. Plans, patches, logs, and summaries are persisted as artifacts and referenced by later step inputs.

## Runtime model

### Workflow definition

A `WorkflowDefinition` is a versioned, immutable directed graph containing nodes, edges, input mappings, outcome mappings, retry policies, timeout policies, and concurrency limits. Editing a published definition creates a new version. Every run remains linked to the exact version with which it started.

### Workflow run

A `WorkflowRun` represents one invocation of a definition, normally one scheduled batch. It records:

- definition and version;
- status and terminal outcome;
- triggering task and schedule;
- authenticated PowerUI execution identity;
- start, update, and finish timestamps;
- root input and workflow variables;
- correlation and idempotency keys;
- parent conversation or timeline identifier.

### Workflow step run

A `WorkflowStepRun` represents one attempt to execute one node. It records:

- node identifier, type, and definition version;
- attempt number and idempotency key;
- normalized input and output;
- declared outcome and resulting edge;
- child action result or AI conversation identifier;
- warnings and technical error details;
- start, finish, duration, and status;
- artifacts read and produced.

Attempts are append-only. A retry creates a new attempt rather than overwriting the failed one.

Steps may be nested. A grouping step represents an independently processed subject and owns the
steps that process it. Its status and outcome summarize those child steps.

## Conversation model

The workflow should appear as a single large conversation in the monitoring UI, but different agents should not initially share one existing `AI_CONVERSATION` row. The current conversation model belongs to one agent and stores one system prompt. Reusing it across agents would mix agent ownership, versions, system prompts, and model metadata.

Instead, use a parent-child model:

- the workflow run owns a parent timeline;
- orchestration events such as starts, decisions, waits, retries, and exits are workflow messages;
- every agent node creates an ordinary child AI conversation;
- the step run links the child conversation to its parent workflow timeline;
- the UI renders workflow events and child conversation messages chronologically as one trace.

This gives designers a unified audit view while preserving the existing details of each agent invocation. A future generalized conversation abstraction may make a workflow timeline, AI chat, tool session, and MCP session different conversation types, but that refactoring is not required for the first workflow release.

## Node types

Some possible node types could include:

| Node type | Responsibility |
| --- | --- |
| `start` | Validate root input and initialize run variables. |
| `action` | Execute a configured PowerUI action. |
| `agent` | Execute an agent action with a declared structured response schema. |
| `decision` | Select an edge using deterministic conditions and declared outcomes. |
| `foreach` | Create a grouping step for each item in a collection and execute its branch. |
| `join` | Wait for required branches or grouping steps and aggregate their outcomes. |
| `wait` | Suspend until a time, event, or external state is reached. |
| `human_gate` | Suspend for approval, correction, or additional input. |
| `subworkflow` | Execute a separately versioned reusable workflow. |
| `exit` | Complete a workflow or item with a terminal outcome. |

The first implementation only needs `start`, `action`, `agent`, `decision`, `foreach`, and `exit`. Other types should remain reserved rather than simulated with special action names.

## Step contract

All executable nodes receive a `WorkflowStepContext` and produce a `WorkflowStepResult`. These are engine concepts and should not be encoded as arbitrary task parameter names.

The context contains the workflow run, optional parent step, node ID, attempt, execution identity, variables, artifacts, and input DataSheet. The result contains a declared outcome, structured output, produced artifact references, status messages, optional child conversation ID, and retry advice.

Recommended step statuses are `pending`, `running`, `succeeded`, `failed`, `waiting`, `skipped`, and `cancelled`. The separate outcome is domain-specific, for example `planned`, `needs_input`, `ready_to_test`, or `no_matching_state`.

Agent actions must use a response JSON schema. Free-form text can be retained as an artifact or message, but routing must only use validated fields. A minimal agent result should include:

```json
{
    "outcome": "planned",
    "summary": "Implementation plan created",
    "artifacts": [
        {"type": "implementation_plan", "uri": "..."}
    ]
}
```

## Configuration sketch

The eventual UXON references allow-listed actions and uses explicit edges. Expressions are evaluated by the workflow engine against a documented context and must not execute arbitrary PHP.

```json
{
    "alias": "powerui.Agent.ImplementationWorkflow",
    "version": 1,
    "start_at": "fetch_tickets",
    "nodes": {
        "fetch_tickets": {
            "type": "action",
            "action": {"alias": "powerui.Agent.FetchAssignedJiraTickets"},
            "next": "has_tickets",
            "on_error": "workflow_failed"
        },
        "has_tickets": {
            "type": "decision",
            "expression": "=Count(workflow.tickets) > 0",
            "on_true": "tickets",
            "on_false": "no_work"
        },
        "tickets": {
            "type": "foreach",
            "items": "workflow.tickets",
            "max_parallel": 1,
            "next": "route_ticket"
        },
        "route_ticket": {
            "type": "decision",
            "cases": {
                "ready": "plan",
                "implementing": "implement"
            },
            "default": "skip_ticket"
        },
        "plan": {
            "type": "agent",
            "action": {"alias": "powerui.Agent.PlanJiraTicket"},
            "next": "generate_tests",
            "on_error": "record_failure"
        },
        "implement": {
            "type": "agent",
            "action": {"alias": "powerui.Agent.ImplementJiraTicket"},
            "lock": "jira:[#ticket.key#]",
            "next": "update_ticket",
            "on_error": "record_failure"
        }
    }
}
```

## Identity and authorization

Use a dedicated, non-interactive PowerUI service account for scheduled execution rather than the CLI user's identity. Keep these identities distinct even if the first deployment maps them to one account:

- the PowerUI execution identity used for action authorization and audit;
- the Jira connector credential used for API authentication;
- the Jira technical assignee used to select workflow tickets;
- the human requester or ticket reporter on whose behalf work is performed.

Every run records the execution identity and every external mutation records the connector identity. Agent tools and child actions continue to enforce their normal authorization. Workflow configuration cannot elevate permissions by naming an otherwise inaccessible action.

## Reliability and concurrency

- Use an idempotency key based on definition version, subject identity, node ID, attempt policy, and relevant input version.
- Acquire a lease before implementation. The first release sets global implementation concurrency to one; later definitions may use per-project limits.
- Store lease ownership and expiry so crashed workers do not block tickets permanently.
- Use the Jira issue version or update timestamp for optimistic concurrency before writing.
- Commit workflow audit records separately from long-running external calls. Do not keep a database transaction open while waiting for an LLM or Jira.
- Retry only failures classified as transient. Use bounded exponential backoff and persist the next attempt time.
- Resume from the latest durable successful step. Never infer completion solely from a log message.
- Provide cancellation and a dead-letter state for exhausted or non-retryable failures.

## Observability and operations

The monitoring UI should show definition version, run and grouping-step status, current node, duration, attempts, costs, token usage, artifacts, decisions, warnings, and errors. Operators need controls to cancel a run, retry a failed step, resume a waiting run, and open each child AI conversation.

Logs and timeline entries use the workflow run ID and step run ID as correlation fields. Sensitive prompts, credentials, source data, and large artifacts require redaction and retention policies.

## MVP status

The MVP is a hardcoded, sequential workflow with durable audit data. It deliberately proves the execution and monitoring model before introducing configurable graphs.

Implemented:

- Runs and ordered steps are persisted with status, outcome, subject, message, error, timing, hierarchy, and an optional child conversation link.
- Grouping steps represent independently processed subjects and summarize their child steps.
- The monitoring page lists runs and their steps and can open linked conversations.
- Runs are closed in `finally`; persistence errors are logged without interrupting successful business work.

The MVP does not yet provide configurable definitions, dedicated item or attempt records, artifacts, retries, resumability, leases, parallel execution, or a combined step-and-message timeline.

## MVP completion plan

1. Define and validate the supported statuses, outcomes, transitions, and terminal states.
2. Render step messages and linked AI messages as one ordered timeline without duplicating conversations.
3. Exercise successful, empty, skipped, partially failed, and interrupted runs on each supported database.

## Later phases

1. **Durable execution:** add append-only attempt records, idempotency, leases, bounded retries, resumability, cancellation, and artifact references.
2. **Configurable engine:** add immutable definitions, graph validation, the minimal node registry, and UXON configuration for the proven workflow.
3. **Designer experience:** add publishing, version comparison, simulation, a visual editor, and operational analytics.

## Open decisions

- Which structured outcomes and transitions complete each branch?
- Where do large outputs and other workflow artifacts live?
- Which failures retry automatically, and which require human intervention?
- What concurrency and optimistic-update rules apply to independently processed items?

