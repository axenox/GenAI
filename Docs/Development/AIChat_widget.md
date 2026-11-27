# AIChat widget

[Deutsch](../Translations/de/Development/AIChat_widget.md)

The app includes a special chat widget called `AIChat`. It is facade agnostic chat widget build with the DeepChat library.

## Look & feel

Set `intro_message` to show a Markdown note at the start of each new conversation.

Our chat widget should use the space available to it efficiently. We decreased padding and increased the width of speech bubbles. In fact, agent answers are no bubbles at all - they appear as regular text (similar to the chat in VS code) - only user messages are styled as bubbles.

Since most facades use the Markian Viewer to render markdown, the `AIChat` widget also uses it. This ensures, markdown rendered there looks similar to that in `Markdown` widgets and in `DocsFacade`.

## Facade integration

The widget is supposed to work with any facade based on the AbstractAjaxFacade using the `InputCustom` widget as base. Facades may include some special styling for `AIChat` (it is an optional widget, that may explicitly support), but the widget itself must not have facade specific features.

## Conversation toolbar

The toolbar shows **New conversation** and **Rate** by default. Starting a new conversation clears the current chat without deleting the saved conversation. Once the chat has a saved conversation, **Rate** opens its rating dialog. Open it again to edit the saved rating and feedback.

Set `allow_to_start_new_conversation` or `allow_to_rate_conversation` to `false` to hide the corresponding button. Use `toolbars` to add other native facade buttons.