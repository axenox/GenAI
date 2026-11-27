# AIChat widget

The app includes a special chat widget called `AIChat`. It is facade agnostic chat widget build with the DeepChat library.

## Look & feel

Our chat widget should use the space available to it efficiently. We decreased padding and increased the width of speech bubbles. In fact, agent answers are no bubbles at all - they appear as regular text (similar to the chat in VS code) - only user messages are styled as bubbles.

Since most facades use the Markian Viewer to render markdown, the `AIChat` widget also uses it. This ensures, markdown rendered there looks similar to that in `Markdown` widgets and in `DocsFacade`.

## Facade integration

The widget is supposed to work with any facade based on the AbstractAjaxFacade using the `InputCustom` widget as base. Facades may include some special styling for `AIChat` (it is an optional widget, that may explicitly support), but the widget itself must not have facade specific features.