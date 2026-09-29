<?php
namespace axenox\GenAI\Exceptions;

use exface\Core\CommonLogic\UxonObject;
use exface\Core\Exceptions\RuntimeException;
use exface\Core\Factories\WidgetFactory;
use exface\Core\Widgets\DebugMessage;

/**
 * Raised when a persisted conversation is opened with a different agent or agent version.
 */
class AiConversationAgentVersionMismatchError extends RuntimeException
{
	private string $conversationUid;

	private string $storedAgentUid;

	private string $storedAgentVersion;

	private string $requestedAgentUid;

	private string $requestedAgentVersion;

	private ?string $storedAgentVersionUid;

	/**
	 * @param string $conversationUid Persisted conversation UID.
	 * @param string $storedAgentUid Agent UID assigned to the conversation.
	 * @param string $storedAgentVersion Agent version assigned to the conversation.
	 * @param string $requestedAgentUid Agent UID attempting to open the conversation.
	 * @param string $requestedAgentVersion Agent version attempting to open the conversation.
	* @param string|null $storedAgentVersionUid UID of the persisted agent version, if valid.
	 */
	public function __construct(
		string $conversationUid,
		string $storedAgentUid,
		string $storedAgentVersion,
		string $requestedAgentUid,
		string $requestedAgentVersion,
		?string $storedAgentVersionUid = null
	) {
		$message = $storedAgentVersionUid === null
			? "AI Conversation '$conversationUid' does not reference a valid agent version"
			: "AI Conversation '$conversationUid' belongs to agent '$storedAgentUid' version "
				. "'$storedAgentVersion', not agent '$requestedAgentUid' version '$requestedAgentVersion'";
		parent::__construct($message);
		$this->conversationUid = $conversationUid;
		$this->storedAgentUid = $storedAgentUid;
		$this->storedAgentVersion = $storedAgentVersion;
		$this->requestedAgentUid = $requestedAgentUid;
		$this->requestedAgentVersion = $requestedAgentVersion;
		$this->storedAgentVersionUid = $storedAgentVersionUid;
	}

	/**
	 * Adds a comparison of the persisted and requested conversation assignment.
	 */
	public function createDebugWidget(DebugMessage $debugWidget)
	{
		$debugWidget = parent::createDebugWidget($debugWidget);
		$tab = $debugWidget->createTab();
		$tab->setCaption('Conversation assignment');
		$tab->addWidget(WidgetFactory::createFromUxonInParent($tab, new UxonObject([
			'widget_type' => 'Markdown',
			'value' => $this->buildAssignmentMarkdown()
		])));
		$debugWidget->addTab($tab);

		return $debugWidget;
	}

	/**
	 * Builds the assignment comparison shown in the debug widget.
	 */
	private function buildAssignmentMarkdown() : string
	{
		$conversationUid = $this->escapeMarkdownCode($this->conversationUid);
		$storedAgentUid = $this->escapeMarkdownCode($this->storedAgentUid);
		$storedAgentVersion = $this->escapeMarkdownCode($this->storedAgentVersion);
		$requestedAgentUid = $this->escapeMarkdownCode($this->requestedAgentUid);
		$requestedAgentVersion = $this->escapeMarkdownCode($this->requestedAgentVersion);

		if ($this->storedAgentVersionUid === null) {
		    return <<<MD
	# Conversation has an invalid agent-version assignment

	Conversation `$conversationUid` stores agent `$storedAgentUid` and version `$storedAgentVersion`, but that assignment does not resolve to a persisted agent-version UID.

	The conversation cannot be continued safely. Repair its agent-version assignment or start a new conversation with agent `$requestedAgentUid` version `$requestedAgentVersion`.
	MD;
		}

		$storedAgentVersionUid = $this->escapeMarkdownCode($this->storedAgentVersionUid);

		return <<<MD
# Conversation cannot be opened by this agent version

	Conversation `$conversationUid` is permanently assigned to agent-version UID `$storedAgentVersionUid`. Reusing it with another agent or version would mix system prompts and message history.

| Assignment | Agent UID | Version |
|---|---|---|
| Stored conversation | `$storedAgentUid` | `$storedAgentVersion` |
| Requested runtime | `$requestedAgentUid` | `$requestedAgentVersion` |

Start a new conversation with the requested agent version, or restore this conversation with its stored agent version.
MD;
	}

	/**
	 * Escapes values embedded in Markdown code spans.
	 */
	private function escapeMarkdownCode(string $value) : string
	{
		return str_replace(['\\', '`', '|'], ['\\\\', '\\`', '\\|'], $value);
	}
}
