# GitHub-Update in ein modified eCommerce Modul integrieren

Diese Anleitung beschreibt, wie ein bestehendes Systemmodul mit dem
eigenständigen `BxGithubModuleUpdater` ausgestattet wird. Als Referenz dient
`bx-block-contacts`. Die Updater-Klasse arbeitet ohne MMLC und kann in mehreren
Modulen identisch verwendet werden.

## 1. Funktionsumfang
uns Punkt 10 in 
Der Updater bietet:

- Prüfung des neuesten GitHub-Releases;
- Cache der Release-Informationen;
- Versionsvergleich;
- Anzeige eines Updatehinweises im Modul-Backend;
- Download eines exakt benannten Release-ZIPs;
- SHA-256-Prüfung des kompletten Archivs;
- Ed25519-Signaturprüfung des Manifests;
- Prüfung jeder einzelnen Datei;
- dynamische Abbildung des Modified-Admin-Verzeichnisses;
- modulbezogene Pfad-Allowlist;
- Backup, Rollback und Recovery;
- requestübergreifende, modulspezifische Datenbankreconciliation;
- Auditlog des Updateergebnisses.

Die Installation wird nie automatisch ausgelöst. Der Administrator muss den
Updatevorgang ausdrücklich per POST-Formular starten.

## 2. Voraussetzungen

### Server

- PHP 8.0 oder neuer;
- `ext-curl`;
- `ext-zip`;
- `ext-sodium`;
- Schreibrecht im Shop und im Updater-Cache;
- funktionierender Zugriff auf `api.github.com`.

Der Updater verwendet `CURLOPT_XFERINFOFUNCTION`, sofern die PHP-Konstante
verfügbar ist. Auf PHP 8.0 und 8.1 greift er auf `CURLOPT_PROGRESSFUNCTION`
zurück. Die Mindestversion von PHP 8.0 bleibt damit gültig.

Im Modulcode darf außerdem kein PHP-8.1-Feature wie `never` verwendet werden.

Bei XAMPP müssen die Erweiterungen in der von Apache verwendeten `php.ini`
aktiv sein. Sodium nur in CLI-PHP reicht nicht aus.

### GitHub

- Repository enthält eine `moduleinfo.json`;
- Repository besitzt `.github/workflows/release.yml`;
- Releases werden über SemVer-Tags wie `v1.2.5` erzeugt;
- GitHub Secret `BX_RELEASE_SIGNING_KEY` ist vorhanden;
- dasselbe Secret kann als Organization Secret für mehrere Repositories
  verwendet werden;
- der passende Public Key wird im Modul hinterlegt.

Der Private Key darf niemals in Sourcecode, Repository, ZIP, Log oder
Dokumentation gelangen.

## 3. Pflichtdateien im Modulpaket

```text
module/
  moduleinfo.json
  .github/workflows/release.yml
  src/
    admin/
      includes/
        classes/
          BX/
            Github/
              BxGithubModuleUpdater.php
        modules/
          system/
            my_module.php
    ...
```

Die zentrale Updater-Klasse kann kopiert werden, muss danach aber mit der
installierten und getesteten Version identisch bleiben.

## 4. `moduleinfo.json` vorbereiten

Mindestanforderungen:

```json
{
  "archiveName": "bx-codemaster/my-module",
  "srcDir": "src",
  "version": "auto",
  "php": {
    "version": "^8.0"
  }
}
```

Der Workflow verwendet:

- den letzten Bestandteil von `archiveName` als Modulname;
- `srcDir` als Paketwurzel;
- den Git-Tag als Releaseversion.

Aus `bx-codemaster/my-module` und Tag `v1.2.3` entsteht:

```text
my-module-1.2.3.zip
my-module-1.2.3.zip.sha256
```

## 5. Release-Workflow einrichten

Die vorhandene `.github/workflows/release.yml` kann für alle Module verwendet
werden. Sie:

1. akzeptiert ausschließlich gültige SemVer-Tags `v*`;
2. liest `srcDir` und `archiveName` aus `moduleinfo.json`;
3. sammelt ausschließlich versionierte Dateien aus `srcDir`;
4. erzeugt `.bx-module-manifest.json`;
5. signiert das exakte Manifest mit dem GitHub Secret;
6. erzeugt `.bx-module-manifest.sig`;
7. packt ZIP, Manifest und Signatur;
8. erzeugt die SHA-256-Prüfsumme;
9. veröffentlicht ZIP und Prüfsumme als GitHub-Release-Assets.

Repository-Workflow und Modulpaket müssen denselben Signing Public Key
verwenden.

## 6. `allowed_paths` festlegen

`allowed_paths` ist für jedes Modul individuell zu konfigurieren.

Es definiert, welche Pfade das Release installieren, ersetzen oder als
veraltet löschen darf. Ein Manifestpfad außerhalb dieser Liste führt zum
Abbruch des gesamten Updates vor `applyPackage()`.

### Regeln

- Jede Hauptdatei und jeder Verzeichnisbaum des Moduls muss abgedeckt sein.
- Möglichst spezifische Pfade verwenden.
- Nicht pauschal `admin`, `includes`, `lang` oder `templates` freigeben.
- Pfade im Release verwenden immer den logischen Präfix `admin/`.
- Die tatsächliche Admin-Installation wird über `DIR_ADMIN` abgebildet.
- Windows-Pfadvergleich erfolgt case-insensitive.

### Beispiel

```php
'allowed_paths' => array(
    'admin/my_module.php',
    'admin/images/icons/heading/my_module.png',
    'admin/includes/classes/BX/MyModule',
    'admin/includes/classes/BX/Github',
    'admin/includes/extra/css/my_module.php',
    'admin/includes/extra/functions/my_module.php',
    'admin/includes/extra/javascript/my_module.php',
    'admin/includes/extra/menu/my_module.php',
    'admin/includes/modules/system/my_module.php',
    'includes/classes/my_module',
    'includes/extra/database_tables/my_module.php',
    'includes/extra/hooks/my_module.php',
    'lang/english/modules/system/my_module.php',
    'lang/german/modules/system/my_module.php',
    'media/content/my_module.php',
    'templates/tpl_modified_nova/css/my_module.css',
    'templates/tpl_modified_nova/javascript/my_module.js.php',
    'templates/tpl_modified_nova/module/my_module.html',
),
```

Wenn ein Modul einen später neu benötigten Bereich nicht in `allowed_paths`
enthält, muss dieser Bereich bewusst freigegeben und mit einem neuen Release
verteilt werden.

## 7. Systemmodul erweitern

### 7.1 Eigenschaften ergänzen

```php
public array $properties = array();

private ?BxGithubModuleUpdater $githubUpdater = null;
private ?array $updateStatus = null;
```

Die Eigenschaften sind typisiert und benötigen PHP 8.

### 7.2 Konstruktor erweitern

```php
public function __construct()
{
    require_once DIR_FS_ADMIN
        . 'includes/classes/BX/Github/BxGithubModuleUpdater.php';

    // Bestehende Modulinitialisierung:
    $this->code = 'my_module';
    $this->title = MODULE_MY_MODULE_TITLE;
    $this->description = MODULE_MY_MODULE_DESC;
    $this->version = '1.0.0';

    if (defined('MODULE_MY_MODULE_VERSION')) {
        $this->updateStatus = $this->getGithubUpdater()->checkForUpdate();

        if (
            $this->updateStatus['has_update']
            && !empty($this->updateStatus['zip_url'])
            && !empty($this->updateStatus['sha256'])
        ) {
            $this->properties['button_update'] = $this->createUpdateButton(
                $this->updateStatus['latest_version'],
                true
            );
        }
    }
}
```

`properties['button_update']` stellt den Button in der Modulübersicht bereit.
`checkForUpdate()` selbst installiert noch nichts.

### 7.3 `getGithubUpdater()` hinzufügen

```php
private function getGithubUpdater(): BxGithubModuleUpdater
{
    if ($this->githubUpdater === null) {
        $this->githubUpdater = new BxGithubModuleUpdater(array(
            'owner'         => 'bx-codemaster',
            'repo'          => 'my-module',
            'module_name'   => 'my-module',
            'module_prefix' => 'MY_MODULE',
            'admin_dir'     => DIR_ADMIN,
            'public_key'    => 'BASE64_PUBLIC_KEY',
            'allowed_paths' => array(
                // modulspezifische Pfade
            ),
            'cache_ttl_seconds' => 3600,
            'check_connect_timeout' => 2,
            'check_timeout' => 5,
            'current_version'   => version_compare(
                $this->version,
                MODULE_MY_MODULE_VERSION,
                '>'
            ) ? $this->version : MODULE_MY_MODULE_VERSION,
        ));
    }

    return $this->githubUpdater;
}
```

`module_prefix` führt zu dieser Konfigurationsvariable:

```text
MODULE_<PREFIX>_VERSION
```

Beispiel:

```text
module_prefix = MY_MODULE
Konfiguration = MODULE_MY_MODULE_VERSION
```

Die Codeversion beschreibt die ausgelieferte Releaseversion. Die Datenbank
speichert zusätzlich einen eigenen Schemastand. Dadurch erkennt eine manuell
neu eingespielte Version sowohl die neue Releaseversion als auch noch fehlende
Datenbankmigrationen.

Bei jedem Release muss die im Systemmodul hinterlegte Codeversion auf die neue
Tagversion gesetzt werden, beispielsweise:

```php
$this->version = '1.2.6';
```

Der Schema-Stand `SCHEMA_VERSION` wird dagegen nur erhöht, wenn tatsächlich eine
neue Datenbankänderung hinzukommt.

### 7.4 Update ausführen

Die Installation erfolgt über ein POST-Formular.

Der Updater installiert im Update-Request ausschließlich die Dateien:

```php
if (($_POST['bx_updater_action'] ?? '') === 'install') {
    global $messageStack;

    $result = $this->getGithubUpdater()->installLatestUpdate();

    $messageStack->add_session(
        $result['message'],
        $result['ok'] ? 'success' : 'error'
    );

    xtc_redirect(xtc_href_link(
        FILENAME_MODULE_EXPORT,
        'set=system&module=' . $this->code . '&action=edit'
    ));
}
```

Die neue Migrationsklasse darf nicht direkt als Callback an denselben
`installLatestUpdate()`-Aufruf übergeben werden. PHP hat die vorherige Klasse
sonst bereits aus dem alten Release geladen. Stattdessen wird die Migration im
nächsten Request beim Laden der neuen Systemmodulklasse gestartet:

```php
require_once DIR_FS_ADMIN
    . 'includes/classes/BX/Github/BxMyModuleMigrations.php';

BxMyModuleMigrations::migratePending();
```

## 8. Button und Hinweis einbauen

### Updatebutton

Die Methode erzeugt entweder:

- ein eigenes CSRF-geschütztes Formular für die Modulübersicht;
- oder nur einen Submit-Button innerhalb des vorhandenen Edit-Formulars.

```php
private function createUpdateButton(string $version, bool $standaloneForm): string
{
    $formStart   = '';
    $formEnd     = '';
    $actionField = ' name="bx_updater_action" value="install"';

    if ($standaloneForm) {
        $formStart = xtc_draw_form(
            'my_module_update',
            FILENAME_MODULE_EXPORT,
            'set=system&module=' . $this->code . '&action=custom'
        );
        $formEnd = '</form>';
        $actionField = '';
    }

    return $formStart
        . ($standaloneForm ? xtc_draw_hidden_field('bx_updater_action', 'install') : '')
        . '<button type="submit"' . $actionField
        . ' class="button btnbox" onclick="return confirm(\''
        . sprintf(
            MODULE_MY_MODULE_UPDATE_CONFIRM,
            htmlspecialchars($version, ENT_QUOTES, 'UTF-8')
        )
        . '\');">'
        . sprintf(
            MODULE_MY_MODULE_UPDATE_BUTTON,
            htmlspecialchars($version, ENT_QUOTES, 'UTF-8')
        )
        . '</button>'
        . $formEnd;
}
```

### Updatehinweis

```php
private function createUpdateNotice(): string
{
    if (
        $this->updateStatus === null
        || !$this->updateStatus['has_update']
        || empty($this->updateStatus['latest_version'])
    ) {
        return '';
    }

    return '<div class="messageStackWarning">'
        . sprintf(
            MODULE_MY_MODULE_UPDATE_AVAILABLE,
            htmlspecialchars($this->updateStatus['latest_version'], ENT_QUOTES, 'UTF-8')
        )
        . (
            !empty($this->updateStatus['zip_url'])
            && !empty($this->updateStatus['sha256'])
                ? $this->createUpdateButton(
                    $this->updateStatus['latest_version'],
                    false
                )
                : ' ' . MODULE_MY_MODULE_UPDATE_NOT_INSTALLABLE
        )
        . '</div>';
}
```

In `display()` muss der Hinweis vor dem bisherigen HTML stehen:

```php
public function display(): array
{
    $html = $this->createUpdateNotice();
    $html .= '<!-- bisherige Modul-Ausgabe -->';

    return array('text' => $html);
}
```

## 9. Sprachdefinitionen ergänzen

Deutsch:

```php
define(
    'MODULE_MY_MODULE_UPDATE_AVAILABLE',
    'Version %s ist verfügbar. Vorhandene Moduldateien werden gesichert.'
);
define('MODULE_MY_MODULE_UPDATE_BUTTON', 'Auf Version %s aktualisieren');
define(
    'MODULE_MY_MODULE_UPDATE_CONFIRM',
    'Soll das Modul jetzt auf Version %s aktualisiert werden?'
);
define(
    'MODULE_MY_MODULE_UPDATE_NOT_INSTALLABLE',
    'Das Release ist noch nicht installierbar.'
);
```

Englische Entsprechungen im englischen Sprachmodul ergänzen.

## 10. Datenbankmigrationen

Der Updater ändert Datenbankstrukturen nicht automatisch. Das Modul führt beim
Laden des nächsten Requests `migratePending()` aus. Diese Methode vergleicht
den persistent gespeicherten Schema-Stand mit der im neu eingespielten Code
definierten `SCHEMA_VERSION`.

Wichtig: Kein `installLatestUpdate()`-Callback mit einer Migrationsklasse
übergeben, die durch das Update selbst ersetzt wird.

### Idempotente Migrationsklasse

```php
final class BxMyModuleMigrations
{
    public const SCHEMA_VERSION = '1.4.0';

    public static function migratePending(): void
    {
        $storedVersion = self::readStoredSchemaVersion();
        if (version_compare($storedVersion, self::SCHEMA_VERSION, '>=')) {
            return;
        }

        self::migrate($storedVersion, self::SCHEMA_VERSION);
        self::writeStoredSchemaVersion(self::SCHEMA_VERSION);
    }

    public static function migrate(string $from, string $to): void
    {
        if (
            version_compare($from, '1.3.0', '<')
            && version_compare($to, '1.3.0', '>=')
        ) {
            xtc_db_query(
                "CREATE TABLE IF NOT EXISTS bx_my_module_data (
                    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    created_at DATETIME NOT NULL,
                    PRIMARY KEY (id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );
        }

        if (
            version_compare($from, '1.4.0', '<')
            && version_compare($to, '1.4.0', '>=')
        ) {
            $query = xtc_db_query(
                "SHOW COLUMNS FROM bx_my_module_data LIKE 'status'"
            );

            if (!$query || xtc_db_num_rows($query) === 0) {
                xtc_db_query(
                    "ALTER TABLE bx_my_module_data
                     ADD COLUMN status VARCHAR(20)
                     NOT NULL DEFAULT 'active'"
                );
            }
        }
    }
}
```

### Regeln für Migrationen

- `CREATE TABLE IF NOT EXISTS` verwenden.
- Vor `ALTER TABLE` prüfen, ob Spalte oder Index fehlt.
- Keine Daten unnötig mehrfach einfügen.
- Alle übersprungenen Zwischenversionen berücksichtigen.
- `migratePending()` und jeder Migrationsblock müssen mehrfach ausführbar sein.
- Der gespeicherte Schema-Stand ist von der Releaseversion getrennt.
- Migrationen werden im Folge-Request mit der neu geladenen Klasse ausgeführt.
- Der Updater kann Dateien zurückrollen, aber nicht jede MySQL-DDL rückgängig
  machen.
- Vor einem produktiven DB-Update eine normale Shop-/Datenbanksicherung
  erstellen.

Eine ausführliche Referenz zu Entwurf, Aufbau, Idempotenz, Fehlerbehandlung und
Tests findet sich in [Migrationsklassen.md](Migrationsklassen.md).

## 11. Dynamischer Admin-Ordner

Die Release-Pakete verwenden immer den logischen Pfad `admin/`.

Beispiel:

```text
admin/includes/modules/system/my_module.php
```

Der Updater erhält:

```php
'admin_dir' => DIR_ADMIN,
```

Bei einem Shop mit `admin_1x2y3z/` wird daraus:

```text
admin_1x2y3z/includes/modules/system/my_module.php
```

Die `allowed_paths` bleiben weiterhin im Format `admin/...`.

## 12. Cache und Erkennungsintervall

Standardmäßig werden erfolgreiche Release-Prüfungen eine Stunde gecacht.

```php
'cache_ttl_seconds' => 3600,
```

Für häufig aktualisierte Module beispielsweise:

```php
'cache_ttl_seconds' => 900,
```

Der zulässige Mindestwert in der Klasse ist 60 Sekunden. Fehler werden höchstens
fünf Minuten gecacht.

Der normale Release-Check verwendet kurze Timeouts, damit der Konstruktor die
Adminseite nicht blockiert:

```php
'check_connect_timeout' => 2,
'check_timeout' => 5,
```

Download und Installation behalten längere, separate Zeitlimits. Bei fehlender
GitHub-Erreichbarkeit wird der kurze Fehlerstatus gecacht und nach fünf Minuten
erneut geprüft.

Der Cache liegt unter:

```text
cache/bx_github_updater/<module-name>/
```

Für Nginx sollte dieses Verzeichnis außerhalb des Webroots liegen oder per
Serverkonfiguration gegen HTTP-Zugriffe geschützt werden.

## 13. Sicherheitsprüfung eines Releases

Der Updater akzeptiert ein Release nur, wenn:

- Repository und Assetname stimmen;
- Tag eine gültige SemVer-Version ist;
- ZIP und SHA-256-Prüfsumme zusammenpassen;
- `.bx-module-manifest.json` vorhanden ist;
- `.bx-module-manifest.sig` vorhanden ist;
- Manifest mit dem eingebauten Public Key verifiziert wird;
- jeder Manifestpfad erlaubt ist;
- keine undecklarierten ZIP-Dateien existieren;
- keine sicheren Pfadregeln verletzt werden;
- jede Paketdatei ihren Manifest-Hash besitzt.

Die Signatur verhindert ein stilles Fallback auf ein manipuliertes, aber
formal gültiges ZIP.

## 14. Erster Release mit Updater-Funktion

Der Updater kann sich erst dann selbst aktualisieren, wenn er bei der
installierten Version bereits vorhanden ist.

Für ein bisheriges Modul:

1. Updater-Klasse und Integration normal in das Modul übernehmen.
2. Public Key hinterlegen.
3. Workflow mit Signing einrichten.
4. Erstes signiertes Release erzeugen.
5. Dieses Release einmal regulär installieren, etwa per MMLC oder manuell.
6. Ab dem nächsten Release läuft die Selbstaktualisierung über das Modul-Backend.

## 15. Versionsablauf

Beispiel:

```powershell
git add .
git commit -m "Updater integriert"
git push origin master

git tag v1.2.3
git push origin v1.2.3
```

Der Workflow:

- erzeugt und signiert das Paket;
- veröffentlicht das Release;
- lädt ZIP und `.sha256` als Assets hoch.

Nur durch Committen entsteht noch kein Release. Der Tag muss gepusht werden.

## 16. Testcheckliste

Vor dem ersten produktiven Modulrelease:

- [ ] Public Key ist korrekt Base64-kodiert.
- [ ] Private Key ist nur als GitHub Secret vorhanden.
- [ ] Apache/Web-PHP lädt `ext-sodium`.
- [ ] `ext-curl` und `ext-zip` sind aktiv.
- [ ] `DIR_ADMIN` wird übergeben.
- [ ] Alle tatsächlichen `src/`-Pfade sind in `allowed_paths` enthalten.
- [ ] `current_version` entspricht der gespeicherten Modulversion.
- [ ] Updatebutton erscheint bei neuem Release.
- [ ] ZIP enthält `.bx-module-manifest.json`.
- [ ] ZIP enthält `.bx-module-manifest.sig`.
- [ ] Signaturprüfung ist erfolgreich.
- [ ] Testshop mit umbenanntem Admin-Verzeichnis wurde geprüft.
- [ ] Erfolgreiches Update setzt die neue Datenbankversion.
- [ ] Migration ist idempotent.
- [ ] Manipulierte Signatur wird abgelehnt.
- [ ] Nicht erlaubter Manifestpfad bricht das komplette Update ab.
- [ ] Auditlog wird geschrieben.
- [ ] Bei Rollback werden neu angelegte leere Verzeichnisse entfernt.
- [ ] Neue Verzeichnisse bleiben auch für den Webserver lesbar.
- [ ] Keine privaten Schlüssel befinden sich im Repository oder Release.

## 17. Typische Fehlerbilder

### `public_key is required`

Der Public Key wurde nicht in `getGithubUpdater()` übergeben oder ist leer.

### `The PHP extension sodium is not available`

CLI- und Apache-PHP unterscheiden sich. Sodium in der Web-PHP-Umgebung
aktivieren und Apache vollständig neu starten.

### `Manifest path is not allowed`

Eine Datei liegt in einem Bereich, der nicht in `allowed_paths` enthalten ist.
Nicht vorschnell einen sehr breiten Ordner freigeben, sondern den benötigten
konkreten Pfad ergänzen.

### `Manifest signature missing/invalid`

Mögliche Ursachen:

- Workflow hat keine Signatur erzeugt;
- falsches GitHub Secret;
- Public Key und Private Key gehören nicht zusammen;
- Manifest wurde nach der Signierung verändert.

### Kein Updatebutton

Prüfen:

- ist das GitHub-Release wirklich veröffentlicht;
- besitzt es exakt benanntes ZIP und SHA-Asset;
- ist die installierte Version älter;
- ist der Release-Cache noch gültig;
- ist `button_update` im Modulkontext verfügbar;
- ist eine Alert-/UI-Ausgabe vorhanden.

## 18. Betriebliche Empfehlungen

- GitHub Secret möglichst als Organization Secret;
- Workflow-Dateien per Ruleset/CODEOWNERS schützen;
- erforderliche Reviewer für Signing-Environment aktivieren;
- Public Key nicht mit einem privaten Arbeitskey verwechseln;
- Schlüsselrotation geplant durchführen;
- Release-Tags und Workflow-Änderungen nur geschützt zulassen;
- vor wichtigen Updates Shop und Datenbank sichern;
- Update-Auditlog regelmäßig archivieren;
- Cache nicht öffentlich zugänglich machen.

## 19. Referenzimplementierung

`bx-block-contacts` enthält die vollständige Beispielintegration:

- `getGithubUpdater()`
- optional `BxMyModuleMigrations::migrate()`
- `createUpdateButton()`
- `createUpdateNotice()`
- Erweiterung des Konstruktors
- POST-Updateausführung
- dynamischer Admin-Pfad
- verpflichtender Public Key
- `allowed_paths`
- Sprachmeldungen
- gemeinsamer Release-Workflow

Bei neuen Modulen sollte diese Implementierung als technische Vorlage dienen;
insbesondere Repositoryname, Prefix, Allowed Paths, Sprachkonstanten und
Migrationslogik müssen angepasst werden.
