# Tipps und Tricks fuer Agent-Prompts

Diese Hinweise ergaenzen die allgemeine Beschreibung zu Agent-Versionen, ohne die Grundlagen aus der Uebersicht zu wiederholen.

## Kontext klein starten

Ein kurzer gemeinsamer Kontext ist oft wertvoller als ein langer Dokumentationsblock. Der Agent braucht zuerst die Begriffe, Zielgruppe und Grenzen der Umgebung. Details kann er spaeter gezielt nachladen.

Mehr Tiefe ist nicht automatisch besser. Eine kleine, gut verlinkte Startseite darf etwas mitgerendert werden. Bei groesseren Bereichen ist ein knapper Einstieg plus Nachladen meist robuster.

## Concepts als Hintergrund

Gute Concepts liefern Material, das der Agent fast immer braucht, ohne dass der Benutzer daran denken muss. Dazu gehoeren stabile Einordnungen, relevante Regeln, vorhandene Strukturen oder aktueller Kontext aus der Oberflaeche.

Ein guter Platzhaltername sagt schon, warum der Abschnitt existiert. Unklare Namen machen Kontext zwar sichtbar, aber nicht unbedingt nuetzlich.

## Doku als Einstieg, Tools als Lupe

Gerenderte Doku ist gut fuer Orientierung. Ein Doku-Tool ist gut fuer Details. Zusammen vermeiden sie zwei Extreme: zu wenig Kontext im Prompt und zu viel komplette Dokumentation auf einmal.

Links sollten als Spuren behandelt werden, nicht als etwas, das das Modell erraten soll. Wenn ein Link sichtbar ist und fuer die Antwort wichtig wirkt, sollte der Agent ihn nachlesen duerfen.

## Tools brauchen einen Anlass

Ein Tool wird eher genutzt, wenn der Prompt den Anlass beschreibt. Das Modell sollte erkennen koennen, welche Art von Unsicherheit durch das Tool kleiner wird.

Die Tool-Beschreibung sollte deshalb weniger wie ein technisches Etikett klingen und mehr wie eine Entscheidungshilfe: Was kann damit geprueft werden, welche Eingabe ist erlaubt, und wann ist das Ergebnis besser als Raten?

## Sichtbare Tools kurz einordnen

Eine automatisch gerenderte Tool-Uebersicht hilft, ersetzt aber nicht den fachlichen Hinweis, wofuer ein Tool im Ablauf gedacht ist. Eine kurze Regel im Prompt reicht oft: erst pruefen, dann ableiten; erst nachlesen, dann behaupten; erst Kontext laden, dann speichern.

## Kontext nach Stabilitaet sortieren

Stabile Informationen gehoeren eher in Concepts. Veraenderliche Informationen gehoeren eher in Tools. Dadurch bleibt der Prompt klein, ohne dass der Agent blind arbeiten muss.

Diese Trennung macht Antworten robuster. Der Agent startet mit genug Orientierung, bleibt aber beweglich, wenn die eigentliche Frage Details braucht.
