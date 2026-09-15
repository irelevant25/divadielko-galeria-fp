<?php
/**
 * Zálohy databázy: všetky tabuľky do jedného súboru JSON v storage/backups/
 * (z webu neprístupné — storage/.htaccess). Vytvárajú sa ručne v administrácii →
 * Zálohy (alebo php setup.php --backup) a samy pred každou migráciou (db_migrate).
 *
 * Súbor:
 *   {
 *   "_meta": {…},                ← na 2. riadku, zoznam záloh tak nemusí čítať celý súbor
 *   "tables": {"tabuľka": [riadky…], …}
 *   }
 * Staršie zálohy (z nasadení pred touto funkciou) majú len {"tabuľka": [riadky…]}.
 *
 * Obrázky a videá (assets/) v zálohe nie sú — to sú súbory na hostingu.
 */

declare(strict_types=1);

const BACKUP_FILE_PATTERN = '/^backup-[a-z0-9-]+\.json$/';

/** Priečinok so zálohami (vytvorí ho; zálohy z nasadení, ktoré boli priamo v storage/, presunie). */
function backup_dir(): string
{
    $dir = ROOT . '/storage/backups';
    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException(t('bk_err_dir'));
        }
        foreach (glob(ROOT . '/storage/backup-*.json') ?: [] as $old) {
            @rename($old, $dir . '/' . basename($old));
        }
    }

    return $dir;
}

/** Tabuľky aktuálnej databázy. */
function backup_tables(): array
{
    return array_column(db_all(
        "SELECT table_name FROM information_schema.tables
          WHERE table_schema = current_schema() AND table_type = 'BASE TABLE'
          ORDER BY table_name"
    ), 'table_name');
}

/**
 * Štruktúra databázy: tabuľka → stĺpec → ['type' => typ, 'required' => musí byť vyplnený].
 * Podľa nej sa pri obnove pozná, či záloha sedí na dnešnú databázu.
 */
function backup_structure(): array
{
    $out = [];
    foreach (db_all(
        "SELECT table_name, column_name, data_type, is_nullable, column_default
           FROM information_schema.columns
          WHERE table_schema = current_schema()
          ORDER BY table_name, column_name"
    ) as $c) {
        $out[$c['table_name']][$c['column_name']] = [
            'type'     => $c['data_type'],
            'required' => $c['is_nullable'] === 'NO' && $c['column_default'] === null,
        ];
    }

    return $out;
}

/** Odtlačok štruktúry — rovnaký odtlačok = záloha sedí na databázu presne. */
function backup_schema_hash(array $structure): string
{
    $lines = [];
    foreach ($structure as $table => $columns) {
        ksort($columns);
        foreach ($columns as $name => $column) {
            $lines[] = $table . '.' . $name . ':' . (is_array($column) ? $column['type'] : $column);
        }
    }
    sort($lines);

    return substr(hash('sha256', implode("\n", $lines)), 0, 16);
}

/** Len názvy stĺpcov (to, čo sa dá porovnať aj so staršou zálohou bez odtlačku). */
function backup_columns(array $structure): array
{
    return array_map(static fn ($columns) => array_map('strval', array_keys($columns)), $structure);
}

/**
 * Vytvorí zálohu celej databázy. $reason: 'rucne' alebo 'pred-<číslo migrácie>';
 * $by: kto (meno používateľa; null = automaticky). Vracia údaje ako backup_info().
 */
function backup_create(string $reason = 'rucne', string $note = '', ?string $by = null, array $pending = []): array
{
    $dir    = backup_dir();
    $reason = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($reason)), '-') ?: 'rucne';
    $base   = 'backup-' . date('Ymd-His') . '-' . $reason;
    $name   = $base . '.json';
    for ($i = 2; is_file($dir . '/' . $name); $i++) {
        $name = $base . '-' . $i . '.json';
    }
    $tmp = $dir . '/.' . $name . '.tmp';

    $pdo = db();
    $fh  = @fopen($tmp, 'wb');
    if ($fh === false) {
        throw new RuntimeException(t('bk_err_write'));
    }

    // Všetky tabuľky z jedného okamihu (inak by sa pri súčasnej úprave mohli rozísť).
    $pdo->beginTransaction();
    try {
        $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
        $tables = backup_tables();
        $counts = [];
        foreach ($tables as $table) {
            $counts[$table] = (int) db_value('SELECT count(*) FROM "' . $table . '"');
        }
        $migrations = array_column(db_all('SELECT name FROM migrations ORDER BY name'), 'name');
        $structure  = backup_structure();

        $meta = [
            'format'     => 2,
            'created_at' => date('c'),
            'created_by' => $by,
            'reason'     => $reason,
            'note'       => mb_substr(trim($note), 0, 200),
            'pending'    => array_values($pending),
            'migration'  => $migrations ? end($migrations) : null,
            'schema'     => backup_schema_hash($structure),
            'columns'    => backup_columns($structure),
            'tables'     => $counts,
        ];
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;
        $ok = fwrite($fh, "{\n\"_meta\": " . json_encode($meta, $flags) . ",\n\"tables\": {\n") !== false;

        // Tabuľka po tabuľke, záznam na riadok — dá sa aj prečítať.
        foreach ($tables as $i => $table) {
            $rows = db_all('SELECT * FROM "' . $table . '"');
            $json = $rows ? "[\n" . implode(",\n", array_map(static fn ($row) => json_encode($row, $flags), $rows)) . "\n]" : '[]';
            $ok = $ok && fwrite($fh, json_encode($table) . ': ' . $json . ($i < count($tables) - 1 ? ",\n" : "\n")) !== false;
        }
        $ok = $ok && fwrite($fh, "}\n}\n") !== false;
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        fclose($fh);
        @unlink($tmp);
        throw $e;
    }

    if (!fclose($fh) || !$ok || !@rename($tmp, $dir . '/' . $name)) {
        @unlink($tmp);
        throw new RuntimeException(t('bk_err_write'));
    }
    @chmod($dir . '/' . $name, 0640);

    return backup_info($dir . '/' . $name);
}

/** Zálohy, najnovšia prvá. */
function backup_list(): array
{
    $out = [];
    foreach (glob(backup_dir() . '/backup-*.json') ?: [] as $path) {
        $out[] = backup_info($path);
    }
    usort($out, static fn (array $a, array $b): int => $b['time'] <=> $a['time'] ?: strcmp($b['name'], $a['name']));

    return $out;
}

/**
 * Údaje o zálohe: kedy, prečo, kto, poznámka, verzia databázy (posledná migrácia)
 * a počty záznamov v tabuľkách.
 */
function backup_info(string $path): array
{
    $name = basename($path);
    $info = [
        'name' => $name, 'size' => (int) filesize($path), 'time' => (int) filemtime($path), 'reason' => '',
        'note' => '', 'by' => null, 'pending' => [], 'migration' => null, 'tables' => [], 'legacy' => false,
        'schema' => null, 'columns' => [],
    ];
    // Čas z názvu — FTP pri kopírovaní mení čas súboru.
    if (preg_match('/(\d{8})-(\d{6})/', $name, $m) && ($t = DateTime::createFromFormat('Ymd His', $m[1] . ' ' . $m[2]))) {
        $info['time'] = $t->getTimestamp();
    }

    $fh = @fopen($path, 'rb');
    $line = '';
    if ($fh !== false) {
        fgets($fh);
        $line = (string) fgets($fh);
        fclose($fh);
    }

    if (strncmp($line, '"_meta": ', 9) === 0) {
        $meta = json_decode(rtrim(substr($line, 9), ",\r\n "), true);
        if (is_array($meta)) {
            $info['reason']    = (string) ($meta['reason'] ?? '');
            $info['note']      = (string) ($meta['note'] ?? '');
            $info['by']        = $meta['created_by'] ?? null;
            $info['pending']   = (array) ($meta['pending'] ?? []);
            $info['migration'] = $meta['migration'] ?? null;
            $info['tables']    = array_map('intval', (array) ($meta['tables'] ?? []));
            $info['schema']    = $meta['schema'] ?? null;
            $info['columns']   = (array) ($meta['columns'] ?? []);
        }
        if ($info['columns']) {
            return $info;
        }
    } else {
        // Staršia záloha {"tabuľka": [...]} z nasadení pred touto funkciou.
        $info['legacy'] = true;
        if (preg_match('/^backup-pred-(\d+)/', $name, $m)) {
            $info['reason'] = 'pred-' . $m[1];
        }
    }

    // Bez zoznamu stĺpcov v hlavičke (staršia záloha) ho zistíme zo súboru — sú malé.
    if ($info['size'] <= 50 * 1024 * 1024) {
        $data   = json_decode((string) file_get_contents($path), true);
        $tables = is_array($data) ? (isset($data['tables']) && is_array($data['tables']) ? $data['tables'] : $data) : [];
        unset($tables['_meta']);
        foreach ($tables as $table => $rows) {
            if (!is_array($rows)) {
                continue;
            }
            $info['tables'][$table] = count($rows);
            $info['columns'][$table] = isset($rows[0]) && is_array($rows[0]) ? array_keys($rows[0]) : [];
        }
        if ($info['migration'] === null) {
            $names = array_column((array) ($tables['migrations'] ?? []), 'name');
            sort($names);
            $info['migration'] = $names ? end($names) : null;
        }
    }

    return $info;
}

/**
 * Dá sa zo zálohy obnoviť dnešná databáza?
 *   'same'    — rovnaká štruktúra (odtlačok sedí), obnoví sa všetko
 *   'ok'      — iná verzia, ale obnoviť sa dá; čo sa preskočí, je v zozname
 *   'blocked' — nedá sa (záloha nemá povinný stĺpec alebo sa nedá prečítať)
 *
 * @return array{level: string, missing_tables: string[], extra_tables: string[], missing_columns: array, extra_columns: array, blockers: string[]}
 */
function backup_compat(array $info, ?array $structure = null): array
{
    $structure ??= backup_structure();
    $out = ['level' => 'ok', 'missing_tables' => [], 'extra_tables' => [], 'missing_columns' => [], 'extra_columns' => [], 'blockers' => []];

    if (!$info['columns']) {
        $out['level'] = 'blocked';
        $out['blockers'][] = t('bk_block_unreadable');
        return $out;
    }
    if ($info['schema'] !== null && $info['schema'] === backup_schema_hash($structure)) {
        $out['level'] = 'same';
        return $out;
    }

    foreach ($structure as $table => $columns) {
        if (in_array($table, backup_skipped_tables(), true)) {
            continue;
        }
        if (!array_key_exists($table, $info['columns'])) {
            $out['missing_tables'][] = $table; // po obnove ostane prázdna
            continue;
        }
        $backupColumns = (array) $info['columns'][$table];
        $missing = array_values(array_diff(array_keys($columns), $backupColumns));
        if ($missing) {
            $out['missing_columns'][$table] = $missing;
            // Povinný stĺpec (bez predvolenej hodnoty) sa nedá doplniť — len keď má záloha riadky.
            foreach ($missing as $column) {
                if ($columns[$column]['required'] && !empty($info['tables'][$table])) {
                    $out['blockers'][] = t('bk_block_column', $table, $column);
                }
            }
        }
    }
    foreach ($info['columns'] as $table => $backupColumns) {
        if (in_array($table, backup_skipped_tables(), true)) {
            continue;
        }
        if (!isset($structure[$table])) {
            if (!empty($info['tables'][$table])) {
                $out['extra_tables'][] = $table; // taká tabuľka už nie je — preskočí sa
            }
            continue;
        }
        $extra = array_values(array_diff((array) $backupColumns, array_keys($structure[$table])));
        if ($extra) {
            $out['extra_columns'][$table] = $extra;
        }
    }

    if ($out['blockers']) {
        $out['level'] = 'blocked';
    }

    return $out;
}

/** Tabuľky, ktoré sa nikdy neobnovujú: prehľad migrácií a pokusy o prihlásenie. */
function backup_skipped_tables(): array
{
    return ['migrations', 'login_attempts'];
}

/** Poradie tabuliek podľa cudzích kľúčov (rodič pred dieťaťom). */
function backup_table_order(array $tables): array
{
    $parents = [];
    foreach (db_all(
        "SELECT tc.table_name AS child, ccu.table_name AS parent
           FROM information_schema.table_constraints tc
           JOIN information_schema.constraint_column_usage ccu ON ccu.constraint_name = tc.constraint_name
          WHERE tc.constraint_type = 'FOREIGN KEY' AND tc.table_schema = current_schema()"
    ) as $fk) {
        if ($fk['child'] !== $fk['parent']) {
            $parents[$fk['child']][] = $fk['parent'];
        }
    }

    $out  = [];
    $seen = [];
    $visit = static function (string $table) use (&$visit, &$out, &$seen, $parents, $tables): void {
        if (isset($seen[$table]) || !in_array($table, $tables, true)) {
            return;
        }
        $seen[$table] = true; // označíme skôr než ideme na rodičov — prípadný kruh sa nezacyklí
        foreach ($parents[$table] ?? [] as $parent) {
            $visit($parent);
        }
        $out[] = $table; // rodičia sú už v zozname
    };
    foreach ($tables as $table) {
        $visit($table);
    }

    return $out;
}

/**
 * Obnoví databázu zo zálohy. Pred obnovou urobí zálohu súčasného stavu.
 * $withUsers = false: účty a heslá ostanú také, aké sú teraz.
 *
 * @return array{restored: array<string, int>, skipped: string[], safety: string}
 */
function backup_restore(string $name, bool $withUsers = false, ?string $by = null): array
{
    $path = backup_path($name);
    $info = backup_info($path);
    $structure = backup_structure();
    $compat = backup_compat($info, $structure);
    if ($compat['level'] === 'blocked') {
        throw new InvalidArgumentException(t('bk_err_incompatible') . ' ' . implode(' ', $compat['blockers']));
    }

    $data   = json_decode((string) file_get_contents($path), true);
    $tables = is_array($data) ? (isset($data['tables']) && is_array($data['tables']) ? $data['tables'] : $data) : null;
    if (!is_array($tables)) {
        throw new RuntimeException(t('bk_err_read'));
    }
    unset($tables['_meta']);

    $skip = backup_skipped_tables();
    if (!$withUsers) {
        $skip[] = 'users';
    }
    $targets = backup_table_order(array_values(array_diff(array_keys($structure), $skip)));

    // Poistka: stav pred obnovou sa dá vrátiť späť.
    $safety = backup_create('pred-obnovou', t('bk_restore_note', $name), $by)['name'];

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->exec('TRUNCATE TABLE ' . implode(', ', array_map(static fn ($t) => '"' . $t . '"', $targets)) . ' RESTART IDENTITY CASCADE');

        $restored = [];
        foreach ($targets as $table) {
            $columns = array_keys($structure[$table]);
            $rows    = is_array($tables[$table] ?? null) ? $tables[$table] : [];
            $count   = 0;
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $use = array_values(array_intersect(array_keys($row), $columns)); // stĺpce, ktoré dnes existujú
                if (!$use) {
                    continue;
                }
                $values = [];
                foreach ($use as $column) {
                    $value = $row[$column];
                    $values[] = is_bool($value) ? ($value ? 't' : 'f')
                        : (is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : $value);
                }
                db_exec(
                    'INSERT INTO "' . $table . '" (' . implode(', ', array_map(static fn ($c) => '"' . $c . '"', $use)) . ')'
                    . ' VALUES (' . implode(', ', array_fill(0, count($use), '?')) . ')',
                    $values
                );
                $count++;
            }
            // číslovanie ďalších záznamov nadviaže na obnovené
            if (isset($structure[$table]['id'])) {
                db_exec('SELECT setval(pg_get_serial_sequence(?, \'id\'), coalesce((SELECT max(id) FROM "' . $table . '"), 0) + 1, false)', [$table]);
            }
            $restored[$table] = $count;
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw new RuntimeException(t('bk_err_restore', $e->getMessage()), 0, $e);
    }

    return ['restored' => $restored, 'skipped' => array_values(array_intersect($skip, array_keys($structure))), 'safety' => $safety];
}

/** Cesta k existujúcej zálohe podľa názvu (iné názvy ako backup-….json neprejdú). */
function backup_path(string $name): string
{
    $path = backup_dir() . '/' . $name;
    if (!preg_match(BACKUP_FILE_PATTERN, $name) || !is_file($path)) {
        throw new InvalidArgumentException(t('bk_err_name'));
    }

    return $path;
}

function backup_delete(string $name): void
{
    if (!@unlink(backup_path($name))) {
        throw new RuntimeException(t('bk_err_write'));
    }
}

/** Číslo verzie databázy z názvu migrácie („009_…sql" → „009"). */
function backup_version(?string $migration): string
{
    return $migration !== null && preg_match('/^(\d+)/', $migration, $m) ? $m[1] : '—';
}
