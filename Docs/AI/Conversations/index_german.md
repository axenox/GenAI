# Conversations

[English](index.md)

Eine Conversation ist ein persistiertes Gespräch zwischen einem individuellen Teilnehmer und genau einer ausführenden KI. Der individuelle Teilnehmer ist typischerweise ein Benutzer, kann aber auch eine andere KI beziehungsweise ein orchestrierender Agent sein.

## Zuordnung zu Agent und Agent-Version

Jede Conversation gehört genau zu einem Agenten und genau einer Agent-Version. Diese Zuordnung ist nach dem Erstellen der Conversation unveränderlich. Dadurch bleiben System-Prompt, Tools, Modellkonfiguration und Antworten eindeutig der tatsächlich verwendeten Agent-Version zugeordnet.

Eine initialisierte Conversation besitzt **exakt einen System-Prompt**. Dieser wird beim ersten Lauf durch `AiAgentInterface::renderSystemPrompt()` als String gerendert und mit `saveSystemPrompt()` gespeichert. Danach ist er unveränderlich. Bei jedem weiteren Lauf wird ausschließlich der persistierte System-Prompt über `AiConversationInterface::getSystemPrompt()` geladen; der Agent rendert ihn nicht erneut.

Die Laufzeitinstanzen der zur Agent-Version gehörenden Tools dürfen für einen neuen Request erneut aufgebaut werden. Das ist keine erneute Prompt-Erzeugung und verändert den gespeicherten System-Prompt nicht.

Diese Invariante ist ein Grund dafür, dass eine Conversation nicht von mehreren Agenten oder Agent-Versionen verwendet werden darf: Ein anderer Agent hätte andere Instructions, Skills, Concepts oder Tool-Regeln, könnte den unveränderlichen System-Prompt der Conversation aber nicht ersetzen.

`AiFactory::createConversationFromPrompt()` entscheidet anhand des Prompts, ob eine neue Conversation erstellt oder eine bekannte Conversation wiederhergestellt wird. Für eine neue Conversation delegiert sie an `createConversation()`; Power UI erzeugt die UID beim Persistieren des Datensatzes. `createConversationFromUid()` stellt eine bekannte Conversation explizit wieder her. Die Conversation lädt ihre persistierte Zuordnung beim ersten Zugriff selbst und prüft dabei:

- die Conversation existiert und gehört zum angemeldeten Benutzer;
- die gespeicherte Agent-UID entspricht dem ausführenden Agenten;
- die gespeicherte Versionsnummer entspricht der ausführenden Agent-Version;
- die zugehörige Agent-Version besitzt eine gültige UID.

Bei einer falschen Agent- oder Versionszuordnung wird `AiConversationAgentVersionMismatchError` ausgelöst. Der Fehlerdialog enthält den Tab `Conversation assignment`, der die gespeicherte und die angeforderte Zuordnung gegenüberstellt oder eine ungültige persistierte Versionsrelation erklärt. Eine nicht vorhandene oder für den Benutzer nicht zugängliche Conversation führt weiterhin zu `AiConversationNotFoundError`.

Das `AiConversationInterface` stellt die Zuordnung bereit:

- `getAgent()` liefert den ausführenden `AiAgentInterface`;
- `getAgentVersionUID()` liefert die UID der exakt zugeordneten Agent-Version;
- `getTitle()` lädt den aktuellen Titel;
- `setTitle()` überschreibt den Titel;
- `getConversationId()` liefert die UID der Conversation.

`AiConversation` erhält im Konstruktor nur den `AiAgentInterface` und die Conversation-UID. Sie lädt Titel und Agent-Version-UID bei Bedarf aus ihrem persistierten Datensatz und speichert keinen aktuellen Prompt. Dadurch bleibt die persistierte Conversation unabhängig vom einzelnen Request und von konkreten Agent-Implementierungen.

Eine neue Conversation besitzt zunächst einen leeren Titel. Beim Speichern des ersten User-Prompts erzeugt `saveUserPrompt()` daraus den initialen Titel. Ein expliziter Aufruf von `setTitle()` überschreibt den vorhandenen Titel jederzeit.

## Nachrichten

Jede gespeicherte Nachricht besteht fachlich aus einem Content-String und strukturierten Daten. Der Content wird in `AI_MESSAGE.MESSAGE`, die zusätzlichen Daten werden serialisiert in `AI_MESSAGE.DATA` gespeichert. Rolle, Benutzer, Modell und Sequenznummer ergänzen den Datensatz.

`saveSystemPrompt()` und `saveUserPrompt()` erhalten Content und Data direkt. Die spezialisierten Methoden für Tool-Aufrufe, Tool-Antworten, Agent-Antworten, Warnungen und Fehler erzeugen dieselben beiden Bestandteile aus ihren jeweiligen Laufzeitobjekten.

`saveSystemPrompt()` ignoriert weitere Speicherversuche, sobald ein System-Prompt vorhanden ist. Alle weiteren Nachrichten erhalten eine fortlaufende Sequenznummer.

Die nächste Sequenznummer gehört zum Zustand der Conversation. Ihr interner Wert beginnt mit `null`. `getSequenceNumber()` lädt beim ersten Zugriff die höchste persistierte `SEQUENCE_NUMBER` und liefert deren Nachfolger; spätere Zugriffe verwenden den gecachten Wert. Beim Speichern liefert `incrementSequenceNumber()` die aktuelle Nummer und erhöht anschließend den lokalen Zähler. Die Factory muss die Sequenz daher weder laden noch an den Konstruktor übergeben.

## Multi-Agent-Abläufe

Eine einzelne Conversation darf nicht von mehreren Agenten oder Agent-Versionen gemeinsam verwendet werden. Ein orchestrierter Ablauf erzeugt deshalb für jeden beteiligten Agenten eine eigene Conversation und verbindet diese über den übergeordneten Workflow oder die Timeline.
//TODO
