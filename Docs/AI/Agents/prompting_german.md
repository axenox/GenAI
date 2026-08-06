# Tipps und Tricks für Agent-Prompts

[English](prompting.md)

Diese Hinweise ergänzen die allgemeine Beschreibung von Agentenversionen, ohne die Grundlagen aus der Übersicht zu wiederholen.

## Mit minimalem Kontext beginnen

Ein kurzer gemeinsamer Kontext ist oft wertvoller als ein langer Dokumentationsblock. Der Agent benötigt zunächst die Terminologie, Zielgruppe und Grenzen seiner Umgebung. Details kann er später bei Bedarf laden.

Mehr Tiefe ist nicht automatisch besser. Eine kleine, gut verlinkte Startseite kann als Teil des initialen Kontexts gerendert werden. Für größere Bereiche ist eine knappe Einführung in Verbindung mit bedarfsgesteuertem Laden meist robuster.

## Concepts als Hintergrundkontext

Gute Concepts stellen Informationen bereit, die der Agent fast immer benötigt, ohne dass Benutzer sie angeben müssen. Dazu gehören stabile Hintergrundinformationen, relevante Regeln, bestehende Strukturen oder aktueller Kontext aus der Benutzeroberfläche.

Ein guter Platzhaltername verdeutlicht, warum der Abschnitt vorhanden ist. Mehrdeutige Namen können Kontext offenlegen, ohne ihn nutzbar zu machen.

## Dokumentation zur Orientierung, Tools für Details

Gerenderte Dokumentation eignet sich zur Orientierung, ein Dokumentations-Tool für Details. Zusammen vermeiden sie zwei Extreme: zu wenig Kontext im Prompt und zu viel vollständige Dokumentation auf einmal.

Links sollten als Pfade verstanden werden, denen gefolgt werden kann, und nicht als etwas, das das Modell erraten soll. Erscheint ein sichtbarer Link für die Antwort relevant, sollte der Agent ihm folgen dürfen.

## Tools benötigen einen eindeutigen Zweck

Ein Tool wird mit höherer Wahrscheinlichkeit verwendet, wenn der Prompt seinen Zweck beschreibt. Das Modell sollte erkennen können, welche Art von Unsicherheit sich mit dem Tool verringern lässt.

Die Tool-Beschreibung sollte daher weniger wie eine technische Bezeichnung und mehr wie eine Entscheidungshilfe formuliert sein: Was kann das Tool überprüfen, welche Eingaben sind zulässig und wann ist sein Ergebnis besser als eine Vermutung?

## Sichtbare Tools kurz erläutern

Eine automatisch gerenderte Tool-Übersicht ist hilfreich, ersetzt aber keine fachspezifischen Hinweise dazu, wie ein Tool in den Workflow passt. Eine kurze Regel im Prompt genügt oft: vor dem Ableiten prüfen, vor dem Behaupten lesen und vor dem Speichern Kontext laden.

## Kontext nach Stabilität strukturieren

Stabile Informationen gehören in Concepts, veränderliche Informationen in Tools. Dadurch bleibt der Prompt klein, ohne dass der Agent im Blindflug arbeiten muss.

Diese Trennung macht Antworten robuster. Der Agent beginnt mit ausreichend Kontext zur Orientierung und bleibt zugleich flexibel, wenn eine Frage mehr Details erfordert.