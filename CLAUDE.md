# Projektregeln: modified eCommerce Shopsoftware (3.x) Modul

## Architektur & Updatesicherheit
- **Zielsystem:** Neueste modified eCommerce Shopsoftware (3.x), Plain PHP (mindestens PHP 8.1+, strikt ohne externe Composer-Abhängigkeiten im Modul).
- **Keine Core-Änderungen:** Modifiziere niemals Core-Dateien direkt. Nutze ausschließlich die offiziellen Auto-Include-Punkte unter `includes/extra/` und `admin/includes/extra/` sowie System-/Exportmodule unter `admin/includes/modules/system/`.
- **MMLC-Struktur:** Lege alle neuen Shop-Dateien unter `new_files/` ab (spiegelt das Shop-Root wider) und pflege die `moduleinfo.json` im Modul-Root. Das Modul muss sowohl über den MMLC als auch per manuellem Upload von `new_files/` lauffähig sein.

## Datenbank & Sicherheit
- **DB-Zugriff:** Verwende ausschließlich `xtc_db_query()`, `xtc_db_fetch_array()`, `xtc_db_input()`, `xtc_db_perform()` und `xtc_db_insert_id()`. Kein `mysqli_*` oder `PDO`.
- **SQL-Injection:** Escape alle externen Werte in Queries konsequent mit `xtc_db_input()` oder caste numerische IDs hart mit `(int)`.
- **XSS & CSRF:** Maskiere Ausgaben mit `encode_htmlspecialchars()`. Nutze im Adminbereich für Formulare `xtc_draw_form()` (inkl. CSRF-Token-Prüfung).
- **Tabellennamen:** Nutze definierte `TABLE_*`-Konstanten oder prüfe bei eigenen Tabellen per `defined('TABLE_MEINE_TABELLE')`, bevor du sie nutzt.

## PHP 8.x & Coding-Standards
- **Keine Notices/Warnings:** Prüfe Array-Keys (`$_GET`, `$_POST`, `$_SESSION`, DB-Rows) immer mit `isset()`, `??` oder `array_key_exists()`, um *Undefined array key*-Fehler unter PHP 8.x zu vermeiden.
- **Typen & Signaturen:** Achte auf strikte Typen bei PHP-internen Funktionen (kein `null` an `strlen()`, `trim()`, `htmlspecialchars()` übergeben -> immer `(string)($var ?? '')` nutzen).
- **Sprachdateien:** Keine hartcodierten Texte in PHP oder HTML. Lagere Texte in `lang/german/extra/` und `lang/english/extra/` aus (`define('TEXT_...', '...');`).
- **Templates:** Trenne Logik und Darstellung. Nutze Smarty-Templates (`.html`) unter `templates/tpl_modified/` (bzw. `CURRENT_TEMPLATE`) statt HTML per `echo` in PHP auszugeben.
