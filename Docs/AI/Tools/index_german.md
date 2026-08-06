# Referenz der Agenten-Tools

[English](index.md)

Tools ermöglichen es einem Agenten, während der Verarbeitung einer Anfrage Informationen abzurufen oder eine klar begrenzte Aktion auszuführen. Anders als Concepts werden Tools beim Erstellen des Prompts nicht automatisch ausgewertet. Das Modell entscheidet anhand der aktuellen Frage, der Tool-Beschreibung und der Anweisungen des Agenten, ob es sie aufruft.

Verwenden Sie ein Tool für Informationen, die zu detailliert, zu veränderlich oder zu aufwendig sind, um sie in jeden Prompt aufzunehmen. Ein gutes Tool besitzt genau eine klar definierte Aufgabe, eng begrenzte Berechtigungen und eine Beschreibung, aus der das Modell erkennt, welche Unsicherheit der Aufruf beseitigen kann. Diese Seite dokumentiert alle derzeit von `axenox.GenAI` bereitgestellten Tool-Prototypen und erläutert, wann und wie sie verwendet werden.

## Ein Tool auswählen

| Anforderung | Empfohlenes Tool |
| --- | --- |
| Eine bekannte Datei untersuchen | `FileReadTool` |
| Dateien oder Text finden, wenn der Speicherort unbekannt ist | `FileSearchTool` |
| Eine Verzeichnisstruktur verstehen | `FolderReadTool` |
| Einen kleinen Teil einer bestehenden Datei ändern | `FilePatchTool` |
| Eine Datei erstellen oder vollständig ersetzen | `FileWriteTool` |
| Einen streng kontrollierten lokalen Befehl ausführen | `CommandLineTool` |
| ExFace-Objektdaten lesen oder speichern | `DataSheetReadTool` oder `DataSheetImportTool` |
| ExFace-Dokumentation lesen | `GetDocsTool` |
| Modell- oder UXON-Metadaten untersuchen | Eines der `Model*InfoTool`-Tools |
| Eine konkrete Seiten- oder Widget-Instanz untersuchen | `UiWidgetInfoTool` |
| Deterministische Testausgaben bereitstellen | `MockTool` |

## Allgemeine Konfiguration

Jedes Tool wird unter `tools` in `CONFIG_UXON` konfiguriert. Der Objektschlüssel wird zu dem für das Modell sichtbaren Funktionsnamen. Wählen Sie einen kurzen, aktionsorientierten Namen, der das Ergebnis beschreibt, beispielsweise `ReadObjectData` oder `FindSourceFile`.

Eine Definition wählt ihren Prototyp über `alias` oder `class` aus und kann die erzeugten Werte für `name`, `description` und `arguments` überschreiben. Bevorzugen Sie einen Alias, da dieser unabhängig vom PHP-Namespace bleibt. Die Beschreibung sollte erläutern, wann das Modell das Tool aufrufen soll, statt lediglich seinen Namen zu wiederholen.

```json
{
  "tools": {
    "GetObject": {
      "alias": "axenox.GenAI.ModelObjectInfoTool",
      "description": "Find and describe a metaobject.",
      "arguments": [
        {
          "name": "search_term",
          "data_type": { "alias": "exface.Core.String" },
          "description": "Object UID, alias, or name"
        }
      ]
    }
  }
}
```

Werden `arguments` weggelassen, kommen die integrierten Argumentvorlagen zum Einsatz. Überschreiben Sie diese nur, wenn der Agent eine spezifischere Terminologie, Beispiele oder ein eingeschränktes Schema benötigt. Die Tool-Anweisungen sollten außerdem angeben, wann ein Aufruf verpflichtend ist, zum Beispiel: „Lesen Sie die Objektdefinition, bevor Sie UXON vorschlagen, das auf dessen Attribute verweist.“

## Dateizugriff konfigurieren

`CommandLineTool`, `FileReadTool`, `FileWriteTool`, `FilePatchTool`, `FolderReadTool` und `FileSearchTool` verwenden gemeinsam die folgenden Eigenschaften:

| Eigenschaft | Standard | Beschreibung |
| --- | --- | --- |
| `base_path` | Vendor-Verzeichnis | Basisverzeichnis für relative Pfade. |
| `use_vendor_folder_as_base` | `true` | Verwendet standardmäßig das ExFace-Vendor-Verzeichnis als Basis; bei `false` wird das Basisverzeichnis der Workbench verwendet. Relative Werte für `base_path` werden unterhalb der ausgewählten Standardbasis aufgelöst. |
| `allowed_paths` | Innerhalb des Basispfads uneingeschränkt | Glob-ähnliche Positivliste für zugängliche Pfade. Verwenden Sie die engstmöglichen Pfade, die der Agent benötigt. |

Pfade werden vor dem Zugriff gegen die konfigurierte Basis und Positivliste validiert. Dadurch kann ein relativer Pfad den zulässigen Bereich nicht verlassen. Konfigurieren Sie für produktive Agenten immer `allowed_paths`. Gewähren Sie nur Zugriff auf den kleinsten Verzeichnisbaum, der die Aufgabe unterstützt; eng begrenzter Zugriff erhöht die Sicherheit und reduziert zugleich unnötige Festplattenzugriffe und Prompt-Inhalte.

## `CommandLineTool`

**Alias:** `axenox.GenAI.CommandLineTool` | [UXON-Prototyp](api/docs/exface/Core/Docs/UXON/UXON_prototypes.md?selector=%5Caxenox%5CGenAI%5CAI%5CTools%5CCommandLineTool)

**Zweck.** Führt einen Befehl in einem validierten Arbeitsverzeichnis aus und gibt die Konsolenausgabe als Markdown zurück.

**Verwenden, wenn.** Der Agent einen Diagnose-, Validierungs-, Test- oder Build-Befehl ausführen muss und kein spezialisiertes Tool denselben Vorgang bereitstellt. Typische Beispiele sind Syntaxprüfungen oder ein eng eingegrenzter Testbefehl.

**Nicht verwenden, wenn.** Stellen Sie keine universell einsetzbare Shell für routinemäßige Geschäftsprozesse, uneingeschränkte Dateisystemerkundung oder destruktive Administration bereit. Bevorzugen Sie ein spezialisiertes Tool, sobald der Vorgang einen stabilen Ein- und Ausgabevertrag besitzt.

| UXON-Eigenschaft | Standard | Beschreibung |
| --- | --- | --- |
| `allowed_commands` | `[]` | Exakte Befehle oder reguläre Ausdrucksmuster, die ausgeführt werden dürfen. |
| `blocked_commands` | `[]` | Befehle oder Muster, die nicht ausgeführt werden dürfen. Eine Sperrregel hat Vorrang vor einer Freigaberegel. |
| `command_timeout` | `60` | Maximale Ausführungszeit in Sekunden. |

| Argument | Erforderlich | Beschreibung |
| --- | --- | --- |
| `command` | Ja | Auszuführende Befehlszeile. |
| `folder` | Nein | Arbeitsverzeichnis relativ zum konfigurierten Basispfad. |

**Verwendung.** Konfigurieren Sie eine explizite Liste `allowed_commands`, eine defensive Liste `blocked_commands` und eng begrenzte Dateizugriffseinstellungen. Das Modell übergibt den vollständigen Befehl und optional ein Arbeitsverzeichnis. Sperrregeln haben Vorrang vor Freigaberegeln; eine leere Positivliste erlaubt ansonsten jeden nicht ausdrücklich gesperrten Befehl.

**Ergebnis und Grenzen.** Das Tool gibt die erfasste Konsolenausgabe in einem Markdown-Codeblock zurück. Ungültige Befehle, abgelehnte Verzeichnisse, Fehler und Zeitüberschreitungen führen zu einem Tool-Fehler. Begrenzen Sie die Ausführungszeit und verlassen Sie sich niemals darauf, dass das Modell entscheidet, ob ein uneingeschränkter Befehl sicher ist.

## `FileReadTool`

**Alias:** `axenox.GenAI.FileReadTool` | [UXON-Prototyp](api/docs/exface/Core/Docs/UXON/UXON_prototypes.md?selector=%5Caxenox%5CGenAI%5CAI%5CTools%5CFileReadTool)

**Zweck.** Liest eine bekannte Textdatei und gibt ihren Inhalt mit Dateimetadaten und sprachspezifischer Markdown-Formatierung zurück.

**Verwenden, wenn.** Der Agent bereits weiß, welche Quelldatei, Konfiguration oder welches Dokument die benötigten Details enthält. Dieses Tool ist die bevorzugte Wahl, um eine exakte Implementierung oder Konfiguration zu überprüfen, bevor eine Aussage getroffen oder eine Änderung vorgenommen wird.

**Nicht verwenden, wenn.** Ist der Pfad unbekannt, verwenden Sie zuerst `FileSearchTool` oder `FolderReadTool`. Lesen Sie keine vollständige große Datei, wenn ein relevanter Zeilenbereich ausreicht.

| UXON-Eigenschaft | Standard | Beschreibung |
| --- | --- | --- |
| `include_instructions_for_github_copilot` | `true` | Hängt den Inhalt der für die angeforderte Datei geltenden Dateien `.github/instructions/*.instructions.md` an. |

| Argument | Erforderlich | Beschreibung |
| --- | --- | --- |
| `path` | Ja | Dateipfad relativ zum konfigurierten Basispfad. |
| `start_with_line` | Nein | Erste zurückzugebende Zeile bei einsbasierter Zeilennummerierung. |
| `max_lines` | Nein | Maximale Anzahl zurückzugebender Zeilen. |

**Verwendung.** Beschränken Sie die zugänglichen Pfade in der Tool-Konfiguration. Das Modell übergibt einen relativen `path` und kann mit `start_with_line` und `max_lines` seitenweise lesen. Lassen Sie `include_instructions_for_github_copilot` aktiviert, wenn einschlägige Repository-Anweisungen gemeinsam mit Quelldateien bereitgestellt werden sollen.

**Ergebnis und Grenzen.** Das Tool gibt den ausgewählten Inhalt als Markdown zurück. Fehlende, nicht lesbare oder nicht erlaubte Dateien führen zu einem Fehler. Die seitengestützte Ausgabe verhindert, dass große Dateien übermäßig viel Kontext belegen.

## `FileWriteTool`

**Alias:** `axenox.GenAI.FileWriteTool` | [UXON-Prototyp](api/docs/exface/Core/Docs/UXON/UXON_prototypes.md?selector=%5Caxenox%5CGenAI%5CAI%5CTools%5CFileWriteTool)

**Zweck.** Erstellt eine neue Datei oder ersetzt eine bestehende Datei innerhalb des zulässigen Pfadbereichs vollständig.

**Verwenden, wenn.** Der vollständige Zielinhalt bekannt ist, beispielsweise für ein neu erzeugtes Artefakt oder eine bewusst vollständig ersetzte kleine Datei.

**Nicht verwenden, wenn.** Verwenden Sie es nicht für eine kleine Änderung an einer bestehenden Datei, da unveränderter Inhalt verloren gehen kann. Nutzen Sie `FilePatchTool` für gezielte Änderungen.

| Argument | Erforderlich | Beschreibung |
| --- | --- | --- |
| `path` | Ja | Zielpfad relativ zum konfigurierten Basispfad. |
| `content` | Ja | Vollständiger zu schreibender Inhalt. |

**Verwendung.** Konfigurieren Sie eine eng begrenzte Liste `allowed_paths`. Das Modell sendet den relativen Pfad und den vollständigen endgültigen Inhalt in einem Aufruf.

**Ergebnis und Grenzen.** Das Tool gibt eine einfache Statusmeldung zurück. Bestehender Inhalt wird überschrieben und nicht zusammengeführt; Schreibfehler oder Fehler bei der Pfadvalidierung führen zu einem Fehler.

## `FilePatchTool`

**Alias:** `axenox.GenAI.FilePatchTool` | [UXON-Prototyp](api/docs/exface/Core/Docs/UXON/UXON_prototypes.md?selector=%5Caxenox%5CGenAI%5CAI%5CTools%5CFilePatchTool)

**Zweck.** Wendet einen oder mehrere exakte `SEARCH`/`REPLACE`-Blöcke auf eine Datei an, ohne nicht betroffene Inhalte neu zu schreiben.

**Verwenden, wenn.** Der Agent eine kleine, gut prüfbare Änderung an einer bestehenden Quell-, Konfigurations- oder Dokumentationsdatei vornehmen muss. Dies ist sicherer und effizienter, als die vollständige Datei mit `FileWriteTool` zu übertragen.

**Nicht verwenden, wenn.** Verwenden Sie es nicht, wenn der ursprüngliche Text unbekannt oder mehrdeutig ist. Lesen Sie zuerst den relevanten Dateiinhalt, damit der Suchblock exakt übereinstimmen kann.

| Argument | Erforderlich | Beschreibung |
| --- | --- | --- |
| `path` | Ja | Zielpfad relativ zum konfigurierten Basispfad. |
| `patch` | Ja | Patch mit exakten Such- und Ersetzungsblöcken. |

```text
<<<<<<< SEARCH
exact text, including whitespace
=======
replacement text
>>>>>>> REPLACE
```

**Verwendung.** Das Modell übergibt einen relativen Pfad und einen oder mehrere Patch-Blöcke. Beim Suchtext wird zwischen Groß- und Kleinschreibung unterschieden und Leerraum exakt berücksichtigt. Daher sollte jeder Block aus der aktuellen Datei kopiert, klein genug für eine einfache Prüfung und zugleich eindeutig genug zur Identifikation genau einer Stelle sein. Ein leerer Suchabschnitt kann eine Datei erstellen oder Inhalt anhängen.

**Ergebnis und Grenzen.** Die Blöcke werden der Reihe nach angewendet, wobei jeweils nur das erste Vorkommen des Suchtexts ersetzt wird. Fehlerhaft formatierte Blöcke und nicht gefundener Suchtext führen zu einem Fehler, statt eine Position zu erraten.

## `FolderReadTool`

**Alias:** `axenox.GenAI.FolderReadTool` | [UXON-Prototyp](api/docs/exface/Core/Docs/UXON/UXON_prototypes.md?selector=%5Caxenox%5CGenAI%5CAI%5CTools%5CFolderReadTool)

**Zweck.** Listet ein Verzeichnis als verschachtelten Markdown-Baum auf.

**Verwenden, wenn.** Der Agent einen schnellen strukturellen Überblick benötigt, bevor er zu lesende Dateien auswählt, beispielsweise beim Einstieg in eine unbekannte App oder bei der Suche nach einem wahrscheinlichen Implementierungsbereich.

**Nicht verwenden, wenn.** Listen Sie kein großes Paket rekursiv auf, nur um einen Dateinamen oder ein Textvorkommen zu finden. Für eine konkrete Suche ist `FileSearchTool` effizienter.

| UXON-Eigenschaft | Standard | Beschreibung |
| --- | --- | --- |
| `depth` | `0` | Maximale Rekursionstiefe; `0` bedeutet unbegrenzt. |
| `exclude_dot_paths` | `true` | Lässt Dateien und Verzeichnisse aus, deren Namen mit einem Punkt beginnen. |

| Argument | Erforderlich | Beschreibung |
| --- | --- | --- |
| `path` | Ja | Verzeichnis relativ zum konfigurierten Basispfad. |

**Verwendung.** Konfigurieren Sie einen eng begrenzten Basispfad und setzen Sie für große Verzeichnisbäume eine endliche `depth`. Das Modell übergibt den relativen Verzeichnispfad.

**Ergebnis und Grenzen.** Das Ergebnis ist eine verschachtelte Markdown-Liste. Eine unbegrenzte Tiefe kann große Antworten und unnötige Festplattenzugriffe verursachen; Punktpfade werden standardmäßig ausgelassen.

## `FileSearchTool`

**Alias:** `axenox.GenAI.FileSearchTool` | [UXON-Prototyp](api/docs/exface/Core/Docs/UXON/UXON_prototypes.md?selector=%5Caxenox%5CGenAI%5CAI%5CTools%5CFileSearchTool)

**Zweck.** Findet Dateien anhand eines Verzeichnismusters und Dateinamens sowie optional anhand einer Textsuche oder eines regulären Ausdrucks innerhalb passender Dateien.

**Verwenden, wenn.** Der Agent weiß, wonach er sucht, aber nicht, in welcher Datei es sich befindet. Das Tool eignet sich, um eine Klasse, einen Konfigurationsschlüssel, einen Methodenaufruf oder eine Formulierung in der Dokumentation zu finden, bevor die relevanten Dateien gelesen werden.

**Nicht verwenden, wenn.** Ist die genaue Datei bereits bekannt, rufen Sie direkt `FileReadTool` auf. Vermeiden Sie breit angelegte rekursive Suchen als Ersatz für einen klaren Suchbegriff.

| UXON-Eigenschaft | Standard | Beschreibung |
| --- | --- | --- |
| `include_extract_line` | `true` | Nimmt bei einer Inhaltssuche Auszüge der passenden Zeilen in das Ergebnis auf. |

| Argument | Erforderlich | Beschreibung |
| --- | --- | --- |
| `path` | Ja | Zu durchsuchendes Verzeichnis oder Verzeichnismuster. |
| `name` | Nein | Glob-Muster für Dateinamen; standardmäßig alle Dateien. |
| `query` | Nein | Text oder regulärer Ausdruck, der in Dateiinhalten gesucht werden soll. |

**Verwendung.** Das Modell übergibt ein Verzeichnismuster, optional ein Glob-Muster für Dateinamen und optional eine Inhaltssuche. Ein einzelnes `*` entspricht einem Pfadsegment, während `**` mehrere Ebenen umfassen kann. Mit `include_extract_line` legen Sie fest, ob passende Zeilen ausgegeben werden.

**Ergebnis und Grenzen.** Das Ergebnis listet passende Pfade und bei Bedarf Auszüge passender Zeilen auf. Vermeiden Sie eine unbegrenzte `**`-Suche ab dem Vendor-Stammverzeichnis. Engere Pfade reduzieren Ausführungszeit, Festplattenzugriffe und Antwortgröße.

## `DataSheetReadTool`

**Alias:** `axenox.GenAI.DataSheetReadTool` | [UXON-Prototyp](api/docs/exface/Core/Docs/UXON/UXON_prototypes.md?selector=%5Caxenox%5CGenAI%5CAI%5CTools%5CDataSheetReadTool)

**Zweck.** Liest ExFace-Objektdaten mithilfe einer DataSheet-UXON-Abfrage.

**Verwenden, wenn.** Der Agent aktuelle Geschäfts- oder Modelldaten benötigt und das Zielobjekt sowie die relevanten Attribute bereits kennt. Die Abfrage kann Spalten auswählen, Zeilen filtern, Ergebnisse sortieren oder aggregieren und große Ergebnismengen seitenweise abrufen.

**Nicht verwenden, wenn.** Erraten Sie keine Objekt- oder Attributaliasse. Ermitteln und überprüfen Sie diese zuerst mit `ModelObjectInfoTool`. Fordern Sie keine vollständigen Objekte oder unbegrenzten Zeilenmengen an, wenn nur wenige Felder oder Datensätze benötigt werden.

| Argument | Erforderlich | Beschreibung |
| --- | --- | --- |
| `data_sheet` | Ja | DataSheet-UXON-Objekt, das die Abfrage beschreibt. |

```json
{
  "object_alias": "exface.Core.OBJECT",
  "columns": [
    { "name": "ALIAS", "attribute_alias": "ALIAS" },
    { "name": "NAME", "attribute_alias": "NAME" }
  ],
  "filters": {
    "operator": "AND",
    "conditions": [
      { "expression": "ALIAS", "comparator": "[", "value": "exface.Core" }
    ]
  },
  "rows_limit": 25,
  "rows_offset": 0
}
```

**Verwendung.** Das Modell übergibt ein DataSheet-UXON-Objekt mit `object_alias`, den ausgewählten `columns` und optional `filters`, `sorters`, `aggregators`, `rows_limit` und `rows_offset`. Die Anweisungen des Agenten sollten für Objekte, die viele Datensätze enthalten können, ein angemessenes Zeilenlimit vorschreiben.

**Ergebnis und Grenzen.** Das Ergebnis enthält JSON-formatierte Zeilen und Metadaten in Markdown. Es gelten die üblichen ExFace-Objektberechtigungen und DataSheet-Lesebeschränkungen. Ungültige Objektaliasse, Ausdrücke oder Filter führen zu Fehlern.

## `DataSheetImportTool`

**Alias:** `axenox.GenAI.DataSheetImportTool` | [UXON-Prototyp](api/docs/exface/Core/Docs/UXON/UXON_prototypes.md?selector=%5Caxenox%5CGenAI%5CAI%5CTools%5CDataSheetImportTool)

**Zweck.** Validiert und speichert eine oder mehrere DataSheet-Nutzlasten in explizit konfigurierten ExFace-Objekten.

**Verwenden, wenn.** Der Agent strukturierte Geschäftsdaten erstellen oder aktualisieren muss und die zulässigen Ziele und Felder vorab definiert werden können. Das Tool eignet sich für begrenzte Workflows, beispielsweise um ein geprüftes Ergebnis zu erfassen oder einen Datensatz eines bekannten Typs zu erstellen.

**Nicht verwenden, wenn.** Stellen Sie keinen uneingeschränkten Schreibzugriff bereit und erlauben Sie dem Modell nicht, beliebige Objekte und Attribute auszuwählen. Verwenden Sie ein Lese-Tool, wenn keine dauerhafte Änderung erforderlich ist.

| UXON-Eigenschaft | Beschreibung |
| --- | --- |
| `save_as` | Definiert ein zulässiges Ziel-DataSheet-Schema. |
| `data_schemas` | Definiert mehrere zulässige Zielschemata. Jedes Schema kann ein Objekt, Spalten und Sub-Sheets angeben. |

| Argument | Erforderlich | Beschreibung |
| --- | --- | --- |
| `data_sheet` | Ja | Ein DataSheet-UXON-Objekt oder ein Array aus DataSheet-Objekten. |

**Verwendung.** Konfigurieren Sie entweder `save_as` für ein einzelnes Zielschema oder `data_schemas` für mehrere zulässige Schemata. Beschränken Sie jedes Schema auf genau die Objekte, Spalten und Sub-Sheets, die der Agent ändern darf. Das Modell übergibt anschließend ein passendes DataSheet-Objekt oder ein Array von Objekten.

**Ergebnis und Grenzen.** Das Tool verwendet den regulären DataSheet-Speichervorgang und gibt die Anzahl importierter Zeilen zurück. ExFace-Autorisierung und -Validierung bleiben aktiv. Ungültige Zeilen werden, sofern die Verarbeitung fortgesetzt werden kann, als Exceptions gemeldet; kritische Fehler brechen den Import ab.

## `GetTimeTool`

**Alias:** `axenox.GenAI.GetTimeTool` | [UXON-Prototyp](api/docs/exface/Core/Docs/UXON/UXON_prototypes.md?selector=%5Caxenox%5CGenAI%5CAI%5CTools%5CGetTimeTool)

**Zweck.** Gibt das aktuelle Serverdatum und die aktuelle Serverzeit als ExFace-Datums-/Zeitwert zurück.

**Verwenden, wenn.** Die Antwort von „jetzt“, einem relativen Datum, einer Frist oder einer Planungslogik abhängt. Durch den Aufruf des Tools wird vermieden, veralteten Modellkontext oder eine Client-Uhr in einer anderen Zeitzone zu verwenden.

**Verwendung.** Stellen Sie das Tool ohne prototypspezifische Konfiguration oder Argumente bereit. Das Ergebnis ist der aktuelle Serverwert; die Anweisungen des Agenten sollten die relevante Zeitzone erläutern, wenn Benutzer den Wert unterschiedlich interpretieren könnten.

## `GetDocsTool`

**Alias:** `axenox.GenAI.GetDocsTool` | [UXON-Prototyp](api/docs/exface/Core/Docs/UXON/UXON_prototypes.md?selector=%5Caxenox%5CGenAI%5CAI%5CTools%5CGetDocsTool)

**Zweck.** Lädt über die Dokumentations-Facade eine ExFace-Dokumentationsseite als Markdown.

**Verwenden, wenn.** Dem Agenten ein Dokumentationslink oder eine URI vorliegt und er vor dem Antworten die detaillierte Seite benötigt. Das Tool ergänzt `AppDocsConcept`: Das Concept stellt eine kompakte Übersicht bereit, während dieses Tool nur den Links folgt, die für die aktuelle Frage relevant sind.

**Nicht verwenden, wenn.** Laden Sie nicht durch wiederholte Aufrufe einen vollständigen Dokumentationsbaum vorab. Lesen Sie die kleinstmögliche Seite, die die Frage beantworten kann.

| Argument | Erforderlich | Beschreibung |
| --- | --- | --- |
| `uri` | Ja | Dokumentations-URI, üblicherweise unterhalb von `api/docs`. |

**Verwendung.** Das Modell übergibt eine lokale Dokumentations-URI, die mit `api/docs` beginnt. `AppDocsConcept` schlägt dem Agenten dieses Tool automatisch vor. Es muss daher nicht erneut konfiguriert werden, sofern Name oder Beschreibung nicht angepasst werden sollen.

**Ergebnis und Grenzen.** Das Ergebnis ist Markdown. PHP-Ziele werden mit dem Code-Markdown-Printer gerendert; andere Ziele werden von der Dokumentations-Facade aufgelöst. Absolute HTTPS-URLs, die in der integrierten Argumentvorlage erwähnt werden, werden derzeit von der Sicherheitsprüfung nicht akzeptiert.

## `GetLogEntryTool`

**Alias:** `axenox.GenAI.GetLogEntryTool` | [UXON-Prototyp](api/docs/exface/Core/Docs/UXON/UXON_prototypes.md?selector=%5Caxenox%5CGenAI%5CAI%5CTools%5CGetLogEntryTool)

**Zweck.** Lädt einen ExFace-Protokolleintrag und formatiert seine Details als Markdown.

**Verwenden, wenn.** Ein Support- oder Diagnoseagent eine bekannte Log-ID erklären, die zugehörige Exception untersuchen oder konkrete Laufzeitinformationen nutzen muss, um einen Fehler zu identifizieren.

**Nicht verwenden, wenn.** Stellen Sie es Agenten ohne betrieblichen Support-Anwendungsfall nicht bereit. Protokolldaten können interne Pfade, Benutzerdaten, Anfragewerte oder andere vertrauliche Details enthalten.

| Argument | Erforderlich | Beschreibung |
| --- | --- | --- |
| `LogId` | Ja | Von der Anwendung angezeigte Kennung des Protokolleintrags. |
| `LogFilePath` | Nein | Pfad der Protokolldatei relativ zur Installation. |

**Verwendung.** Das Modell übergibt die sichtbare `LogId` und nur bei Bedarf einen relativ zur Installation angegebenen Protokolldateipfad. Die Anweisungen des Agenten sollten das Modell verpflichten, den Eintrag vor der Diagnose zu lesen und keine nicht relevanten vertraulichen Werte wiederzugeben.

**Ergebnis und Grenzen.** `LogEntryMarkdownPrinter` erzeugt das Markdown-Ergebnis. Der Zugriff sollte auf Agenten und Benutzer mit einer geeigneten Support-Rolle beschränkt werden.

## `GetPrintPreviewTool`

**Alias:** `axenox.GenAI.GetPrintPreviewTool` | [UXON-Prototyp](api/docs/exface/Core/Docs/UXON/UXON_prototypes.md?selector=%5Caxenox%5CGenAI%5CAI%5CTools%5CGetPrintPreviewTool)

**Zweck.** Führt eine konfigurierte Druckaktion aus und gibt die gerenderte Dokumentvorschau als HTML zurück.

**Verwenden, wenn.** Der Agent den tatsächlichen Inhalt einer Rechnung, eines Berichts, Etiketts oder anderen Dokuments untersuchen muss, bevor er ihn zusammenfasst, prüft oder erörtert.

**Nicht verwenden, wenn.** Verwenden Sie es nicht lediglich zum Lesen von Objektattributen, die über `DataSheetReadTool` verfügbar sind. Das Rendern ist aufwendiger und kann den vollständigen Dokumentinhalt offenlegen.

| UXON-Eigenschaft | Standard | Beschreibung |
| --- | --- | --- |
| `print_action` | Erforderlich | Alias der auszuführenden Druckaktion. |
| `print_data` | Optional | DataSheet-UXON, das als Eingabe für die Aktion verwendet wird. |
| `cache_previews` | `false` | Verwendet Vorschauen erneut, solange die relevanten Eingabezeilen unverändert bleiben. |

**Verwendung.** Konfigurieren Sie eine druckfähige `print_action` und eine eng gefilterte `print_data`-Vorlage. Mit `[#~argument:0#]`, `[#~argument:1#]` und den nachfolgenden Indizes fügen Sie Tool-Argumente ein. Bei einem Aufruf durch `ToolCallConcept` kann `[#~input:FIELD#]` ein Feld aus der ersten Eingabezeile lesen. Aktivieren Sie das Caching nur, wenn die Wiederverwendung von Vorschauen für die zugrunde liegenden Daten angemessen ist.

**Ergebnis und Grenzen.** Das Ergebnis ist der HTML-Body der Vorschau. Die konfigurierte Aktion muss das Rendern einer Vorschau unterstützen; die üblichen Aktionsberechtigungen bleiben wirksam.

## `ModelObjectInfoTool`

**Alias:** `axenox.GenAI.ModelObjectInfoTool` | [UXON-Prototyp](api/docs/exface/Core/Docs/UXON/UXON_prototypes.md?selector=%5Caxenox%5CGenAI%5CAI%5CTools%5CModelObjectInfoTool)

**Zweck.** Findet ExFace-Metaobjekte und gibt ihre Modelldokumentation zurück.

**Verwenden, wenn.** Der Agent einen Objektalias ermitteln, verfügbare Attribute und Relationen überprüfen oder ein Objekt verstehen muss, bevor er DataSheet-Abfragen oder UXON erstellt.

**Nicht verwenden, wenn.** Sind der genaue Typ und Selektor einer Komponente bekannt, die kein Objekt ist, führt `ModelComponentInfoTool` direkter zum Ziel.

| Argument | Erforderlich | Beschreibung |
| --- | --- | --- |
| `search_term` | Ja | Objekt-UID, vollständig qualifizierter Alias, Teilalias oder Name. |

**Verwendung.** Das Modell übergibt eine UID, einen vollständig qualifizierten Alias, einen Teilalias oder einen menschenlesbaren Namen. Werte, die mit `0x` beginnen, werden als UIDs behandelt. Wahrscheinliche vollständige Aliasse werden exakt abgeglichen; andere Werte durchsuchen Objektnamen und -aliasse.

**Ergebnis und Grenzen.** Exakte Treffer werden zuerst ausgegeben, gefolgt von erzeugtem Markdown für jeden Treffer. Allgemeine Begriffe können mehrere Objekte zurückgeben; verwenden Sie daher den spezifischsten bekannten Selektor.

## `ModelComponentInfoTool`

**Alias:** `axenox.GenAI.ModelComponentInfoTool` | [UXON-Prototyp](api/docs/exface/Core/Docs/UXON/UXON_prototypes.md?selector=%5Caxenox%5CGenAI%5CAI%5CTools%5CModelComponentInfoTool)

**Zweck.** Gibt Registry-Dokumentation für eine bekannte ExFace-Modellkomponente zurück, beispielsweise eine Aktion, ein Objekt oder eine Seite.

**Verwenden, wenn.** Komponententyp und Selektor bereits bekannt sind und der Agent verbindliche Metadaten benötigt, bevor er die Komponente referenziert oder konfiguriert.

**Nicht verwenden, wenn.** Dieses Tool ist keine breit angelegte Ermittlungssuche. Verwenden Sie `ModelObjectInfoTool`, wenn ein Objekt noch identifiziert werden muss, oder ein spezialisiertes Widget-Tool für Widget-Details.

| Argument | Erforderlich | Beschreibung |
| --- | --- | --- |
| `component` | Ja | Komponententyp, beispielsweise `action`, `object` oder `page`. |
| `selector` | Ja | Alias oder Selektor der Komponente. |

**Verwendung.** Das Modell übergibt den Registry-Komponententyp und seinen Selektor. Verwenden Sie nach Möglichkeit kanonische Aliasse.

**Ergebnis und Grenzen.** Das Ergebnis ist die von der Komponenten-Registry zurückgegebene Dokumentation. Unbekannte Komponententypen oder Selektoren können nicht aufgelöst werden.

## `ModelUxonPrototypeTool`

**Alias:** `axenox.GenAI.ModelUxonPrototypeTool` | [UXON-Prototyp](api/docs/exface/Core/Docs/UXON/UXON_prototypes.md?selector=%5Caxenox%5CGenAI%5CAI%5CTools%5CModelUxonPrototypeTool)

**Zweck.** Erzeugt Dokumentation für die konfigurierbaren UXON-Eigenschaften eines PHP-Prototyps.

**Verwenden, wenn.** Der Agent UXON für eine Aktion, ein Widget, Behavior, einen Connector, Datentyp, ein Tool, Concept oder einen anderen Prototyp erstellen oder bearbeiten wird und zuvor verfügbare Eigenschaften, Typen, Standardwerte und Beschreibungen prüfen muss.

**Nicht verwenden, wenn.** Dieses Tool dokumentiert eine Prototypklasse, keine konkrete Seiteninstanz oder ein Metaobjekt. Verwenden Sie für diese Fälle `UiWidgetInfoTool` beziehungsweise `ModelObjectInfoTool`.

| Argument | Erforderlich | Beschreibung |
| --- | --- | --- |
| `selector` | Ja | PHP-Klassenname oder Pfad zur Prototypdatei. |

**Verwendung.** Das Modell übergibt entweder eine vollständig qualifizierte PHP-Klasse, die mit `\` beginnt, oder einen PHP-Dateipfad relativ zum Vendor-Verzeichnis. Aliasse werden derzeit nicht als Selektoren unterstützt.

**Ergebnis und Grenzen.** `UxonPrototypeMarkdownPrinter` gibt die Prototypbeschreibung und indizierte UXON-Eigenschaften zurück. Die Qualität des Ergebnisses hängt davon ab, ob die Annotationen des Prototyps im Modell verfügbar sind.

## `ModelWidgetTypeInfoTool`

**Alias:** `axenox.GenAI.ModelWidgetTypeInfoTool` | [UXON-Prototyp](api/docs/exface/Core/Docs/UXON/UXON_prototypes.md?selector=%5Caxenox%5CGenAI%5CAI%5CTools%5CModelWidgetTypeInfoTool)

**Zweck.** Dokumentiert einen Widget-Typ einschließlich seiner UXON-Eigenschaften, aufrufbaren Widget-Funktionen und Presets.

**Verwenden, wenn.** Der Agent Widget-UXON entwirft und Widget-spezifische Informationen benötigt, die über eine allgemeine Liste von Prototyp-Eigenschaften hinausgehen.

**Nicht verwenden, wenn.** Verwenden Sie es nicht, um das aktuelle UXON einer konkreten Seite oder eines Dialogs zu untersuchen. Nutzen Sie `UiWidgetInfoTool` für ein über eine URL erreichtes instanziiertes Widget.

| Argument | Erforderlich | Beschreibung |
| --- | --- | --- |
| `path` | Ja | PHP-Dateipfad des Widgets oder ein unterstützter Core-Widget-Typ. |

**Verwendung.** Das Modell übergibt den PHP-Dateipfad des Widgets oder einen unterstützten Core-Widget-Typ. Ein Dateipfad ist der eindeutigste Selektor.

**Ergebnis und Grenzen.** Das Tool kombiniert indizierte UXON-Annotationen mit Metadaten zu Widget-Funktionen und Presets. Fehlende oder unvollständige Modellannotationen führen zu unvollständiger Dokumentation.

## `UiWidgetInfoTool`

**Alias:** `axenox.GenAI.UiWidgetInfoTool` | [UXON-Prototyp](api/docs/exface/Core/Docs/UXON/UXON_prototypes.md?selector=%5Caxenox%5CGenAI%5CAI%5CTools%5CUiWidgetInfoTool)

**Zweck.** Lädt über eine ExFace-Facade das UXON-Modell und die Markdown-Beschreibung einer konkreten Seite, eines Dialogs oder eines verschachtelten Widgets.

**Verwenden, wenn.** Der Agent die aktuelle UI-Struktur verstehen muss, bevor er eine Seite ändert, auf sichtbare Bedienelemente verweist oder eine Widget-Konfiguration diagnostiziert.

**Nicht verwenden, wenn.** Werden nur die allgemeinen Eigenschaften eines Widget-Typs benötigt, verwenden Sie stattdessen `ModelWidgetTypeInfoTool`. Laden Sie nicht eine vollständige Seite, wenn der relevante Teil gezielt über eine bekannte `widget_id` ausgewählt werden kann.

| Argument | Erforderlich | Beschreibung |
| --- | --- | --- |
| `url` | Ja | Seitenalias, Facade-URL oder Abfragezeichenfolge. |
| `widget_id` | Nein | ID eines verschachtelten Widgets; weglassen, um das Root-Widget zu dokumentieren. |

**Verwendung.** Das Modell übergibt einen Seitenalias, eine Facade-URL oder eine Abfragezeichenfolge und kann optional eine verschachtelte `widget_id` auswählen. Die URL muss von einer Facade auflösbar sein, die die Widget-Suche unterstützt.

**Ergebnis und Grenzen.** Das Ergebnis beschreibt das aufgelöste Widget und sein UXON. Fehlende Seiten, unbekannte Widget-IDs und nicht unterstütztes Routing werden als Warnungen oder Fehler zurückgegeben.

## `MockTool`

**Alias:** `axenox.GenAI.MockTool` | [UXON-Prototyp](api/docs/exface/Core/Docs/UXON/UXON_prototypes.md?selector=%5Caxenox%5CGenAI%5CAI%5CTools%5CMockTool)

**Zweck.** Gibt vordefinierte Inhalte zurück, ohne den tatsächlichen Vorgang auszuführen, für den ein Tool steht.

**Verwenden, wenn.** Agententests und Prompt-Entwicklung deterministische Ausgaben benötigen oder ein Workflow bewertet werden muss, bevor die echte Integration verfügbar ist.

**Nicht verwenden, wenn.** Mock-Ausgaben sind keine produktive Datenquelle und dürfen nicht als aktueller Systemzustand dargestellt werden.

| UXON-Eigenschaft | Beschreibung |
| --- | --- |
| `request_response_pairs` | Geordnete Anfrage-Matcher und ihre Antworten; der erste Treffer gewinnt. |
| `sample_response` | Ersatzantwort, wenn kein Paar übereinstimmt. |

**Verwendung.** Konfigurieren Sie `sample_response` als deterministische Ersatzantwort und überschreiben Sie die Argumentdefinition so, dass sie dem getesteten Tool entspricht. `request_response_pairs` ist für die Auswahl von Antworten anhand der Anfrage vorgesehen, doch die aktuelle Annotation und Konvertierungsimplementierung sind unvollständig.

**Ergebnis und Grenzen.** Das Tool gibt Markdown aus der ausgewählten Mock-Antwort zurück. Bis die Verarbeitung von Anfrage-Antwort-Paaren korrigiert ist, sollten Sie sich für vorhersehbares Verhalten auf `sample_response` verlassen.

## Fehlerverhalten

Tools geben einen typisierten Wert gemeinsam mit keiner, einer oder mehreren Exceptions zurück. Laufzeitfehler umfassen ungültige Argumente, abgelehnte Pfade, fehlgeschlagene Befehle, Ein-/Ausgabefehler und ungültige Modelloperationen. Warnungen können Teilergebnisse oder fehlende optionale Daten darstellen. Prompt-Implementierungen können diese Exceptions dem LLM als Warnungen anzeigen.

Der Tool-Zugriff ersetzt nicht die ExFace-Autorisierung. Datenoperationen, Aktionen, Facades, Protokolle, Dateien und Befehle müssen weiterhin auf die Berechtigungen und Pfade beschränkt werden, die für den Anwendungsfall des Agenten erforderlich sind.