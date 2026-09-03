<?php
namespace axenox\GenAI\Exceptions;

use axenox\GenAI\Interfaces\AiPromptInterface;
use axenox\GenAI\Interfaces\AiToolCallInterface;
use axenox\GenAI\Interfaces\AiToolInterface;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\DataTypes\MarkdownDataType;
use exface\Core\Exceptions\ExceptionTrait;
use exface\Core\Exceptions\RuntimeException;
use exface\Core\Facades\DocsFacade;
use exface\Core\Factories\WidgetFactory;
use exface\Core\Widgets\DebugMessage;

/**
 * Exception thrown if an AI tool encounters a severe error, which makes it impossible to use this tool for the LLM
 * 
 * In particular, this type of error means, the tool did not return any meaningful result and should actually not be
 * used again. 
 * 
 * If this error occurs, the agent will tell the LLM to stop using the tool, but the other tools will remain usable
 * and the conversation can continue.
 * 
 * @author Andrej Kabachnik
 */
class AiToolCriticalError extends RuntimeException
{
	private AiToolInterface $tool;
	private AiPromptInterface $prompt;
	private ?AiToolCallInterface $toolCall = null;

	/**
	 * @param AiToolInterface $tool
	 * @param AiPromptInterface $prompt
	 * @param string $message
	 * @param string|null $alias
	 * @param \Throwable|null $previous
	 */
	public function __construct(AiToolInterface $tool, AiPromptInterface $prompt, string $message, ?string $alias = null, ?\Throwable $previous = null)
	{
		parent::__construct($message, $alias, $previous);
		$this->tool = $tool;
		$this->prompt = $prompt;
	}

	/**
	 * @return AiToolInterface
	 */
	public function getTool(): AiToolInterface
	{
		return $this->tool;
	}

	/**
	 * @return AiPromptInterface
	 */
	public function getPrompt(): AiPromptInterface
	{
		return $this->prompt;
	}

	/**
	 * Adds the concrete LLM invocation that caused this error.
	 *
	 * @param AiToolCallInterface $toolCall
	 * @return $this
	 */
	public function setToolCall(AiToolCallInterface $toolCall): AiToolCriticalError
	{
		$this->toolCall = $toolCall;
		return $this;
	}

	/**
	 * @return AiToolCallInterface|null
	 */
	public function getToolCall(): ?AiToolCallInterface
	{
		return $this->toolCall;
	}

	/**
	 * {@inheritDoc}
	 * @see ExceptionTrait::createDebugWidget()
	 */
	public function createDebugWidget(DebugMessage $debugWidget)
	{
		$debugWidget = parent::createDebugWidget($debugWidget);

		$toolTab = $debugWidget->createTab();
		$toolTab->setCaption('AI Tool Call');
		$toolName = MarkdownDataType::escapeString($this->toolCall?->getToolName() ?? $this->tool->getName());
		$toolPrototype = MarkdownDataType::escapeString($this->tool->getAliasWithNamespace());
		$toolDocsUrl = DocsFacade::buildUrlToDocsForUxonPrototype($this->tool);
		$toolDetailsMarkdown = <<<MD
| Property | Value |
| -------- | ----- |
| Name | {$toolName} |
| Prototype | [{$toolPrototype}]({$toolDocsUrl}) |
MD;
		if ($this->toolCall !== null) {
			$callId = MarkdownDataType::escapeString($this->toolCall->getCallId());
			$toolDetailsMarkdown .= "\n| Call ID | {$callId} |";
		}
		$toolTab->addWidget(WidgetFactory::createFromUxonInParent($toolTab, new UxonObject([
			'widget_type' => 'Markdown',
			'width' => '100%',
			'hide_caption' => true,
			'value' => $toolDetailsMarkdown,
		])));

		if ($this->toolCall !== null && $this->toolCall->getArguments() !== []) {
			$toolTab->addWidget(WidgetFactory::createFromUxonInParent($toolTab, new UxonObject([
				'widget_type' => 'Markdown',
				'width' => '100%',
				'hide_caption' => true,
				'value' => "\n\n### Arguments",
			])));
			$toolTab->addWidget(WidgetFactory::createFromUxonInParent($toolTab, new UxonObject([
				'widget_type' => 'InputUxon',
				'disabled' => true,
				'width' => '100%',
				'hide_caption' => true,
				'value' => UxonObject::fromArray($this->toolCall->getArguments())->toJson(true),
			])));
		}

		$toolUxon = $this->tool->exportUxonObject();
		if ($toolUxon !== null) {
			$toolTab->addWidget(WidgetFactory::createFromUxonInParent($toolTab, new UxonObject([
				'widget_type' => 'Markdown',
				'width' => '100%',
				'hide_caption' => true,
				'value' => "\n\n### Tool configuration",
			])));
			$toolTab->addWidget(WidgetFactory::createFromUxonInParent($toolTab, new UxonObject([
				'widget_type' => 'InputUxon',
				'disabled' => true,
				'width' => '100%',
				'height' => '100%',
				'hide_caption' => true,
				'value' => $toolUxon->toJson(true),
			])));
		}
		$debugWidget->addTab($toolTab);

		$debugWidget = $this->prompt->createDebugWidget($debugWidget);
		return $debugWidget;
	}
}