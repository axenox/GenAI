# Actions

[English](index.md)

## Call Agent

Die Action `axenox.GenAI.CallAgent` sendet ihr Input-DataSheet an einen konfigurierten KI-Agenten und gibt die Antwort als reguläre, aus Markdown gerenderte Erfolgsnachricht zurück. Rohes HTML des Agenten wird vor dem Rendering escaped. Das DataSheet ist im Benutzer-Prompt immer als Markdown-Tabelle enthalten.

### UXON-Eigenschaften

| Eigenschaft | Erforderlich | Beschreibung |
| --- | --- | --- |
| `agent_alias` | Ja | Agent-Alias, optional mit Version, beispielsweise `axenox.GenAI.MyAgent:1.0`. |
| `additional_prompt` | Nein | Zusätzliche Anweisung vor oder nach der Markdown-Tabelle. |
| `additional_prompt_position` | Nein | `before_data_sheet` oder `after_data_sheet`. Standard ist `before_data_sheet`. |

Zusätzlichen Prompt vor dem DataSheet einfügen:

```json
{
  "alias": "axenox.GenAI.CallAgent",
  "agent_alias": "my.App.DataReviewer:1.0",
  "additional_prompt": "Prüfe die folgenden Datensätze auf Unstimmigkeiten.",
  "additional_prompt_position": "before_data_sheet"
}
```

Zusätzlichen Prompt nach dem DataSheet einfügen:

```json
{
  "alias": "axenox.GenAI.CallAgent",
  "agent_alias": "my.App.DataReviewer:1.0",
  "additional_prompt": "Fasse die oben dargestellten Datensätze zusammen.",
  "additional_prompt_position": "after_data_sheet"
}
```

Die Action erhält das Input-DataSheet zusätzlich im Kontext des KI-Prompts. Alle Input-Spalten und -Zeilen werden an den konfigurierten Agenten gesendet. Mit einem `input_mapper` sollten nur die benötigten Daten aufgenommen und sensible Felder ausgeschlossen werden.

## Run Test

Die Action `axenox.GenAI.RunTest` führt einen oder mehrere ausgewählte Datensätze des Objekts `axenox.GenAI.AI_TEST_CASE` als verzögerte Action aus. Sie ruft den im jeweiligen Testfall konfigurierten Agenten auf, speichert einen `AI_TEST_RUN`, wertet dessen Kriterien aus und speichert die Bewertungen. Die Testing-Seite verwendet dafür die gespeicherte Object-Action `axenox.GenAI.AiTestCaseRunTest`.

### UXON-Eigenschaften

| Eigenschaft | Erforderlich | Beschreibung |
| --- | --- | --- |
| `finish_message` | Nein | Abschlussmeldung. Standard ist `Testcase erfolgreich ausgeführt`. |
| `repetitions` | Nein | Anzahl der Testläufe. Standard ist `1`; zwischen Wiederholungen wartet die Action 60 Sekunden. |

```json
{
  "alias": "axenox.GenAI.RunTest",
  "finish_message": "Testlauf abgeschlossen",
  "repetitions": 1
}
```

Die Action benötigt mindestens eine Input-Zeile. Test-Prompts und der konfigurierte Kontext werden an den im Testfall ausgewählten Agenten gesendet. Fehler werden protokolliert und mit dem Testergebnis gespeichert, damit sie auf der Testing-Seite geprüft werden können.