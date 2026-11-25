---
name: bx-module-updater
description: Erstellt oder erweitert Module für modified eCommerce 3.x im MMLC-Format (new_files + moduleinfo.json).
---

# Blueprint für ein modified 3.x Modul

Wenn ein neues Modul angelegt wird, erstelle immer folgende Struktur:

1. **`moduleinfo.json` (im Modul-Root):**
   - Enthält `name`, `archiveName`, `sourceDir: "new_files"`, `version`, `shortDescription`, `description`, `developer`, `developerWebsite`, `modifiedCompatibility` (aktuelle 3.x Versionen), `require` (PHP >= 8.1) und `autoload` (falls Klassen unter `includes/classes/` liegen).

2. **Systemmodul unter `new_files/admin/includes/modules/system/<modul_kuerzel>.php`:**
   - Klasse `<modul_kuerzel>` mit Properties `$code`, `$title`, `$description`, `$sort_order`, `$enabled`, `$properties`.
   - Konstruktor prüft `defined('MODULE_<MODUL_KUERZEL>_STATUS')`.
   - Implementiere `process()`, `display()`, `check()`, `install()`, `remove()`, `keys()`.
   - In `install()`: Lege mindestens `MODULE_<MODUL_KUERZEL>_STATUS` (`True`/`False`) in `TABLE_CONFIGURATION` an.
   - In `remove()`: Lösche alle eigenen Einträge aus `TABLE_CONFIGURATION` sauber über `WHERE configuration_key IN ('" . implode("', '", $this->keys()) . "')`.

3. **Auto-Include-Hooks (nur bei Bedarf):**
   - Lege Dateien unter `new_files/includes/extra/<hook_ordner>/<modul_kuerzel>.php` an.
   - Prüfe am Dateianfang immer zuerst, ob das Modul aktiv ist:
     ```php
     if (!defined('MODULE_<MODUL_KUERZEL>_STATUS') || MODULE_<MODUL_KUERZEL>_STATUS !== 'True') {
         return;
     }
     ```
   - Vermeide globale Variablen-Kollisionen im Auto-Include-Scope (kapsle Logik in Funktionen/Klassen oder räume temporäre Variablen mit `unset()` auf).
