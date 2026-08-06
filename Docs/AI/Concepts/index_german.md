# Referenz der Agenten-Concepts

[English](index.md)

Concepts stellen Kontext bereit, den ein Agent automatisch zusammen mit seinen Anweisungen erhalten soll. Sie lösen benannte Platzhalter auf, während der Prompt erstellt wird, noch bevor die Anfrage an das LLM gesendet wird. Dadurch eignen sie sich für Informationen, die in jeder relevanten Konversation erforderlich sind und nicht davon abhängen sollen, dass sich das Modell für den Aufruf eines Tools entscheidet.

Verwenden Sie Concepts für stabiles Hintergrundwissen, verbindliche Regeln, ein kleines aktuelles Schema oder aus der Eingabezeile abgeleiteten Kontext. Nutzen Sie sie nicht, um große oder nur selten benötigte Informationsmengen vorab zu laden: Concept-Ausgaben verlängern die Erstellung des Prompts und werden auch dann in den Modellkontext aufgenommen, wenn die aktuelle Frage sie nicht benötigt. Stellen Sie in solchen Fällen ein Tool bereit, über das das Modell Details bei Bedarf abrufen kann.

Diese Seite dokumentiert alle derzeit von `axenox.GenAI` bereitgestellten Concept-Prototypen und erläutert, wann und wie sie verwendet werden.

## Ein Concept konfigurieren

Ein Concept wird unter `concepts` in `CONFIG_UXON` konfiguriert. Der Objektschlüssel ist der Platzhaltername und sollte den Inhalt beschreiben, beispielsweise `platform_docs` oder `database_schema`. Fügen Sie diesen Schlüssel an genau der Stelle in `INSTRUCTIONS` als `[#placeholder_name#]` ein, an der der gerenderte Inhalt erscheinen soll.

```json
{
  "concepts": {
    "platform_docs": {
      "alias": "axenox.GenAI.AppDocsConcept",
      "app_alias": "exface.Core",
      "starting_page": "index.md",
      "depth": 0
    }
  }
}
```

```md
## Platform documentation

[#platform_docs#]
```

Die Concept-Ausgabe wird beim Rendern des Prompts erzeugt und für diese Concept-Instanz zwischengespeichert. Platzieren Sie den Platzhalter unter einer aussagekräftigen Überschrift, damit das Modell versteht, warum der erzeugte Inhalt vorhanden ist. Einige Concepts können außerdem Tools vorschlagen, die der Agent bereitstellen sollte; `AppDocsConcept` fügt beispielsweise ein Tool zum Abrufen der verlinkten Detaildokumentation hinzu.

## `AppDocsConcept`

**Alias:** `axenox.GenAI.AppDocsConcept` | [UXON-Prototyp](api/docs/exface/Core/Docs/UXON/UXON_prototypes.md?selector=%5Caxenox%5CGenAI%5CAI%5CConcepts%5CAppDocsConcept)

**Zweck.** Rendert eine Einstiegsseite aus der Markdown-Dokumentation einer installierten App in die Anweisungen des Agenten und kann optional verlinkte Seiten einbeziehen.

**Verwenden, wenn.** Jede Konversation eine kompakte Einführung in eine App, ihre Terminologie oder ihre Dokumentationsstruktur benötigt. Eine flache Seite mit Links ist besonders nützlich, weil sie dem Modell Orientierung gibt, während `GetDocsTool` später Details abrufen kann.

**Nicht verwenden, wenn.** Rendern Sie keinen vollständigen Dokumentationsbaum in jeden Prompt. Große Tiefenwerte erhöhen Ein-/Ausgabeaufwand, Latenz und Kontextgröße selbst bei themenfremden Fragen.

| UXON-Eigenschaft | Standard | Beschreibung |
| --- | --- | --- |
| `app_alias` | Erforderlich | App, deren Dokumentation geladen wird. |
| `starting_page` | `index.md` | Erste zu rendernde Dokumentationsseite. |
| `depth` | `0` | Anzahl der einzubeziehenden Ebenen verlinkter Dokumentation; bei `0` wird nur die Startseite gerendert. |
| `hide_title` | `false` | Entfernt den ersten Seitentitel der obersten Ebene. |
| `heading_level` | Unverändert | Normalisiert die erste Überschrift auf die ausgewählte Ebene und verschiebt untergeordnete Überschriften entsprechend. |

**Verwendung.** Setzen Sie `app_alias` und wählen Sie eine fokussierte `starting_page`. Beginnen Sie mit `depth: 0`; erhöhen Sie den Wert nur, wenn die verlinkten Seiten klein und für nahezu jede Anfrage erforderlich sind. Verwenden Sie `hide_title` und `heading_level`, um die gerenderte Seite unter der umgebenden Überschrift der Anweisungen einzupassen.

**Ergebnis und Grenzen.** Der Markdown-Printer der Dokumentation erzeugt den eingefügten Inhalt. Das Concept schlägt außerdem `GetDocsTool` vor, sodass das Modell relevanten Links bei Bedarf folgen kann. Die Auflösung schlägt fehl, wenn für die App oder Startseite keine Dokumentation vorhanden ist.

## `MarkdownFilesConcept`

**Alias:** `axenox.GenAI.MarkdownFilesConcept` | [UXON-Prototyp](api/docs/exface/Core/Docs/UXON/UXON_prototypes.md?selector=%5Caxenox%5CGenAI%5CAI%5CConcepts%5CMarkdownFilesConcept)

**Zweck.** Lädt eine oder mehrere Markdown-Dateien und fügt ihren kombinierten Inhalt in einer festgelegten Reihenfolge in die Anweisungen ein.

**Verwenden, wenn.** Agentenregeln oder Fachwissen als wiederverwendbare Markdown-Dateien gepflegt werden, beispielsweise Repository-Anweisungen, Coding-Konventionen oder ein kurzes Fachglossar. Dadurch bleibt gemeinsam genutztes Material außerhalb einzelner Agentendatensätze.

**Nicht verwenden, wenn.** Nehmen Sie keine veränderlichen Daten oder umfangreichen Referenzhandbücher auf, die nur gelegentlich benötigt werden. Verwenden Sie für solche Details ein geeignetes Abruf-Tool.

| UXON-Eigenschaft | Standard | Beschreibung |
| --- | --- | --- |
| `file_paths` | Erforderlich | Geordnete Liste relativer oder absoluter Markdown-Dateipfade. |
| `base_path` | Vendor-Verzeichnis | Basisverzeichnis für relative Pfade. |
| `heading_level` | Unverändert | Normalisiert Überschriften so, dass sie auf der ausgewählten Ebene beginnen. |
| `strip_front_matter` | `false` | Entfernt YAML- oder TOML-Frontmatter vor dem Rendern. |

**Verwendung.** Führen Sie die benötigten Dateien in `file_paths` in der Reihenfolge auf, in der sie erscheinen sollen. Relative Pfade werden unterhalb von `base_path` aufgelöst, das standardmäßig auf das Vendor-Verzeichnis verweist. Verwenden Sie `strip_front_matter`, wenn Metadaten das Modell nicht erreichen sollen, und `heading_level`, um Dateiüberschriften in die umgebenden Anweisungen einzupassen.

**Ergebnis und Grenzen.** Das Ergebnis ist der zusammengefügte Markdown-Inhalt. Fehlende Konfiguration oder nicht lesbare Dateien verhindern eine sinnvolle Auflösung. Halten Sie die Liste klein, da jede einbezogene Datei zu jedem Prompt beiträgt.

## `MetamodelDbmlConcept`

**Alias:** `axenox.GenAI.MetamodelDbmlConcept` | [UXON-Prototyp](api/docs/exface/Core/Docs/UXON/UXON_prototypes.md?selector=%5Caxenox%5CGenAI%5CAI%5CConcepts%5CMetamodelDbmlConcept)

**Zweck.** Erzeugt aus ausgewählten ExFace-Metaobjekten ein kompaktes DBML-Schema, das verfügbare Attribute, Enum-Werte und Beziehungen enthält.

**Verwenden, wenn.** Ein datenorientierter Agent bei nahezu jeder Anfrage dasselbe begrenzte Objektmodell verstehen muss, beispielsweise beim Erstellen von Abfragen oder Erklären von Relationen für eine App.

**Nicht verwenden, wenn.** Fügen Sie nicht das vollständige Metamodell ein. Wenn die Objekte je nach Frage variieren, sollte der Agent sie stattdessen mit `ModelObjectInfoTool` ermitteln.

| UXON-Eigenschaft | Beschreibung |
| --- | --- |
| `object_filters` | ExFace-Bedingungsgruppe zur Auswahl der im Schema enthaltenen Objekte. |

```json
{
  "alias": "axenox.GenAI.MetamodelDbmlConcept",
  "object_filters": {
    "operator": "AND",
    "conditions": [
      { "expression": "APP__ALIAS", "comparator": "==", "value": "my.App" }
    ]
  }
}
```

**Verwendung.** Konfigurieren Sie eine nicht leere Bedingungsgruppe `object_filters`, die ausschließlich die relevante App, den Namespace, die Verbindung oder die Objektmenge auswählt. Filter können Prompt-Eingabeplatzhalter wie `[#~input:UID#]` enthalten, wenn das Schema von der ersten Eingabezeile abhängt.

**Ergebnis und Grenzen.** Das Ergebnis ist für den Modellkontext geeignetes DBML und keine ausführbare Datenbank-DDL. Das Rendern einer breiten Auswahl erhöht den Datenbankaufwand und die Prompt-Größe. Objekte, die auf benutzerdefinierten SQL-Anweisungen basieren, lassen sich möglicherweise nicht als reguläre DBML-Tabellen darstellen.

## `SqlDbmlConcept`

**Alias:** `axenox.GenAI.SqlDbmlConcept` | [UXON-Prototyp](api/docs/exface/Core/Docs/UXON/UXON_prototypes.md?selector=%5Caxenox%5CGenAI%5CAI%5CConcepts%5CSqlDbmlConcept)

**Zweck.** Erzeugt DBML ausschließlich für tabellenartige Metaobjekte, die auf SQL-Daten-Connectoren basieren, und gibt die Datenbank-Engine in der Ausgabe an.

**Verwenden, wenn.** Ein SQL-Assistent in jeder Konversation das physische, tabellenorientierte Schema für eine ausgewählte Verbindung oder App benötigt. Wenn sich die Aufgabe ausdrücklich auf SQL bezieht, ist dieses Concept genauer als `MetamodelDbmlConcept`.

**Nicht verwenden, wenn.** Verwenden Sie es nicht für Nicht-SQL-Connectoren, benutzerdefinierte SQL-Objekte oder allgemeine konzeptionelle Objektdokumentation.

**Verwendung.** Übergeben Sie dieselbe eng begrenzte Bedingungsgruppe `object_filters` wie für `MetamodelDbmlConcept`. Die Filterung nach einer Verbindung aus `[#~input:FIELD#]` ist nützlich, wenn ein KI-Chat für eine ausgewählte Datenverbindung geöffnet wird.

**Ergebnis und Grenzen.** Das Concept erzeugt DBML für unterstützte SQL-Objekte und Engines wie MySQL, MariaDB, Oracle und Microsoft SQL Server. Die Auflösung schlägt fehl, wenn keine geeigneten SQL-Objekte zur Konfiguration passen.

## `ToolCallConcept`

**Alias:** `axenox.GenAI.ToolCallConcept` | [UXON-Prototyp](api/docs/exface/Core/Docs/UXON/UXON_prototypes.md?selector=%5Caxenox%5CGenAI%5CAI%5CConcepts%5CToolCallConcept)

**Zweck.** Ruft während der Prompt-Erstellung ein Tool auf und fügt das Tool-Ergebnis direkt in die Anweisungen ein.

**Verwenden, wenn.** Dynamische Informationen bereits vorliegen müssen, bevor das Modell sein weiteres Vorgehen bestimmen kann, beispielsweise der ausgewählte Protokolleintrag, ein von der Eingabezeile bestimmtes Schema oder eine kompakte aktuelle Verzeichnisübersicht.

**Nicht verwenden, wenn.** Verwenden Sie es nicht für aufwendige, breite oder optionale Aufrufe. Ein reguläres Agenten-Tool ist vorzuziehen, wenn die Informationen nur für einige Fragen benötigt werden. Nutzen Sie eine unbemerkt leere Ausgabe niemals als einzigen Mechanismus zur Durchsetzung einer kritischen Regel.

| UXON-Eigenschaft | Beschreibung |
| --- | --- |
| `tool_name` | Name eines bereits für den Agenten konfigurierten Tools. |
| `tool_definition` | Inline-UXON-Definition für ein temporäres Tool. Sein Funktionsname entspricht standardmäßig dem Concept-Platzhalter. |
| `arguments` | Geordnete Werte, die an das ausgewählte Tool übergeben werden. |

**Verwendung.** Setzen Sie `tool_name`, um ein bereits vom Agenten bereitgestelltes Tool aufzurufen, oder geben Sie eine Inline-`tool_definition` für ein Tool an, das das Modell selbst nicht aufrufen soll. Übergeben Sie Positionswerte über `arguments`. Eingabeplatzhalter wie `[#~input:FIELD#]` können Konfiguration oder Argumente aus der ersten Prompt-Eingabezeile ableiten. Das Weglassen beider Tool-Selektoren ist ein Konfigurationsfehler.

**Ergebnis und Grenzen.** Tool-Frontmatter wird vor dem Einfügen in Markdown konvertiert. Exceptions werden, soweit unterstützt, den Prompt-Warnungen hinzugefügt. Andere Fehler werden protokolliert und führen zu einer leeren Concept-Ausgabe. Da der Aufruf vor jeder Modellantwort ausgeführt wird, sollte er kostengünstig, eng gefiltert und begrenzt sein.

## `ToolIntroductionConcept`

**Alias:** `axenox.GenAI.ToolIntroductionConcept` | [UXON-Prototyp](api/docs/exface/Core/Docs/UXON/UXON_prototypes.md?selector=%5Caxenox%5CGenAI%5CAI%5CConcepts%5CToolIntroductionConcept)

**Zweck.** Erzeugt eine Markdown-Übersicht der aktuell vom Agenten bereitgestellten Tools einschließlich Beschreibungen, Regeln und Prototypinformationen.

**Verwenden, wenn.** Ein Agent mehrere Tools besitzt, deren integrierte Regeln oder Prototypbeschreibungen in den Systemanweisungen sichtbar sein sollen, ohne sie manuell zu duplizieren. Dies ist nützlich, wenn sich Tool-Sammlungen zwischen Agentenversionen weiterentwickeln.

**Nicht verwenden, wenn.** Behandeln Sie einen automatisch erzeugten Katalog nicht als Ersatz für Workflow-Anweisungen. Der Agent benötigt weiterhin knappe Regeln dazu, welches Tool zuerst verwendet werden soll und wann ein Aufruf verpflichtend ist.

| UXON-Eigenschaft | Standard | Beschreibung |
| --- | --- | --- |
| `heading_level` | `2` | Für jeden Tool-Abschnitt verwendete Überschriftenebene. |
| `show_description` | `true` | Nimmt die an das LLM gerichtete Beschreibung des Tools auf. |
| `show_rules` | `true` | Nimmt die vom Tool zurückgegebenen verbindlichen Regeln auf. |
| `show_prototype_description` | `true` | Nimmt die Prototypdokumentation auf oder verwendet sie als Ersatz, wenn keine Beschreibung vorhanden ist. |

**Verwendung.** Fügen Sie den Concept-Platzhalter an der Stelle ein, an der die Tool-Übersicht erscheinen soll. Wählen Sie mit `show_description`, `show_rules` und `show_prototype_description` den gewünschten Detailgrad und setzen Sie `heading_level` passend zur umgebenden Dokumentstruktur.

**Ergebnis und Grenzen.** Das Ergebnis enthält einen Markdown-Abschnitt je Tool; Tools ohne ausgewählte Inhalte werden ausgelassen. Wenn für viele Tools sämtliche Details aktiviert werden, kann ein großer System-Prompt entstehen. Nehmen Sie daher nur Informationen auf, die das Modell zur korrekten Auswahl und Verwendung der Tools benötigt.

## `MockConcept`

**Alias:** `axenox.GenAI.MockConcept` | [UXON-Prototyp](api/docs/exface/Core/Docs/UXON/UXON_prototypes.md?selector=%5Caxenox%5CGenAI%5CAI%5CConcepts%5CMockConcept)

**Zweck.** Ersetzt einen Concept-Platzhalter durch einen festen Wert.

**Verwenden, wenn.** Tests das Prompt-Verhalten von veränderlichen Dateien, Daten, Schemata oder Tool-Ausgaben isolieren müssen. Das Concept eignet sich außerdem, um zu testen, wie ein Agent auf ein bekanntes Kontextfragment reagiert.

**Nicht verwenden, wenn.** Verwenden Sie feste Mock-Inhalte nicht als produktiven Kontext und stellen Sie sie nicht als aktuellen Systemzustand dar.

| UXON-Eigenschaft | Beschreibung |
| --- | --- |
| `value` | Erforderliche Ausgabe, die den Platzhalter ersetzt. |

**Verwendung.** Konfigurieren Sie den exakten `value`, der den Platzhalter ersetzen soll, und behalten Sie denselben Platzhalternamen bei, den das getestete reale Concept verwendet.

**Ergebnis und Grenzen.** Die konfigurierte Zeichenfolge wird unverändert zurückgegeben und macht Tests dadurch deterministisch. Das Verhalten der realen Datenquelle wird weder validiert noch simuliert.

## `UiWidgetInfoConcept`

**Alias:** `axenox.GenAI.UiWidgetInfoConcept` | [UXON-Prototyp](api/docs/exface/Core/Docs/UXON/UXON_prototypes.md?selector=%5Caxenox%5CGenAI%5CAI%5CConcepts%5CUiWidgetInfoConcept)

**Status.** Dieser Prototyp ist im Paket vorhanden, aber noch nicht implementiert. Seine Ausgabemethode gibt keine Widget-Dokumentation zurück, und er stellt keine konfigurierbaren UXON-Eigenschaften bereit.

**Aktuelle Empfehlung.** Verwenden Sie ihn nicht in einer aktiven Agentenkonfiguration. Nutzen Sie `UiWidgetInfoTool`, wenn das Modell eine Seite oder ein Widget bei Bedarf untersuchen soll. Muss Widget-Kontext automatisch eingefügt werden, rufen Sie dieses Tool über `ToolCallConcept` mit einer begrenzten URL und optionalen Widget-ID auf.

## Ein Concept auswählen

| Anforderung | Concept |
| --- | --- |
| Einen kleinen Abschnitt der App-Dokumentation einbeziehen | `AppDocsConcept` |
| Gepflegte Markdown-Anweisungsdateien einbeziehen | `MarkdownFilesConcept` |
| Ausgewählte Metaobjekte als Schema beschreiben | `MetamodelDbmlConcept` |
| Ausschließlich SQL-basierte Tabellenobjekte beschreiben | `SqlDbmlConcept` |
| Aktuelle Tool-Ausgaben während der Prompt-Erstellung einfügen | `ToolCallConcept` |
| Alle vom Agenten bereitgestellten Tools vorstellen | `ToolIntroductionConcept` |
| Deterministischen Testkontext bereitstellen | `MockConcept` |

Wählen Sie ein Concept nur, wenn dessen Ausgabe vorliegen muss, bevor das Modell antworten kann. Bevorzugen Sie Tools für Details, die umfangreich, veränderlich, vertraulich, aufwendig abzurufen oder nur gelegentlich erforderlich sind. Ein übliches Muster besteht darin, ein Concept für eine kleine verlinkte Übersicht und ein Tool für die verlinkten Details zu verwenden.