
# 📘 Power UI – Beschreibung zum Testen von KI-Agenten

Das Testsystem in **Power UI** ermöglicht die strukturierte Überprüfung von KI-Agenten anhand definierter Prompts, Testkriterien und Bewertungsmetriken.  
Jeder Test wird als eigenständige Einheit beschrieben und kann automatisiert ausgeführt werden.

---

## 🧩 Allgemeiner Aufbau (immer benötigt)

- wergen über die Oberfläche ausgewählt, bedarf keine Uxon <!-- Json -->  konfiguration.

### **Name**
Beschreibt **was getestet wird**.  
> Beispiel: *„Bewertung der SQL-Abfragegenerierung“*

### **Description**
Beschreibt, **was genau** geprüft wird.  
> Beispiel: *„Prüft, ob der Agent aus einer natürlichen Spracheingabe eine korrekte SQL-Abfrage erzeugt.“*

### **Ai Agent**

Gibt den exakten Namen des Agenten an (kann pber Dropdown ausgewählt werden)

---

## 💬 Test Prompt

Der **Test Prompt** ist die Frage oder Aufgabe, die dem KI-Agenten gestellt wird.  
> Beispiel:  
> „Gib eine SQL-Abfrage aus, die alle Kunden aus Deutschland selektiert, deren Umsatz größer als 5000 € ist.“

---

## ⚙️ Test Criteria

Die **Test Criteria** definieren, **wie** die Antworten des Agenten ausgewertet werden.  
Jedes Kriterium basiert auf einer **PrototypeClass**, die vorgibt:
- **Wie der relevante Text extrahiert wird**
- **Was das erwartete Ergebnis ist**
- **Wie das Ergebnis bewertet wird**
- **Mit welcher Gewichtung (weight)** es in die Gesamtnote eingeht

---

### 🧱 Standardparameter pro Testkriterium

| Parameter | Beschreibung                                                 |
|------------|--------------------------------------------------------------|
| **name** | Name des Kriteriums                                          |
| **description** | Erklärung, was geprüft wird                                  |
|**Expected value**| Die erwartete richtige Lösung
| **weight** | Gewichtung im Gesamtergebnis                                 |
| **prototype_class** | Klasse, die die Extraktionslogik definiert                   |
| **config_uxon** | Konfiguration zur Steuerung der Extraktion und der Bewertung |


---

## 🧬 Prototype Classes

- werden wie andere Parameter über Oberfläche ausgewählt, Konfiguration in config_Uxon <!-- keine Json Konfiguration -->

### **TextResponseTestCriterion.php**

Diese Klasse wird verwendet, um Textinhalte über **Regex** zu extrahieren und anschließend mit Metriken zu bewerten.

#### **Hauptfunktionen**
| Methode | Beschreibung |
|----------|---------------|
| `extract_by_regex` | Manuelles Setzen eines Regex-Ausdrucks |
| `extract_code_block` | Extrahiert Codeblöcke aus Markdown-Antworten<br>**Vordefiniertes Regex:** `/```([\s\S]*?)```/` | 


### **ToolResponseTestCriterion.php**

Diese Klasse erzeugt eine **formatierte Textausgabe** aller Toolaufrufe, die ein KI-Agent während seiner Antwort ausgeführt hat.

Der ausgegebene Text enthält für jedes verwendete Tool den **Toolnamen** und alle **Argumente**, die beim Aufruf übergeben wurden.  
Mehrere Toolaufrufe werden durch Leerzeilen voneinander getrennt.

---

#### **Hauptfunktionen**
| Methode | Beschreibung |
|----------|---------------|
| *(folgt)* | *(wird für zukünftige Erweiterungen ergänzt)* |

---

#### **Ausgabeformat**

Toolname:
\<Name des Tools>

Arguments:
\<Argument1> = "\<Wert1>"
\<Argument2> = "\<Wert2>"
...

---

<!-- Achtung diese Angaben nur als Textform, sie gehören nicht mit in das Json wenn der Nutzer danach fragt-->
<!-- folgende Sachen gehören in das Json (Metriken):-->

## 📏 Metriken

Werden über eine Liste in der **config_uxon** gesetzt und können mehrfach benutzt werden:

##### Beispielnutzung (Beispielinhalt von Config_Uxon):
````json
{
  "methoden_der_PrototypeKlassen": "...",
  "metrics": 
    [
      {
        "name": "TextMatch1",
        "type": "TextMatch",
        "contains_all": [
          "Techcorp",
          "John"
        ],
        "not_contains_any": [
          "OR"
        ]
      },
      {
        "name": "GetDoc",
        "type": "ToolCheck",
        "tools": [
          {
            "name": "search_user",
            // wird benötigt
            "required": true,
            //standartmäßig auf true
            "min_calls": 1,
            // optional
            "max_calls": 2,
            // optional
            "arguments": {
              "name": "John"
              //Key (hier name) muss übereinstimmen mit den Argument was der Agent nutzt
            }
          }
        ]
      }
    ]
}
`````

Die **Metriken** bestimmen, **wie die extrahierten Ergebnisse bewertet werden** und sind wichtig für automatische Bewertungen.
Sie werden in einem Array definiert und sind modular erweiterbar.

---

| Metric Name           | Beschreibung                                                            | Eigenschaften                                                                |
| --------------------- | ----------------------------------------------------------------------- | ---------------------------------------------------------------------------- |
| `TextMatch`           | Vergleicht Text auf exakte Übereinstimmung, Teilstrings oder Wortmuster | equals, equals_ignore_case, contains, contains_ignore_case, contains_all, contains_any, not_contains_any, starts_with, starts_with_any, ends_with, ends_with_any |
| `ToolCheck` | Überprüft die Tool die der Agent aufgerufen hat, ob es die richtigen mit den richtigen Argumenten waren usw.| forbidden_tools, tools

---

### 🧩 Eigenschaften von Metriken

| Eigenschaft              | Typ              | Beschreibung                                                                                                                             | Nutzbar in         |
| ------------------------ |------------------|------------------------------------------------------------------------------------------------------------------------------------------|--------------------|
| **type** | string           | Der Typ ist wichtig und muss immer angegeben werden, da darüber die verwendete Metrik bestimmt wird                                      | In allen notwendig |
| **name** | string           | Falls man eine Metrik umbenennen möchte, kann hier ein eigener Name angegeben werden, falls nicht gesetzt wird der type als Name genommen | In allen möglich   |
| **equals**               | string           | Text muss **exakt** mit dem angegebenen Wert übereinstimmen                                                                              | Textmatch          |
| **equals_ignore_case**   | string           | Exakte Übereinstimmung, **Groß-/Kleinschreibung** wird ignoriert                                                                         | Textmatch          |
| **contains**             | string           | Prüft, ob der Text eine bestimmte Teilzeichenkette enthält                                                                               | Textmatch          |
| **contains_ignore_case** | string           | Wie `contains`, aber ohne Berücksichtigung der Groß-/Kleinschreibung                                                                     | Textmatch          |
| **contains_all**         | string[] (array) | Alle angegebenen Wörter oder Phrasen müssen im Text vorkommen (**UND**)                                                                  | Textmatch          |
| **contains_any**         | string[] (array) | Mindestens ein angegebener Begriff muss vorkommen (**ODER**)                                                                             | Textmatch          |
| **not_contains_any**     | string[] (array) | Keiner der angegebenen Begriffe darf im Text vorkommen                                                                                   | Textmatch          |
| **starts_with**          | string           | Text muss mit der angegebenen Zeichenkette beginnen                                                                                      | Textmatch          |
| **starts_with_any**      | string[] (array) | Text muss mit einem der angegebenen Präfixe beginnen                                                                                     | Textmatch          |
| **ends_with**            | string           | Text muss mit der angegebenen Zeichenkette enden                                                                                         | Textmatch          |
| **ends_with_any**        | string[] (array) | Text muss mit einem der angegebenen Suffixe enden                                                                                        | Textmatch          |
| **forbidden_tools** | string [] (array) | Tools die verboten sind und negativ sich auf die Bewertung auswirken wenn sie aufgerufen werden                                          | ToolCheck          |
| **tool** | ToolCheckData[] (array) | Welche Tools sollen wie aufgerufen werden, dafür bedarf es eine besondere Konfiguration <!-- Json angaben sind vordefiniert wie-->| ToolCheck          |

Achtung bei den Eigenschaften die Typen müssen exakt sein. Wenn ein Array "Eigenschaft": ["Content"] für ein String übergeben wird kommt es zum Fehler.

#### Besondere Typen

##### ToolCheckData

| Typ  | Datatype        | Beschreibung                                                                                                                                                                              |
|------|-----------------|-------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| **name** | string          | Gibt den Namen der zu verwenden funktion an (wird immer benötigt)                                                                                                                         | 
| **min_calls** | number          | Mindestesanzahl an Aufrufen die bei diesen Tool vorgenommen werden soll                                                                                                                   |
| **max_calls** | number          | Maximalanzahl an Aufrufen die bei diesen Tool vorgenommen werden soll                                                                                                                     |
| **arguments** | Json [] (array) | Eine Liste von Jsons (Uxons), wobei der Key angibt wie das Argument heißt was die KI Anfragen soll und der Inhalt mit welchen Inhalt                                                      |
| **allow_additional_arguments** | bool            | es bestimmt ob die KI andere Argumenten nutzen darf, wenn es auf false ist wird in die Bewertung mit einbezogen wenn Argumenten verwendet wurden die nicht richtig waren, sonst ignoriert |



## ✅ Zusammenfassung

1. **Name**, **Description**, **Agent**, **Prompt** und **Criteria** sind Pflichtbestandteile jedes Tests.
2. **PrototypeClasses** legen fest, wie der relevante Teil der Antwort extrahiert wird.
3. **Config UXON** steuert das Extraktionsverfahren (Regex, Codeblock, manuell).
4. **Metrics** bewerten die extrahierten Ergebnisse anhand objektiver Maßstäbe.
5. Durch die Kombination dieser Elemente lassen sich Power UI KI-Agenten **automatisiert, reproduzierbar und vergleichbar** testen.