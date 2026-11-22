# Roadmap: Zentraler BX Module Updater

## 1. Ausgangslage

Der selbst aktualisierende Modul-Stack enthält derzeit in jedem Produktmodul
eine Kopie derselben Datei:

```text
admin/includes/classes/BX/Github/BxGithubModuleUpdater.php
```

Ein Release von `bx-block-contacts`, `bx-advent-calendar` oder einem weiteren
Modul kann diese gemeinsame Datei daher überschreiben.

Das ist funktional vorübergehend akzeptabel, solange alle Produktmodule exakt
denselben Updaterstand enthalten. Es erzeugt jedoch folgende Risiken:

- mehrere unabhängige Kopien derselben Sicherheitslogik;
- unterschiedliche Updaterstände je Modulrepository;
- eine Produktinstallation kann die zentrale Klasse unerwartet auf- oder
  abwerten;
- Sicherheitsfixes müssen in jedes Modulrepository übernommen werden;
- Releasegröße und Redundanz steigen;
- Tests müssen für jede Kopie wiederholt werden;
- es gibt keinen eindeutigen Eigentümer der Datei.

Ziel ist daher ein eigenständiges Modul mit eindeutiger Verantwortung:

```text
bx-module-updater
```

Die Produktmodule enthalten danach nur noch ihre modulspezifische Integration
und Datenbankmigration.

## 2. Zielarchitektur

### Zentrales Updater-Modul

```text
bx-module-updater/
  .github/workflows/release.yml
  moduleinfo.json
  src/
    admin/
      includes/
        classes/
          BX/
            Github/
              BxGithubModuleUpdater.php
              BxModuleMigration.php
        modules/
          system/
            bx_module_updater.php
    lang/
      german/
        modules/
          system/
            bx_module_updater.php
      english/
        modules/
          system/
            bx_module_updater.php
  docs/
```

Das zentrale Modul verantwortet:

- GitHub-Release-Prüfung;
- TLS- und Redirectvalidierung;
- SHA-256-Prüfung;
- Manifest- und Ed25519-Prüfung;
- ZIP-Validierung;
- Pfad-Allowlist;
- Admin-Verzeichnis-Mapping;
- Lock, Backup, Recovery und Commit-Marker;
- Auditlog;
- optionale gemeinsame UI-/Button-Helfer;
- gemeinsame Migrationshilfsmethoden;
- Selbstupdate des Updater-Kerns.

### Produktmodule

Beispiel:

```text
bx-block-contacts/
  src/
    admin/
      includes/
        classes/
          BX/
            Github/
              BxBlockContactsMigrations.php
        modules/
          system/
            bx_block_contacts.php
```

Jedes Produktmodul verantwortet:

- eigenes GitHub-Repository und Release;
- Public Key beziehungsweise verwendeten Schlüssel;
- Allowed Paths;
- aktuelle installierte Version;
- spezifische Migrationsklasse;
- Updatehinweis und Buttons im Modulkontext;
- Aufruf des zentralen Updaters;
- Übergabe von Migrationen.

Die Produktmodule liefern die zentrale `BxGithubModuleUpdater.php` nach dem
Übergang nicht mehr aus.

## 3. Nicht zentralisieren

Folgende Inhalte bleiben modulspezifisch:

- `BxBlockContactsMigrations.php`;
- `BxAdventCalendarMigrations.php`;
- Allowed Paths;
- Release-Repository;
- Modulprefix;
- versionsspezifische SQL-Operationen;
- Sprachtexte der Produktmodule;
- Updatekonfiguration und Produktversion.

Eine zentrale Migrationsdatenbank mit Migrationen aller Produkte wäre schwerer
zu warten und würde unabhängige Releases verhindern.

## 4. Zentrale Hilfsklasse

Nur allgemeine, produktneutrale Hilfslogik darf zentralisiert werden:

```php
abstract class BxModuleMigration
{
    protected static function crossesVersion(...)
    protected static function tableExists(...)
    protected static function columnExists(...)
    protected static function indexExists(...)
    protected static function configurationExists(...)
    protected static function configurationGroupId(...)
    protected static function nextConfigurationSortOrder(...)
    protected static function quoteIdentifier(...)
}
```

Eine Modulmigration erweitert diese Hilfsklasse:

```php
final class BxBlockContactsMigrations extends BxModuleMigration
{
    public static function migrate(string $from, string $to): void
    {
        self::migrateTo120($from, $to);
    }
}
```

Diese Basisklasse darf keine Kenntnis von konkreten Produktmodulen besitzen.

## 5. Modulabhängigkeit

### `moduleinfo.json`

Produktmodule deklarieren langfristig:

```json
{
  "require": {
    "bx-codemaster/bx-module-updater": "^1.0"
  }
}
```

Der exakte Constraints-Modus muss mit dem verwendeten Dependency-Resolver
kompatibel bleiben.

### Laufzeitprüfung

Produktmodule prüfen vor einem Update:

```php
if (!class_exists(BxGithubModuleUpdater::class)) {
    // Produktiv: verständlichen Hinweis anzeigen
    return;
}
```

Eine minimale Versionsprüfung sollte ebenfalls möglich sein:

```php
if (
    !defined(BxGithubModuleUpdater::class . '::VERSION')
    || version_compare(
        BxGithubModuleUpdater::VERSION,
        '1.0.0',
        '<'
    )
) {
    // bx-module-updater installieren oder aktualisieren
}
```

## 6. Zentraler Ownership-Nachweis

Der Updater erhält eine eindeutige Version:

```php
final class BxGithubModuleUpdater
{
    public const VERSION = '1.0.0';
}
```

Nur `bx-module-updater` darf diese Klasse nach dem Übergang verändern.

Bei Übergangsreleases kann ein Paket optional prüfen, ob die vorhandene
Klasse neuer ist:

```php
if (
    class_exists(BxGithubModuleUpdater::class)
    && version_compare(
        BxGithubModuleUpdater::VERSION,
        $this->version,
        '>'
    )
) {
    // vorhandene zentrale Version nicht überschreiben
}
```

Langfristig entfällt diese Prüfung aus Produktpaketen, weil die Datei dort nicht
mehr enthalten ist.

## 7. Phasenplan

### Phase 0: Vorbereitung und Bestandsaufnahme

Ziel: Alle derzeit ausgelieferten Updaterkopien erfassen.

Aufgaben:

- alle Module mit `BxGithubModuleUpdater.php` suchen;
- SHA-256 jeder Kopie ermitteln;
- unterschiedliche Implementationen identifizieren;
- aktuelle Referenzimplementierung festlegen;
- für jedes Modul Allowed Paths dokumentieren;
- vorhandene Produktmigrationen erfassen;
- PHP-Mindestversion der Produktmodule prüfen;
- Signing-Status und Public Keys je Modul prüfen.

Ergebnis:

```text
inventory/
  module
  updater_version
  updater_hash
  public_key
  allowed_paths
  has_migrations
  workflow_status
```

### Phase 1: Updater stabilisieren

Ziel: Eine eingefrorene, getestete gemeinsame Version definieren.

Aufgaben:

- letzten Sicherheitsstand in einer Referenzkopie konsolidieren;
- `BxGithubModuleUpdater::VERSION` ergänzen;
- Header-/Release-Dokumentation vervollständigen;
- öffentliche Konfigurationsschnittstelle definieren;
- Kompatibilitätsmatrix dokumentieren;
- Shared-Installer-Tests erstellen;
- Crash-, Signatur-, Rollback- und Pfadtests automatisieren;
- Referenzrelease erstellen.

Akzeptanzkriterien:

- signiertes Testrelease wird installiert;
- manipulierter Pfad wird abgelehnt;
- manipulierte Signatur wird abgelehnt;
- Commit-Recovery rollt kein erfolgreiches Update zurück;
- benutzerdefinierter Adminordner funktioniert;
- XAMPP-Sodium und Apache-SAPI sind dokumentiert;
- alle Modulkopien haben denselben Updaterhash.

### Phase 2: `bx-module-updater` anlegen

Ziel: Den gemeinsamen Code in ein eigenes Repository und Modul überführen.

Aufgaben:

- Repository `bx-codemaster/bx-module-updater` erstellen;
- Updaterklasse als Eigentümerpaket übernehmen;
- Systemmodul für Status, Tests und Selbstupdate integrieren;
- eigenen Release-Workflow und Signing verwenden;
- `moduleinfo.json` mit Abhängigkeiten erstellen;
- Installationslogik bewusst minimal halten;
- zentrale Basisklasse `BxModuleMigration` hinzufügen;
- Dokumente `Roadmap.md`, `Anleitung.md` und
  `Migrationsklassen.md` mitführen.

Das zentrale Modul darf keine produktmodulspezifischen SQL-Migrationen enthalten.

### Phase 3: Übergangsrelease aller Produktmodule

Ziel: Abhängigkeit einführen, ohne bestehende Installationen zu brechen.

Alle Produktmodule erhalten ein Übergangsrelease, das:

- weiterhin eine kompatible Bootstrap-Kopie des Updaters enthält;
- `bx-module-updater` als Abhängigkeit deklariert;
- erkennt, ob `bx-module-updater` installiert ist;
- die zentrale Klasse nicht überschreibt, falls diese neuer ist;
- eigene Migrationsklasse weiterhin ausliefert.

Empfohlene Reihenfolge:

1. `bx-module-updater` veröffentlichen;
2. Referenzmodul `bx-block-contacts` migrieren;
3. kleines, risikoarmes weiteres Modul migrieren;
4. komplexes Modul mit Templates und Datenbank testen;
5. restliche Module in Gruppen migrieren.

### Phase 4: Zentralen Updater erzwingen

Ziel: Produktmodule können ohne zentralen Updater keine Updatefunktion starten.

Die Produktmodule zeigen bei fehlender Klasse:

```text
Das zentrale Modul bx-module-updater ist erforderlich.
Bitte zuerst bx-module-updater installieren.
```

Dateien werden dabei weiterhin über normale Modulinstallation verteilt, aber
der GitHub-Selbstupdatepfad bleibt gesperrt.

### Phase 5: Updaterkopien aus Produktmodulen entfernen

Ziel: Jede Produktinstallation erhält den Updater nur noch vom Eigentümernmodul.

Aufgaben:

- `BxGithubModuleUpdater.php` aus Produktmodul-`src` entfernen;
- Allowed Paths aus Produktmodulen um den Updaterpfad bereinigen;
- `class_exists()`-Prüfung beibehalten;
- `migratePending()` im Folge-Request modulbezogen ausführen;
- Migrationsklasse im Produktmodul behalten;
- zentralen Updater im Manifest und in Abhängigkeiten verankern.

Besonderheit:

Beim Update auf eine Version, die den Updater nicht mehr ausliefert, darf die
bestehende installierte Datei nicht gelöscht werden. Das Produktmanifest kennt
die Datei nicht mehr und kann sie daher nicht als eigene veraltete Datei
entfernen. Die zentrale Klasse bleibt bestehen.

### Phase 6: Gemeinsame UI abstrahieren

Ziel: Wiederkehrenden Code in Produktmodulen reduzieren.

Optional können zentrale Helfer bereitgestellt werden:

- Updatehinweis rendern;
- CSRF-geschützten Button erzeugen;
- Releaseinformationen formatieren;
- Fehlermeldungen standardisieren;
- Common-Statusobjekt ausgeben.

Nicht zentralisieren:

- modulspezifische `getGithubUpdater()`-Konfiguration;
- modulspezifische Migration;
- fachliche Produkttexte.

## 8. Übergangslogik in Produktmodulen

### Vor Übergang

```php
require_once DIR_FS_ADMIN
    . 'includes/classes/BX/Github/BxGithubModuleUpdater.php';
```

### Übergangsphase

```php
if (!class_exists(BxGithubModuleUpdater::class)) {
    $bootstrapPath = DIR_FS_ADMIN
        . 'includes/classes/BX/Github/BxGithubModuleUpdater.php';

    if (!is_file($bootstrapPath)) {
        throw new RuntimeException(
            'bx-module-updater ist nicht installiert.'
        );
    }

    require_once $bootstrapPath;
}
```

Dabei wird nach dem Übergang nur noch die zentrale, installierte Klasse geladen.

### Nach Übergang

```php
if (!class_exists(BxGithubModuleUpdater::class)) {
    // administrativen Installationshinweis anzeigen
    return;
}
```

Produktmodule enthalten keinen `require_once` mehr auf eine eigene ausgelieferte
Updaterkopie.

## 9. Migrationsklassen

Jedes Produktmodul behält seine eigene Migrationsklasse:

```text
admin/includes/classes/BX/Github/BxBlockContactsMigrations.php
admin/includes/classes/BX/Github/BxAdventCalendarMigrations.php
```

Nur diese Klassen werden vom Produktrelease aktualisiert.

Die zentrale `BxModuleMigration.php` liefert:

- Versionsvergleich;
- Tabellenprüfung;
- Spaltenprüfung;
- Indexprüfung;
- Konfigurationsprüfung;
- dynamische Gruppen-ID;
- nächste Sortierung;
- sichere Identifier-Prüfung.

## 10. Versions- und Kompatibilitätsmatrix

Für den zentralen Updater sollte eine Matrix gepflegt werden:

```text
bx-module-updater  Produktmodule  PHP
1.0.x              >= 1.0.0       >= 8.0
1.1.x              >= 1.2.0       >= 8.0
```

Die Matrix dokumentiert:

- unterstützte Konfigurationsoptionen;
- erwartetes Releaseformat;
- Manifest-Schema;
- Pflichtsignatur;
- Recovery-Format;
- Admin-Pfad-Unterstützung.

## 11. Releaseformat

Das Releaseformat bleibt gleich:

```text
<module-name>-<version>.zip
.bx-module-manifest.json
.bx-module-manifest.sig
```

Produktmodule dürfen `BxGithubModuleUpdater.php` nach Phase 5 nicht mehr
enthalten. Der Updater kann anhand des Manifests kontrollieren, dass keine
Produktdatei einen fremden zentralen Pfad überschreibt.

Recommended rule:

```text
admin/includes/classes/BX/Github/BxGithubModuleUpdater.php
```

ist in `allowed_paths` eines Produktmoduls nicht mehr zulässig.

## 12. Backup- und Rollback-Strategie

Der zentrale Updater bleibt für Rollback verantwortlich.

Produktmodule:

- dürfen nur eigene Migrationsklassen und Produktdateien liefern;
- dürfen keine zentrale Recoveryklasse überschreiben;
- führen ihre modulspezifische `migratePending()`-Reconciliation im Folge-Request aus.

Fehlerfälle:

1. Produktdatei-Update schlägt fehl → Updater rollt Produktdateien zurück.
2. Produktmigration schlägt im Folge-Request fehl → die neue Klasse bleibt
   geladen, der Schema-Stand bleibt zurück und wird beim nächsten Request
   erneut reconciliert. Die Migration muss idempotent sein.
3. Zentraler Updater fällt aus → normaler Modulbetrieb soll soweit möglich
   weiter funktionieren, nur Selbstupdate wird gesperrt.
4. Zentraler Updater wird beschädigt → `bx-module-updater` muss gezielt
   neu installiert werden.

## 13. Tests

### Gemeinsamer Updater

- Release-Erkennung;
- exakte Assetzuordnung;
- SHA-256;
- Ed25519;
- unsigniertes Paket;
- falsches Manifest;
- nicht erlaubter Pfad;
- umbenannter Adminordner;
- Windows- und POSIX-Rename;
- unterbrochene Stage;
- committed Stage;
- Auditlog;
- Cache und Rate-Limit;
- Symfony-/Nginx-Webroot-Schutz.

### Jedes Produktmodul

- `allowed_paths` vollständig;
- Migrationsklasse vorhanden;
- Migrationsklasse idempotent;
- direkte und übersprungene Versionssprünge;
- Public Key korrekt;
- Repository und Modulname korrekt;
- Release enthält keine zentrale Updaterdatei;
- Update bei fehlendem Updater zeigt verständliche Meldung.

### Übergang

- bestehende Installation mit alter eingebetteter Updaterkopie;
- zentrale neue Version bereits installiert;
- produktmodul enthält versehentlich ältere Kopie;
- zentrale Klasse wird nicht herabgestuft;
- produktmodul wird anschließend ohne eigene Updaterdatei aktualisiert.

## 14. Dokumentation

Für jedes Produktmodul:

- Integrationsanleitung;
- Allowed-Paths-Liste;
- Public-Key-Verweis;
- Migrationsklassen-Dokument;
- Abhängigkeit von `bx-module-updater`;
- Repository-Workflow.

Für das zentrale Modul:

- interne Updaterarchitektur;
- Releaseformat;
- Manifest- und Signaturprozess;
- Recovery und Commitlogik;
- Versionsmatrix;
- Integrations-SDK für Produktmodule.

## 15. Rollout-Reihenfolge

Empfohlene Reihenfolge für reale Module:

1. `bx-module-updater`
2. `bx-block-contacts`
3. `bx-advent-calendar`
4. weiteres kleines Modul ohne komplexe Datenbank
5. Module mit Templates und mehreren Admin-Dateien
6. Module mit umfangreichen Migrationen
7. kostenpflichtige oder kritische Module
8. restliche Module

Nach jedem Schritt:

- Update in Testshop;
- Update in Shop mit umbenanntem Adminordner;
- Migration wiederholt ausführen;
- Release-Signatur prüfen;
- installiertes Manifest kontrollieren;
- Auditlog prüfen.

## 16. Sicherheitsregeln für GitHub

- gemeinsames oder modulbezogenes Signing Secret bewusst wählen;
- Signing Environment mit Required Reviewers schützen;
- Tag- und Workflow-Rulesets setzen;
- `.github/workflows/` per CODEOWNERS schützen;
- Private Key nicht in Logs, Dateien oder ZIPs ausgeben;
- Public Key und Klassenstand versionieren;
- Key-Rotation dokumentieren;
- Produktworkflows dürfen keinen fremden Updaterpfad überschreiben.

## 17. Entscheidungspunkte

Vor Start der Umsetzung sind folgende Entscheidungen zu treffen:

1. Soll ein einzelner Organisation-Signing-Key alle Module signieren?
2. Soll jedes Produktmodul eigene `allowed_paths`-Konfiguration behalten?
3. Ist `bx-module-updater` ein sichtbares Systemmodul oder primär eine Library?
4. Soll es den Updater selbst und alle Produktmodule prüfen?
5. Benötigen Produktmodule eine minimale Updater-Bootstrap-Datei?
6. Wie wird die Mindestversion des Updaters im Dependency-Resolver ausgedrückt?
7. Wie lange bleibt die Übergangsphase mit eingebetteten Kopien bestehen?
8. Sollen Update-Backups dauerhaft oder nur temporär aufbewahrt werden?
9. Wie werden Auditlogs langfristig rotiert?
10. Welche Module werden zuerst migriert?

## 18. Akzeptanzkriterien

Die Architektur gilt als erfolgreich umgesetzt, wenn:

- Produktreleases keine `BxGithubModuleUpdater.php` mehr enthalten;
- `bx-module-updater` die einzige Eigentümerquelle der Klasse ist;
- alle Produktmodule ihre Migrationsklasse selbst enthalten;
- Updates von `bx-block-contacts` und weiteren Modulen weiterhin funktionieren;
- fehlender zentraler Updater eine verständliche Meldung erzeugt;
- ein älteres Produktrelease die installierte zentrale Version nicht herabstuft;
- ein zentrales Updater-Update alle Produktintegrationen kompatibel hält;
- dynamischer Adminordner, Signaturen, Backup und Recovery unverändert bestehen;
- alle Tests der bestehenden Referenzimplementierung auf das zentrale Modul
  übertragen wurden.

## 19. Empfohlene nächste drei Schritte

1. Eine Kopie des aktuellen Updaters in das neue Repository
   `bx-module-updater` übernehmen und `VERSION` einführen.
2. `bx-block-contacts` als Pilotmodul umstellen, aber die eingebettete Updaterkopie
   noch für Bootstrap-Kompatibilität behalten.
3. Ein zweites Modul mit anderer Struktur integrieren, um Allowed Paths,
   Migrationsklassen und zentralen Updater unter realen Bedingungen zu prüfen.

Danach kann der Updater aus den Produktreleases entfernt werden, sobald die
Übergangsinstallation ausreichend getestet ist.
