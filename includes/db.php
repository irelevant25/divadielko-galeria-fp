<?php
/**
 * PostgreSQL cez PDO. Spojenie sa otvára až pri prvom dotaze, takže dočasné
 * stránky bez databázy fungujú aj vtedy, keď databáza nebeží.
 */

declare(strict_types=1);

function db(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $cfg = config('db');
        $pdo = new PDO((string) $cfg['dsn'], (string) $cfg['user'], (string) $cfg['password'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_TIMEOUT            => 5,
        ]);
        $pdo->exec("SET TIME ZONE 'Europe/Bratislava'");
    }

    return $pdo;
}

/** Beží databáza? Výsledok si pamätá, aby sa pri výpadku nečakalo na timeout opakovane. */
function db_available(): bool
{
    static $ok = null;

    if ($ok === null) {
        // Databáza ešte nie je nastavená (nový hosting) — ani sa nepokúšame pripájať.
        if ((string) config('db.user') === '') {
            return $ok = false;
        }
        try {
            db()->query('SELECT 1 FROM migrations LIMIT 1');
            $ok = true;
        } catch (Throwable $e) {
            error_log('[db] ' . $e->getMessage());
            $ok = false;
        }
    }

    return $ok;
}

function db_all(string $sql, array $params = []): array
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll();
}

function db_one(string $sql, array $params = []): ?array
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();

    return $row === false ? null : $row;
}

function db_value(string $sql, array $params = [])
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $value = $stmt->fetchColumn();

    return $value === false ? null : $value;
}

function db_exec(string $sql, array $params = []): int
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    return $stmt->rowCount();
}

/** Súbory migrácií v poradí, v akom sa aplikujú: názov súboru → cesta. */
function db_migration_files(): array
{
    $files = glob(__DIR__ . '/migrations/*.sql') ?: [];
    sort($files);

    return array_combine(array_map('basename', $files), $files);
}

/** Migrácie, ktoré na túto databázu ešte nebežali (nová verzia kódu je nahraná, databáza nie). */
function db_pending_migrations(): array
{
    $done = array_column(db_all('SELECT name FROM migrations'), 'name');

    return array_values(array_diff(array_keys(db_migration_files()), $done));
}

/**
 * Prehľad pre administráciu → Nastavenia → Databáza. Každá migrácia:
 * name, applied_at (unixový čas, null = čaká), about = prvý odsek komentára na začiatku
 * súboru. Navyše tie, ktoré v databáze sú, ale súbor tu nie je ('file' => false) —
 * databázu zdieľa aj iná inštalácia a tá má novší kód.
 */
function db_migrations(): array
{
    $applied = [];
    foreach (db_all('SELECT name, extract(epoch FROM applied_at)::bigint AS at FROM migrations') as $row) {
        $applied[$row['name']] = (int) $row['at'];
    }

    $list = [];
    foreach (db_migration_files() as $name => $file) {
        $about = [];
        foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (!preg_match('/^--\s?(.*)$/', $line, $m) || trim($m[1]) === '') {
                break;
            }
            $about[] = trim($m[1]);
        }
        $list[$name] = ['name' => $name, 'applied_at' => $applied[$name] ?? null, 'about' => implode(' ', $about), 'file' => true];
    }
    foreach (array_diff_key($applied, $list) as $name => $at) {
        $list[$name] = ['name' => $name, 'applied_at' => $at, 'about' => '', 'file' => false];
    }
    ksort($list);

    return array_values($list);
}

/**
 * Aplikuje chýbajúce migrácie z includes/migrations (podľa názvu súboru).
 * Vracia zoznam práve aplikovaných. Pred aktualizáciou existujúcej databázy
 * urobí zálohu (názov súboru vráti v $backup); keď sa nepodarí, migrácie
 * sa nespustia.
 */
function db_migrate(?string &$backup = null): array
{
    $pdo = db();
    $pdo->exec('CREATE TABLE IF NOT EXISTS migrations (
        name       varchar(200) PRIMARY KEY,
        applied_at timestamptz NOT NULL DEFAULT now()
    )');

    $done  = array_column(db_all('SELECT name FROM migrations'), 'name');
    $files = db_migration_files();

    $pending = array_values(array_diff(array_keys($files), $done));
    if ($pending && $done) { // nová inštalácia (prázdna databáza) zálohu nepotrebuje
        require_once __DIR__ . '/backup.php';
        try {
            $backup = backup_create('pred-' . (preg_match('/^(\d+)/', $pending[0], $m) ? $m[1] : 'migraciou'), '', null, $pending)['name'];
        } catch (Throwable $e) {
            throw new RuntimeException(t('bk_err_before_migration', $e->getMessage()), 0, $e);
        }
    }

    $applied = [];
    foreach ($files as $name => $file) {
        if (in_array($name, $done, true)) {
            continue;
        }

        $pdo->beginTransaction();
        try {
            $pdo->exec((string) file_get_contents($file));
            db_exec('INSERT INTO migrations (name) VALUES (?)', [$name]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw new RuntimeException("Migrácia $name zlyhala: " . $e->getMessage(), 0, $e);
        }
        $applied[] = $name;
    }

    return $applied;
}
