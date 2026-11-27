# AIChat-Widget

[English](../../../Development/AIChat_widget.md)

Mit dem Widget `AIChat` können Benutzer direkt auf einer Seite oder in einem Dialog mit einem KI-Agenten chatten. Das Widget basiert auf Deep Chat und verwendet Markian zur Darstellung von Markdown. Benutzernachrichten erscheinen in Sprechblasen, Antworten des Agenten als normaler Text.

Mit `intro_message` zeigen Sie zu Beginn jeder neuen Unterhaltung einen Markdown-Hinweis an.

## Integration in Fassaden

Das Widget basiert auf `InputCustom` und enthält keine fassadenspezifischen Bedienelemente. Die Fassade rendert die Symbolleiste mit ihren Standard-Buttons.

## Symbolleiste

Standardmäßig zeigt die Symbolleiste **New conversation** und **Rate**. Eine neue Unterhaltung leert den Chat, löscht aber nicht die gespeicherte Unterhaltung. Sobald die Unterhaltung gespeichert wurde, öffnet **Rate** den Bewertungsdialog. Beim erneuten Öffnen können Benutzer ihre gespeicherte Bewertung und ihr Feedback bearbeiten.

Mit `allow_to_start_new_conversation` oder `allow_to_rate_conversation` auf `false` lässt sich der jeweilige Button ausblenden. Über `toolbars` lassen sich weitere Standard-Buttons hinzufügen.
