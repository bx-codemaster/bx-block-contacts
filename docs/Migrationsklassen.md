# Modulspezifische Datenbankmigrationen

Diese Dokumentation beschreibt Aufbau, Einbindung und Funktionsweise einer
Migrationsklasse für ein modifiziertes eCommerce Systemmodul.

Referenzimplementierung:

```text
src/admin/includes/classes/BX/Github/BxBlockContactsMigrations.php
```

Die Migration wird gemeinsam mit dem selbst aktualisierenden GitHub-Updater
verwendet. Dateien und Datenbankänderungen bleiben dabei zwei getrennte
Bausteine. Der Updater verteilt und validiert Dateien; die Migrationsklasse
kennt die Datenbank des jeweiligen Moduls.

## 1. Ziel

Ein Modulrelease kann nicht nur PHP-, Sprach- und Template-Dateien ändern.
Zwischen zwei Modulversionen kann außerdem erforderlich sein:

- eine neue Tabelle anzulegen;
- eine bestehende Tabelle um Spalten zu erweitern;
- Indizes oder Fremdschlüssel zu ergänzen;
- Konfigurationswerte anzulegen oder umzubenennen;
- vorhandene Daten umzuschreiben;
- alte Tabellen, Spalten oder Daten zu bereinigen;
- Daten aus einer früheren Modulversion in ein neues Datenmodell zu überführen.

Eine Migrationsklasse beschreibt diese Änderungen als versionierte,
wiederholbare Schritte.

Sie ist modulspezifisch. `bx-block-contacts` darf nicht wissen, wie die Tabellen
von `bx-advent-calendar`, `bx-stockmanagerpro` oder einem anderen Modul aufgebaut
sind.

## 2. Abgrenzung zur normalen Erstinstallation

Die normale Methode `install()` eines modified-Systemmoduls bleibt für die
Erstinstallation zuständig.

```php
public function install(): void
{
    // Tabellen und Konfiguration bei Neuinstallation anlegen
}
```

Die Migrationsklasse wird dagegen im nächsten Request nach einem Dateiupdate
oder beim Laden manuell eingespielter neuer Dateien aufgerufen.

Beide Bereiche können teilweise dieselben Schemaoperationen benötigen. Deshalb
sollten Hilfsfunktionen für Tabellen-, Spalten- und Indexprüfung zentral und
wiederverwendbar sein. `install()` darf nicht blind davon ausgehen, dass eine
Migrationsklasse bereits vorhanden ist.

## 3. Aufruf im Folge-Request

Der Updater installiert in seinem Request ausschließlich Dateien und den
Release-Manifeststand:

```php
$result = $this->getGithubUpdater()->installLatestUpdate();
```

Im darauffolgenden Request lädt PHP die neu verteilte Systemmodul- und
Migrationsklasse. Der Konstruktor ruft:

```php
BxBlockContactsMigrations::migratePending();
```

Intern werden zwei Daten verwendet:

```text
fromVersion = gespeicherter MODULE_*_SCHEMA_VERSION
toVersion   = BxBlockContactsMigrations::SCHEMA_VERSION
```

Die Releaseversion `MODULE_*_VERSION` und die Schema-Zielversion sind absichtlich
verschieden:

- `MODULE_*_VERSION` beantwortet: „Welche Programmversion ist installiert?“
- `MODULE_*_SCHEMA_VERSION` beantwortet: „Bis zu welchem DB-Schema wurde die
  Installation tatsächlich migriert?“

Eine neue Codeversion ohne Datenbankänderung erhöht nur die Releaseversion. Eine
spätere Datenbankänderung erhält einen neuen Wert in `SCHEMA_VERSION`.

So wird garantiert die neue Klassendefinition ausgeführt. Eine Migrationsklasse,
die durch das Update selbst ersetzt wird, darf nicht als Callback an den
`installLatestUpdate()`-Aufruf desselben Requests übergeben werden: PHP hätte
die alte Klassendefinition bereits geladen.

Dasselbe Verfahren erkennt manuell eingespielte neue Dateien. Die Releaseversion
und der Datenbankschema-Stand werden getrennt verwaltet.

## 4. Empfohlener Dateipfad

Für `bx-block-contacts`:

```text
src/admin/includes/classes/BX/Github/BxBlockContactsMigrations.php
```

Für ein anderes Modul:

```text
src/admin/includes/classes/BX/Github/BxMyModuleMigrations.php
```

Der gemeinsame Pfad ist Absicht: Der Updater und modulspezifische
Migrationsklassen werden gemeinsam unter `admin/includes/classes/BX/Github`
verteilt. Die `allowed_paths`-Konfiguration muss diesen Pfad enthalten:

```php
'allowed_paths' => array(
    'admin/includes/classes/BX/Github',
    // weitere modulspezifische Pfade
),
```

## 5. Laden der Migrationsklasse

Die Klasse wird im Systemmodul vor der Updateausführung geladen:

```php
require_once DIR_FS_ADMIN
    . 'includes/classes/BX/Github/BxBlockContactsMigrations.php';
```

Robuster ist bei optionaler Deinstallation oder unvollständigem Release:

```php
$migrationFile = DIR_FS_ADMIN
    . 'includes/classes/BX/Github/BxBlockContactsMigrations.php';

if (is_file($migrationFile)) {
    require_once $migrationFile;
} else {
    // kontrollierte Fehlermeldung; kein fataler require_once-Fehler
}
```

Ein sinnvoller Ort ist direkt beim Laden des Updaters im Konstruktor:

```php
public function __construct()
{
    require_once DIR_FS_ADMIN
        . 'includes/classes/BX/Github/BxGithubModuleUpdater.php';
    require_once DIR_FS_ADMIN
        . 'includes/classes/BX/Github/BxBlockContactsMigrations.php';

    // normale Modulinitialisierung
}
```

Der neue Releaseinhalt wurde zu diesem Zeitpunkt bereits auf die Shop-Dateien
kopiert. Die `require_once`-Anweisung muss also auf eine Migrationsklasse zeigen,
die im neuen Release enthalten ist.

## 6. Minimale Klasse

```php
<?php

declare(strict_types=1);

final class BxMyModuleMigrations
{
    public const SCHEMA_VERSION = '1.4.0';
    public const STORED_SCHEMA_KEY = 'MODULE_MY_MODULE_SCHEMA_VERSION';

    public static function migratePending(): void
    {
        $storedVersion = self::readStoredSchemaVersion();

        if (version_compare($storedVersion, self::SCHEMA_VERSION, '>=')) {
            return;
        }

        self::migrate($storedVersion, self::SCHEMA_VERSION);
        self::writeStoredSchemaVersion(self::SCHEMA_VERSION);
    }

    public static function migrate(string $fromVersion, string $toVersion): void
    {
        // Versionsblöcke werden hier in aufsteigender Reihenfolge aufgerufen.
    }
}
```

`readStoredSchemaVersion()` und `writeStoredSchemaVersion()` sind Teil der
Migrationsklasse, weil nur das Modul weiß, in welcher Konfigurationsgruppe sein
interner Schema-Schlüssel gespeichert wird.

```php
private static function readStoredSchemaVersion(): string
{
    $query = xtc_db_query(
        "SELECT configuration_value
         FROM " . TABLE_CONFIGURATION . "
         WHERE configuration_key = '"
            . xtc_db_input(self::STORED_SCHEMA_KEY)
            . "'
         LIMIT 1"
    );

    if (!$query || xtc_db_num_rows($query) < 1) {
        return '0.0.0';
    }

    $row = xtc_db_fetch_array($query);
    $version = trim((string) ($row['configuration_value'] ?? ''));

    return $version !== '' ? $version : '0.0.0';
}
```

```php
private static function writeStoredSchemaVersion(string $version): void
{
    if (self::configurationExists(self::STORED_SCHEMA_KEY)) {
        $result = xtc_db_query(
            "UPDATE " . TABLE_CONFIGURATION . "
             SET configuration_value = '" . xtc_db_input($version) . "',
                 last_modified = NOW()
             WHERE configuration_key = '"
                . xtc_db_input(self::STORED_SCHEMA_KEY)
                . "'"
        );
    } else {
        $groupId = self::getConfigurationGroupId();

        $result = xtc_db_query(
            "INSERT INTO " . TABLE_CONFIGURATION . "
             (configuration_key, configuration_value,
              configuration_group_id, sort_order, date_added)
             VALUES (
                '" . xtc_db_input(self::STORED_SCHEMA_KEY) . "',
                '" . xtc_db_input($version) . "',
                " . $groupId . ",
                " . self::getNextConfigurationSortOrder($groupId) . ",
                NOW()
             )"
        );
    }

    if ($result === false) {
        throw new RuntimeException(
            'Der gespeicherte Datenbankschema-Stand konnte nicht geschrieben werden.'
        );
    }
}
```

Der Schlüssel darf bei Neuinstallation mit der aktuellen `SCHEMA_VERSION`
angelegt werden. Bei bestehenden Installationen wird ein fehlender Schlüssel
als `0.0.0` behandelt. Dadurch wird die noch erforderliche Migration
idempotent nachgeholt.

### Warum `final`?

Die Klasse ist eine abgeschlossene Beschreibung des Modul-Schemas. Sie soll
nicht durch Vererbung oder externe Seiteneffekte variiert werden.

```php
final class BxMyModuleMigrations
```

verhindert unbeabsichtigte Unterklassen.

### Warum statische Methoden?

`migratePending()` kann direkt beim Laden des Systemmoduls aufgerufen werden.
Es ist keine Instanz und keine komplexe Dependency Injection erforderlich.

## 7. Versionsblöcke

Jede strukturelle Änderung gehört zu genau einer Zielversion.

```php
private static function migrateTo120(
    string $fromVersion,
    string $toVersion
): void {
    if (!self::crossesVersion($fromVersion, $toVersion, '1.2.0')) {
        return;
    }

    // Änderungen, die seit Version 1.2.0 benötigt werden
}
```

Der zentrale Dispatcher ruft alle Blöcke in aufsteigender Versionsreihenfolge
auf:

```php
public static function migrate(string $fromVersion, string $toVersion): void
{
    self::migrateTo120($fromVersion, $toVersion);
    self::migrateTo130($fromVersion, $toVersion);
    self::migrateTo140($fromVersion, $toVersion);
}
```

### Der Versionssprung

```php
private static function crossesVersion(
    string $fromVersion,
    string $toVersion,
    string $migrationVersion
): bool {
    return version_compare($fromVersion, $migrationVersion, '<')
        && version_compare($toVersion, $migrationVersion, '>=');
}
```

Diese Logik bedeutet:

```text
Ausgangsversion älter als Migration
und
Zielversion mindestens Migration
```

Beispiele:

```text
1.1.0 -> 1.4.0: Migrationen 1.2.0, 1.3.0 und 1.4.0
1.2.0 -> 1.4.0: Migrationen 1.3.0 und 1.4.0
1.4.0 -> 1.4.1: keine Schema-Migration, falls kein Block für 1.4.1 existiert
```

## 8. Idempotenz

Idempotenz ist die wichtigste Eigenschaft einer Migration.

Idempotent bedeutet:

> Das erneute Ausführen verändert den Zustand nicht fehlerhaft und erzeugt
> keinen zweiten Datensatz, keine doppelte Spalte und keinen doppelten Index.

Warum ist das notwendig?

- Eine Migration kann teilweise ausgeführt worden sein.
- Eine danach folgende Dateioperation oder DB-Operation kann fehlschlagen.
- Der Administrator kann den Updatevorgang wiederholen.
- MySQL-DDL kann implizit committen und lässt sich nicht wie eine normale
  Transaktion sicher zurückrollen.

### Tabellen

```php
CREATE TABLE IF NOT EXISTS bx_my_module_data (...)
```

Das ist grundsätzlich idempotent.

### Spalten

Vor `ALTER TABLE` prüfen:

```php
if (!self::columnExists(self::TABLE_DATA, 'status')) {
    xtc_db_query(
        "ALTER TABLE " . self::TABLE_DATA . "
         ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'active'"
    );
}
```

### Indizes

```php
if (!self::indexExists(self::TABLE_DATA, 'status')) {
    xtc_db_query(
        "ALTER TABLE " . self::TABLE_DATA . "
         ADD INDEX status (status)"
    );
}
```

### Daten

Riskant:

```php
INSERT INTO bx_my_module_data (...) VALUES (...)
```

Sicherer ist:

- zuerst auf Existenz prüfen;
- ein passendes Unique-Kennzeichen verwenden;
- `INSERT ... ON DUPLICATE KEY UPDATE` verwenden;
- oder nur bei nachweislich vorhandenem UNIQUE-Index auf `configuration_key`
  ein `ON DUPLICATE KEY UPDATE` verwenden.

Für den internen Schema-Schlüssel ist die portable Reihenfolge
`SELECT → UPDATE/INSERT` vorzuziehen, weil nicht jede unterstützte
modified-Version einen passenden UNIQUE-Index garantiert.

## 9. Wiederverwendbare Prüfmethoden

Eine robuste Migrationsklasse besitzt kleine Hilfsmethoden:

```php
private static function tableExists(string $table): bool
```

```php
private static function columnExists(
    string $table,
    string $column
): bool
```

```php
private static function indexExists(
    string $table,
    string $index
): bool
```

Diese Methoden trennen:

- Versionsentscheidung;
- Prüfung des vorhandenen Schemas;
- tatsächlich auszuführende DDL-Anweisung.

### Tabellenprüfung

```php
private static function tableExists(string $table): bool
{
    $query = xtc_db_query(
        "SHOW TABLES LIKE '" . xtc_db_input($table) . "'"
    );

    return $query && xtc_db_num_rows($query) > 0;
}
```

### Spaltenprüfung

```php
private static function columnExists(
    string $table,
    string $column
): bool {
    $query = xtc_db_query(
        "SELECT 1
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = '" . xtc_db_input($table) . "'
           AND COLUMN_NAME = '" . xtc_db_input($column) . "'
         LIMIT 1"
    );

    return $query && xtc_db_num_rows($query) > 0;
}
```

### Indexprüfung

```php
private static function indexExists(
    string $table,
    string $index
): bool {
    $query = xtc_db_query(
        'SHOW INDEX FROM ' . self::quoteIdentifier($table)
        . " WHERE Key_name = '" . xtc_db_input($index) . "'"
    );

    return $query && xtc_db_num_rows($query) > 0;
}
```

## 10. Sichere SQL-Bezeichner

Tabellen- und Spaltennamen sind keine beliebigen Benutzereingaben. Trotzdem
sollten Bezeichner zentral geprüft werden:

```php
private static function quoteIdentifier(string $identifier): string
{
    if (preg_match('/^[A-Za-z0-9_]+$/', $identifier) !== 1) {
        throw new InvalidArgumentException('Invalid database identifier.');
    }

    return '`' . $identifier . '`';
}
```

Werte werden über `xtc_db_input()` abgesichert. Tabellennamen können mit dieser
Methode nicht parametrisiert werden und sollten deshalb über erlaubte
Identifier geprüft werden.

## 11. Fehlerbehandlung

`xtc_db_query()` kann bei einem Fehler `false` zurückgeben. Kritische
Schemaänderungen sollten diesen Fall nicht ignorieren.

```php
$result = xtc_db_query($sql);

if ($result === false) {
    throw new RuntimeException(
        'Migration 1.3.0: Spalte status konnte nicht angelegt werden.'
    );
}
```

Die Exception wird vom Updater abgefangen:

- Dateibackups werden zurückgespielt;
- Update wird als fehlgeschlagen protokolliert;
- Versionsnummer wird nicht normal abgeschlossen.

Bereits implizit committete MySQL-DDL kann jedoch nicht mehr vollständig
zurückgesetzt werden. Deshalb bleibt Idempotenz entscheidend.

## 12. Datenbanktransaktionen

Bei MySQL können reine DML-Operationen teilweise in einer Transaktion ausgeführt
werden. DDL-Anweisungen wie `CREATE TABLE` und `ALTER TABLE` können eine
implizite Transaktion auslösen.

Deshalb:

- nicht pauschal eine große Transaktion um alle Migrationen legen;
- einzelne DML-Blöcke bei Bedarf transaktional ausführen;
- DDL idempotent gestalten;
- nicht erwarten, dass Dateisystem und Datenbank gemeinsam rollbackfähig sind.

## 13. Vollständige Referenzklasse

```php
<?php

declare(strict_types=1);

final class BxMyModuleMigrations
{
    private const TABLE_DATA = 'bx_my_module_data';

    public static function migrate(string $from, string $to): void
    {
        self::migrateTo120($from, $to);
        self::migrateTo130($from, $to);
        self::migrateTo140($from, $to);
    }

    private static function migrateTo120(string $from, string $to): void
    {
        if (!self::crossesVersion($from, $to, '1.2.0')) {
            return;
        }

        $result = xtc_db_query(
            "CREATE TABLE IF NOT EXISTS " . self::TABLE_DATA . " (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                title VARCHAR(255) NOT NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        if ($result === false) {
            throw new RuntimeException('Migration 1.2.0 fehlgeschlagen.');
        }
    }

    private static function migrateTo130(string $from, string $to): void
    {
        if (!self::crossesVersion($from, $to, '1.3.0')) {
            return;
        }

        if (!self::columnExists(self::TABLE_DATA, 'status')) {
            $result = xtc_db_query(
                "ALTER TABLE " . self::quoteIdentifier(self::TABLE_DATA)
                . " ADD COLUMN status VARCHAR(20)
                   NOT NULL DEFAULT 'active'"
            );

            if ($result === false) {
                throw new RuntimeException(
                    'Migration 1.3.0: Spalte status konnte nicht angelegt werden.'
                );
            }
        }

        if (!self::indexExists(self::TABLE_DATA, 'status')) {
            $result = xtc_db_query(
                "ALTER TABLE " . self::quoteIdentifier(self::TABLE_DATA)
                . " ADD INDEX status (status)"
            );

            if ($result === false) {
                throw new RuntimeException(
                    'Migration 1.3.0: Index status konnte nicht angelegt werden.'
                );
            }
        }
    }

    private static function migrateTo140(string $from, string $to): void
    {
        if (!self::crossesVersion($from, $to, '1.4.0')) {
            return;
        }

        $result = xtc_db_query(
            "UPDATE " . self::quoteIdentifier(self::TABLE_DATA)
            . " SET status = 'inactive'
               WHERE status IS NULL OR status = ''"
        );

        if ($result === false) {
            throw new RuntimeException('Migration 1.4.0 fehlgeschlagen.');
        }
    }

    private static function crossesVersion(
        string $from,
        string $to,
        string $version
    ): bool {
        return version_compare($from, $version, '<')
            && version_compare($to, $version, '>=');
    }

    private static function columnExists(
        string $table,
        string $column
    ): bool {
        $query = xtc_db_query(
            "SELECT 1
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = '" . xtc_db_input($table) . "'
               AND COLUMN_NAME = '" . xtc_db_input($column) . "'
             LIMIT 1"
        );

        return $query && xtc_db_num_rows($query) > 0;
    }

    private static function indexExists(
        string $table,
        string $index
    ): bool {
        $query = xtc_db_query(
            'SHOW INDEX FROM ' . self::quoteIdentifier($table)
            . " WHERE Key_name = '" . xtc_db_input($index) . "'"
        );

        return $query && xtc_db_num_rows($query) > 0;
    }

    private static function quoteIdentifier(string $identifier): string
    {
        if (preg_match('/^[A-Za-z0-9_]+$/', $identifier) !== 1) {
            throw new InvalidArgumentException('Invalid database identifier.');
        }

        return '`' . $identifier . '`';
    }
}
```

## 14. Referenzimplementierung `bx-block-contacts`

Die aktuelle Klasse liegt unter:

```text
admin/includes/classes/BX/Github/BxBlockContactsMigrations.php
```

Sie stellt für den Versionsübergang nach 1.2.0 sicher, dass in
`TABLE_ADMIN_ACCESS` die Spalte `bx_block_contacts` existiert:

```php
self::ensureAdminAccessColumn();
```

Die Migration ist idempotent, da zuerst geprüft wird:

```php
SELECT 1
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = '...'
  AND COLUMN_NAME = 'bx_block_contacts'
LIMIT 1
```

Erst bei fehlender Spalte wird ausgeführt:

```sql
ALTER TABLE ...
ADD COLUMN `bx_block_contacts` INTEGER(1) NOT NULL DEFAULT 1
```

Der Updater erhält keinen Migrationscallback. Stattdessen ruft der Konstruktor
des Systemmoduls im Folge-Request:

```php
BxBlockContactsMigrations::migratePending();
```

Der gespeicherte Datenbankschema-Stand liegt getrennt von der Releaseversion in:

```text
MODULE_BX_BLOCK_CONTACTS_SCHEMA_VERSION
```

Die aktuelle Schema-Zielversion steht als
`BxBlockContactsMigrations::SCHEMA_VERSION` im Code.

`install()` und `remove()` sollen keine Migrationsklassenkonstanten referenzieren.
Sie verwenden den Schlüssel direkt als String:

```php
'MODULE_BX_BLOCK_CONTACTS_SCHEMA_VERSION'
```

Dadurch funktionieren Installation und Entfernung auch dann, wenn die optionale
Migrationsklassendatei fehlt oder eine vorherige Deinstallation unvollständig war.

### Wiederholte Fehlermeldungen und Parallelität

Fehlgeschlagene Migrationen werden für kurze Zeit in
`migration-error.json` gedrosselt. Dadurch wird die Adminseite nicht bei jedem
Aufruf erneut belastet. Der gespeicherte Fehler wird während der Wartezeit
weiterhin angezeigt, zusammen mit der verbleibenden Zeit bis zum nächsten
automatischen Versuch. Nach fünf Minuten wird ein neuer Versuch zugelassen.
Die Markerdatei kann nach einer bewussten Korrektur manuell gelöscht werden,
um sofort neu zu versuchen.

Bei der ersten Marker-Erzeugung wird im Cache-Verzeichnis zusätzlich eine
`.htaccess` angelegt. Das schützt Apache; unter nginx bleibt eine entsprechende
Serverregel erforderlich.

Gleichzeitige Migrationen werden zusätzlich über den MySQL-Named-Lock
`bx_block_contacts_migration` serialisiert. Nur der Request, der den Lock erhält,
prüft und schreibt den Schema-Schlüssel.

Der Schema-Schlüssel wird portabel mit `SELECT`, anschließend `UPDATE` oder
`INSERT` geschrieben. Dadurch hängt die Logik nicht davon ab, ob
`configuration.configuration_key` in der jeweils eingesetzten
modified-Version bereits durch einen UNIQUE-Index geschützt ist. Die Race
Condition wird hier über `GET_LOCK` verhindert, nicht über eine
UNIQUE-Indexannahme.

`GET_LOCK` ist verbindungsbezogen. Deshalb müssen Erwerb und Freigabe im selben
PHP-Request und über dieselbe Datenbankverbindung erfolgen.

## 15. Vorhandene Konfigurationen migrieren

Nicht nur Tabellen können migriert werden. Häufig müssen Konfigurationseinträge
angelegt oder Werte transformiert werden. `configuration_group_id` und die
Sortierung dürfen dabei niemals als feste Werte angenommen werden: Beide werden
bei der Erstinstallation dynamisch ermittelt.

Bei einer bestehenden Installation sollte deshalb zuerst der vorhandene
Modul-Konfigurationsgruppen-Schlüssel gelesen werden, zum Beispiel:

```php
MODULE_MY_MODULE_CONFIG_ID
```

Fehlt ein solcher expliziter Gruppenschlüssel, kann die Gruppe auch über einen
anderen bereits bekannten Modul-Konfigurationsschlüssel ermittelt werden.

```php
private static function getConfigurationGroupId(): ?int
{
    $queries = array(
        "SELECT configuration_value
         FROM " . TABLE_CONFIGURATION . "
         WHERE configuration_key = 'MODULE_MY_MODULE_CONFIG_ID'
         LIMIT 1",
        "SELECT configuration_group_id
         FROM " . TABLE_CONFIGURATION . "
         WHERE configuration_key = 'MODULE_MY_MODULE_STATUS'
         LIMIT 1",
    );

    foreach ($queries as $sql) {
        $query = xtc_db_query($sql);
        if ($query && xtc_db_num_rows($query) > 0) {
            $row = xtc_db_fetch_array($query);
            $value = $row['configuration_value']
                ?? $row['configuration_group_id']
                ?? null;

            if ($value !== null && (int) $value > 0) {
                return (int) $value;
            }
        }
    }

    return null;
}
```

Die Sortierung eines neuen Eintrags sollte innerhalb dieser Gruppe ermittelt
werden:

```php
private static function getNextConfigurationSortOrder(int $groupId): int
{
    $query = xtc_db_query(
        "SELECT COALESCE(MAX(sort_order), 0) AS max_sort_order
         FROM " . TABLE_CONFIGURATION . "
         WHERE configuration_group_id = " . (int) $groupId
    );
    $row = $query ? xtc_db_fetch_array($query) : array();

    return ((int) ($row['max_sort_order'] ?? 0)) + 1;
}
```

Beispiel für das Anlegen eines fehlenden Konfigurationswerts:

```php
if (!self::configurationExists('MODULE_MY_MODULE_NEW_OPTION')) {
    $groupId = self::getConfigurationGroupId();

    if ($groupId === null) {
        throw new RuntimeException(
            'Die Modul-Konfigurationsgruppe konnte nicht ermittelt werden.'
        );
    }

    xtc_db_perform(
        TABLE_CONFIGURATION,
        array(
            'configuration_key'      => 'MODULE_MY_MODULE_NEW_OPTION',
            'configuration_value'    => 'true',
            'configuration_group_id' => $groupId,
            'sort_order'             => self::getNextConfigurationSortOrder(
                $groupId
            ),
            'date_added'             => 'now()',
        )
    );
}
```

Ist die Gruppe tatsächlich nicht vorhanden, muss die Migrationsklasse denselben
freien-Gruppenprozess wie `install()` verwenden:

1. freie `configuration_group_id` ermitteln;
2. freie Gruppensortierung ermitteln;
3. Gruppe in `TABLE_CONFIGURATION_GROUP` anlegen;
4. Gruppen-ID im Modulkonfigurationsschlüssel speichern;
5. neue Konfigurationseinträge mit dieser ID anlegen.

Bei einer normalen Update-Migration sollte jedoch bereits eine Gruppe vorhanden
sein. Eine fehlende Gruppe kann auf eine unvollständige Installation hindeuten.
Dann ist ein hartes, verständliches Abbrechen meist sicherer als das unbemerkt
Anlegen einer zweiten Modulgruppe.

```php
private static function configurationExists(string $key): bool
{
    $query = xtc_db_query(
        "SELECT configuration_id
         FROM " . TABLE_CONFIGURATION . "
         WHERE configuration_key = '" . xtc_db_input($key) . "'
         LIMIT 1"
    );

    return $query && xtc_db_num_rows($query) > 0;
}
```

Auch Konfigurationsmigrationen müssen idempotent sein. Der neue Eintrag wird nur
angelegt, wenn sein Konfigurationsschlüssel noch nicht existiert. Gruppen-ID und
Sortierung werden aus dem konkreten Shopzustand ermittelt, nicht als feste
Zahlen angenommen.

## 16. Datenmigration

Schemaänderung und Datenänderung sollten getrennt gedacht werden:

1. neue Spalte/Tabelle anlegen;
2. vorhandene Daten transformieren;
3. Constraints oder finale Indizes ergänzen.

Beispiel:

```php
if (!self::columnExists(self::TABLE_DATA, 'status')) {
    // 1. Spalte anlegen
}

// 2. Altwerte in neue Struktur überführen
xtc_db_query(
    "UPDATE " . self::quoteIdentifier(self::TABLE_DATA)
    . " SET status = 'active'
       WHERE legacy_active = 1
         AND (status IS NULL OR status = '')"
);
```

Die `WHERE`-Bedingung verhindert, dass der Vorgang bei erneutem Ausführen bereits
migrierte Daten überschreibt.

## 17. Entfernen alter Strukturen

Das sofortige Löschen alter Spalten ist riskant, wenn alte Installationen oder
andere Modulversionen noch darauf zugreifen.

Sicherer Ablauf:

1. Neue Struktur ergänzen.
2. Daten kopieren.
3. Code auf neue Struktur umstellen.
4. Mindestens ein Release lang kompatibel bleiben.
5. In späterer Version alte Struktur kontrolliert entfernen.

Vor dem Löschen ebenfalls prüfen:

```php
if (self::columnExists(self::TABLE_DATA, 'legacy_value')) {
    xtc_db_query(
        "ALTER TABLE " . self::quoteIdentifier(self::TABLE_DATA)
        . " DROP COLUMN legacy_value"
    );
}
```

## 18. `allowed_paths`

Die Migrationsklasse selbst ist Teil des Modulpakets. Ihr Verzeichnis muss in
der `allowed_paths`-Konfiguration stehen.

Bei gemeinsamer Ablage:

```php
'allowed_paths' => array(
    'admin/includes/classes/BX/Github',
    // weitere Pfade
),
```

Damit dürfen sowohl `BxGithubModuleUpdater.php` als auch
`BxBlockContactsMigrations.php` aktualisiert werden.

## 19. Tests einer Migration

Jede neue Migration sollte mindestens gegen folgende Zustände getestet werden:

1. Tabelle/Spalte/Index fehlt vollständig;
2. Struktur ist teilweise vorhanden;
3. Struktur ist bereits vollständig migriert;
4. Wiederholter Aufruf;
5. Direktupdate über mehrere Versionen;
6. Update von der direkt vorherigen Version;
7. alte Daten und leere Werte;
8. DB-Fehler beziehungsweise fehlende Berechtigung;
9. bestehende angepasste Händlerdaten;
10. Sicherung vor DDL-kritischem Produktivupdate.

Beispiel für Direktupdate:

```php
BxMyModuleMigrations::migrate('1.1.0', '1.5.0');
```

Danach nochmals:

```php
BxMyModuleMigrations::migrate('1.1.0', '1.5.0');
```

Der zweite Lauf darf nicht scheitern und darf keine strukturellen oder
inhaltlichen Duplikate erzeugen.

## 20. Releasepraxis

Beim Hinzufügen einer Migration:

1. Code der Migrationsklasse bearbeiten.
2. Version des Blocks festlegen, beispielsweise `1.6.0`.
3. Methode `migrateTo160()` ergänzen.
4. In `migrate()` an richtiger Stelle aufrufen.
5. Migrationspfad in `allowed_paths` aufnehmen.
6. Migration auf leerem und bereits migriertem Teststand ausführen.
7. Releaseversion setzen und neues signiertes Release erzeugen.
8. Produktiv erst nach Backup aktualisieren.

Ein bereits veröffentlichter Migrationsblock wird später nicht still verändert.
Zusätzliche Änderungen erhalten einen neuen Versionsblock.

## 21. Häufige Fehler

### `ALTER TABLE` wird ohne Prüfung ausgeführt

Folge beim erneuten Lauf:

```text
Duplicate column name
```

Lösung: `columnExists()`.

### `INSERT` wird bei jedem Update wiederholt

Folge: doppelte Konfigurationen oder Datensätze.

Lösung: Unique Key, Existenzprüfung oder Upsert.

### Migrationsklasse ist nicht im Release

Die neu verteilten Moduldateien erwarten eine Klasse, die im Paket fehlt.

Lösung:

- Datei in `src/...` ablegen;
- Pfad in `allowed_paths` erlauben;
- Release-ZIP kontrollieren.

### Falscher Klassenname oder Dateipfad

Beide müssen zum `require_once` passen:

```text
Datei: BxBlockContactsMigrations.php
Klasse: BxBlockContactsMigrations
Reconciliation: BxBlockContactsMigrations::migratePending()
```

### DDL wurde nach dem Versionssprung nicht berücksichtigt

Migrationen müssen jede Zwischenversion prüfen. Nicht nur:

```php
if ($from !== $to)
```

sondern:

```php
crossesVersion($from, $to, '1.2.0')
```

## 22. Kurzfassung

Eine gute Migrationsklasse ist:

- modulspezifisch;
- versionsbasiert;
- idempotent;
- prüfbar;
- wiederholbar;
- auf aufeinanderfolgende und übersprungene Versionen ausgelegt;
- vorsichtig bei DDL;
- eindeutig in Dateiname, Klasse und `migratePending()`-Einbindung;
- Bestandteil der Release-Allowlist.

Der Updater ist für die sichere Verteilung verantwortlich. Die Migrationsklasse
ist für die fachlich korrekte Änderung der Datenbank des einzelnen Moduls
verantwortlich.
