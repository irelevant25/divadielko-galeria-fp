<?php
/**
 * Static checks for Divadielko Galéria — the project has no test suite, so this is the
 * cheap safety net to run after every change:
 *
 *   php .claude/skills/dg-dev/scripts/check.php [--root=<dir>] [--no-db] [--unused]
 *
 *   lint        php -l on every PHP file, node --check on JS (when node is installed)
 *   migrations  file names NNN_snake_case.sql, contiguous numbering, no duplicates
 *   lang        every translation key the code refers to exists in includes/lang.php
 *               (a missing key is not an error at runtime — t() prints the raw key on the page)
 *   schema      includes/entities.php agrees with the database: tables, columns, column types,
 *               updated_at / sort / deleted_at, NOT NULL columns covered by required fields,
 *               soft-deleted entities listed in cms_archive()   (read-only; skipped with --no-db)
 *
 * --root    which install to check (default: this repository). Point it at the test copy
 *           (testsite.php path) to validate the schema that the migrations produce from scratch.
 * --unused  also list lang.php keys nothing seems to use (heuristic — read before deleting).
 *
 * Exit code 0 = all good, 1 = something failed.
 */

declare(strict_types=1);

$opts = getopt('', ['root:', 'no-db', 'unused']);
$root = rtrim(str_replace('\\', '/', (string) realpath($opts['root'] ?? dirname(__DIR__, 4))), '/');
if ($root === '' || !is_file($root . '/includes/entities.php')) {
    fwrite(STDERR, "Not a Divadielko Galéria install: " . ($opts['root'] ?? dirname(__DIR__, 4)) . PHP_EOL);
    exit(2);
}

$failures = 0;
$section = static function (string $name): void {
    echo PHP_EOL, '== ', $name, PHP_EOL;
};
$fail = static function (string $message) use (&$failures): void {
    $failures++;
    echo '  FAIL  ', $message, PHP_EOL;
};
$pass = static function (string $message): void {
    echo '  ok    ', $message, PHP_EOL;
};
$note = static function (string $message): void {
    echo '  note  ', $message, PHP_EOL;
};

/** Source files: what git knows about (tracked + new, not ignored); without git, a directory walk. */
$sourceFiles = static function (string $root): array {
    $out = [];
    $cmd = 'git -C ' . escapeshellarg($root) . ' ls-files --cached --others --exclude-standard';
    $list = @shell_exec($cmd . (DIRECTORY_SEPARATOR === '\\' ? ' 2>NUL' : ' 2>/dev/null'));
    if (is_string($list) && trim($list) !== '') {
        foreach (preg_split('/\R/', trim($list)) as $rel) {
            if (is_file($root . '/' . $rel)) {
                $out[] = $rel;
            }
        }
        return $out;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        $rel = ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen($root))), '/');
        if (!preg_match('~^(\.git|assets|assets_original|storage|tools)/~', $rel) && $rel !== 'includes/config.local.php') {
            $out[] = $rel;
        }
    }
    return $out;
};

$files = $sourceFiles($root);
$code  = array_values(array_filter($files, static fn (string $f): bool => !str_starts_with($f, '.claude/')));

// ── lint ─────────────────────────────────────────────────────────────────────

$section('lint');
$php = array_values(array_filter($files, static fn (string $f): bool => str_ends_with($f, '.php'))); // the tooling under .claude/ too
$bad = 0;
foreach ($php as $rel) {
    $out = [];
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($root . '/' . $rel) . ' 2>&1', $out, $status);
    if ($status !== 0) {
        $bad++;
        $first = (string) (array_values(array_filter(array_map('trim', $out)))[0] ?? 'parse error');
        $fail($rel . ': ' . str_replace($root . '/', '', str_replace('\\', '/', $first)));
    }
}
if (!$bad) {
    $pass(count($php) . ' PHP files parse (PHP ' . PHP_VERSION . ' — production runs a newer PHP, see dg-deploy)');
}
$js = array_values(array_filter($code, static fn (string $f): bool => str_ends_with($f, '.js')));
$out = [];
@exec('node --version 2>&1', $out, $status);
if ($status === 0) {
    $bad = 0;
    foreach ($js as $rel) {
        $out = [];
        exec('node --check ' . escapeshellarg($root . '/' . $rel) . ' 2>&1', $out, $status);
        if ($status !== 0) {
            $bad++;
            $fail($rel . ': ' . trim(implode(' ', array_slice($out, 0, 4))));
        }
    }
    if (!$bad) {
        $pass(count($js) . ' JS files parse');
    }
} else {
    $note('node is not installed — JS syntax not checked');
}

// ── migrations ───────────────────────────────────────────────────────────────

$section('migrations');
$names = array_map('basename', glob($root . '/includes/migrations/*.sql') ?: []);
sort($names);
$numbers = [];
foreach ($names as $name) {
    if (!preg_match('/^(\d{3})_[a-z0-9_]+\.sql$/', $name, $m)) {
        $fail("$name — expected NNN_snake_case.sql");
        continue;
    }
    $numbers[] = (int) $m[1];
}
if (count($numbers) !== count(array_unique($numbers))) {
    $fail('two migrations share a number — they run in file-name order, give the new one the next free number');
}
if ($numbers && $numbers !== range(1, count($numbers))) {
    $fail('numbering is not contiguous 001…' . sprintf('%03d', count($numbers)) . ': ' . implode(', ', $numbers));
}
if ($numbers && $numbers === range(1, count($numbers))) {
    $pass(count($numbers) . ' migrations, 001…' . sprintf('%03d', max($numbers)));
}

// ── lang ─────────────────────────────────────────────────────────────────────

$section('lang');
$entities = require $root . '/includes/entities.php';
$langFile = require $root . '/includes/lang.php';
$lang     = $langFile[array_key_first($langFile)];

$used    = []; // key → where
$dynamic = []; // prefix → where
$jsWords = []; // every quoted word in JS — cms.js also looks texts up indirectly: s(t[1]), S.months
$use = static function (string $key, string $where) use (&$used): void {
    $used[$key][$where] = true;
};

foreach ($code as $rel) {
    if (!preg_match('/\.(php|js)$/', $rel) || $rel === 'includes/lang.php') {
        continue;
    }
    $isJs = str_ends_with($rel, '.js');
    foreach (file($root . '/' . $rel) as $n => $line) {
        $at = $rel . ':' . ($n + 1);
        if ($isJs) {
            if (preg_match_all('~\bs\(\'([a-z0-9_]+)\'~', $line, $m)) {
                foreach ($m[1] as $key) {
                    $use('js_' . $key, $at);
                }
            }
            if (preg_match_all('~\'([a-z0-9_]+)\'|\bS\.([a-z0-9_]+)~', $line, $m)) {
                foreach (array_filter(array_merge($m[1], $m[2])) as $word) {
                    $jsWords['js_' . $word] = true;
                }
            }
            continue;
        }
        if (preg_match_all('~\bt(?:_has)?\(\s*\'([a-z0-9_]+)\'\s*[,)]~', $line, $m)) {
            foreach ($m[1] as $key) {
                $use($key, $at);
            }
        }
        if (preg_match_all('~\bt(?:_has)?\(\s*\'([a-z0-9_]+_)\'\s*\.~', $line, $m)) {
            foreach ($m[1] as $prefix) {
                $dynamic[$prefix] = $at;
            }
        }
        // t($cond ? 'a' : 'b')
        if (preg_match_all('~\bt\([^;?]*\?\s*\'([a-z0-9_]+)\'\s*:\s*\'([a-z0-9_]+)\'\s*\)~', $line, $m, PREG_SET_ORDER)) {
            foreach ($m as $hit) {
                $use($hit[1], $at);
                $use($hit[2], $at);
            }
        }
        // label keys handed to helpers, and error keys returned as strings
        if (preg_match_all('~cms_(?:add|edit_link)\([^;]*?\'(cms_[a-z0-9_]+)\'~', $line, $m)) {
            foreach ($m[1] as $key) {
                $use($key, $at);
            }
        }
        if (preg_match_all('~\'((?:cf_err|up_err|bk_err|login|pw)_[a-z0-9_]+)\'~', $line, $m)) {
            foreach ($m[1] as $key) {
                if ($key !== 'login_attempts') { // table name
                    $use($key, $at);
                }
            }
        }
        if (preg_match_all('~setting_label\(\s*\'([a-z0-9_]+)\'\s*\)~', $line, $m)) {
            foreach ($m[1] as $key) {
                $use('default_' . $key, $at . ' (setting_label needs a default)');
            }
        }
        // t(['facebook' => 'cta_fb', …][$x])
        if (preg_match_all('~=>\s*\'((?:cta|adm_tab)_[a-z0-9_]+)\'~', $line, $m)) {
            foreach ($m[1] as $key) {
                $use($key, $at);
            }
        }
    }
}

foreach ($entities['entities'] as $entity => $def) {
    $use('ent_' . $entity, "entities.php ($entity — title of the edit window)");
    if (!empty($def['soft_delete'])) {
        $use('adm_arch_' . $entity, "entities.php ($entity — heading in admin → Archív)");
    }
    foreach ($def['fields'] as $name => $f) {
        $use($f['label'] ?? 'f_' . $name, "entities.php ($entity.$name label)");
        if (!empty($f['hint'])) {
            $use($f['hint'], "entities.php ($entity.$name hint)");
        }
        foreach ($f['choices'] ?? [] as $choiceLabel) {
            $use($choiceLabel, "entities.php ($entity.$name choice)");
        }
    }
}
foreach ($entities['settings'] as $group => $fields) {
    $use('sg_' . $group, "entities.php (settings group $group — title of the edit window)");
    foreach ($fields as $name => $f) {
        $use($f['label'] ?? 'f_' . $name, "entities.php (settings.$group.$name label)");
        if (!empty($f['hint'])) {
            $use($f['hint'], "entities.php (settings.$group.$name hint)");
        }
    }
}
$contentPhp = (string) file_get_contents($root . '/includes/content.php');
if (preg_match('~const SECTIONS = \[([^\]]+)\]~', $contentPhp, $m) && preg_match_all("~'([a-z]+)'~", $m[1], $mm)) {
    foreach ($mm[1] as $sec) {
        $use('sec_' . $sec, 'content.php SECTIONS (admin → Sekcie a menu)');
        $use('default_nav_' . $sec, 'content.php SECTIONS (menu label)');
        if (!isset($entities['settings']['nav']['nav_' . $sec])) {
            $fail("section '$sec' has no 'nav_$sec' field in entities.php → settings → nav (its menu label cannot be edited)");
        }
    }
}
if (preg_match('~const SITE_MODES = \[([^\]]+)\]~', $contentPhp, $m) && preg_match_all("~'([a-z]+)'~", $m[1], $mm)) {
    foreach ($mm[1] as $mode) {
        $use('mode_' . $mode, 'content.php SITE_MODES');
        $use('mode_' . $mode . '_desc', 'content.php SITE_MODES');
    }
}
foreach (['wip_', 'mnt_'] as $prefix) {
    foreach (['title', 'meta_desc', 'headline', 'lead', 'links'] as $key) {
        $use($prefix . $key, 'pages/placeholder.php');
    }
}

$missing = 0;
foreach ($used as $key => $where) {
    if (!array_key_exists($key, $lang)) {
        $missing++;
        $fail("missing key '$key'  ← " . implode(', ', array_slice(array_keys($where), 0, 3)));
    }
}
foreach ($lang as $key => $text) {
    if (!is_string($text)) {
        $fail("'$key' is not a string");
    } elseif (preg_match('/"[^"]{2,}"/u', $text)) {
        $note("'$key' uses straight \"quotes\" — Slovak typography is „ … “ (see dg-ui-texts)");
    }
}
if (!$missing) {
    $pass(count($used) . ' referenced keys all exist (' . count($lang) . ' keys defined)');
}
if (isset($opts['unused'])) {
    $covered = static function (string $key) use ($dynamic): bool {
        foreach (array_keys($dynamic) as $prefix) {
            if (str_starts_with($key, $prefix)) {
                return true;
            }
        }
        return false;
    };
    $unused = array_values(array_filter(array_keys($lang), static fn (string $k): bool => !isset($used[$k]) && !isset($jsWords[$k]) && !$covered($k)));
    echo '  possibly unused (', count($unused), '): ', $unused ? implode(', ', $unused) : '—', PHP_EOL;
}

// ── schema ───────────────────────────────────────────────────────────────────

$section('schema');
if (isset($opts['no-db'])) {
    $note('skipped (--no-db)');
} else {
    try {
        require $root . '/includes/bootstrap.php';
        require $root . '/includes/cms.php';
        if (!db_available()) {
            $note('database not reachable or not migrated for ' . $root . ' — schema not checked (use --no-db to silence)');
        } else {
            $columns = [];
            foreach (db_all(
                "SELECT table_name, column_name, data_type, is_nullable, column_default
                   FROM information_schema.columns WHERE table_schema = current_schema()"
            ) as $c) {
                $columns[$c['table_name']][$c['column_name']] = $c;
            }
            $typeOk = [
                'bool' => ['boolean'], 'files' => ['jsonb'], 'people' => ['jsonb'], 'date' => ['date'],
                'datetime' => ['timestamp without time zone', 'timestamp with time zone'],
                'number' => ['smallint', 'integer', 'bigint'], 'select' => ['smallint', 'integer', 'bigint', 'character varying', 'text'],
            ];
            $before = $failures;
            $lng = (string) config('default_lang');

            // A database that has not received the newest migration yet is behind on purpose (the
            // developer's own database, right after a migration was written): report differences as
            // notes there. The strict run is the one against a freshly migrated test copy (--root).
            $applied = array_column(db_all('SELECT name FROM migrations'), 'name');
            $pending = array_values(array_diff($names, $applied));
            $strictFail = $fail;
            if ($pending) {
                $note('this database has not applied ' . implode(', ', $pending) . ' yet — differences below are expected here;');
                $note('the run that counts: check.php --root="$(php .claude/skills/dg-dev/scripts/testsite.php path)" after testsite.php create');
                $fail = static function (string $message) use ($note): void {
                    $note('(pending migration) ' . $message);
                };
            }
            foreach ($entities['entities'] as $entity => $def) {
                if (!isset($columns[$entity])) {
                    $fail("entity '$entity' has no table — write a migration (dg-migration)");
                    continue;
                }
                $cols = $columns[$entity];
                foreach (['id', 'updated_at'] as $needed) {
                    if (!isset($cols[$needed])) {
                        $fail("$entity.$needed is missing — cms_save() relies on it");
                    }
                }
                if (!empty($def['sortable']) && !isset($cols['sort'])) {
                    $fail("$entity is sortable but has no 'sort' column");
                }
                if (!empty($def['soft_delete']) && !isset($cols['deleted_at'])) {
                    $fail("$entity has soft_delete but no 'deleted_at' column");
                }
                $covered = ['id' => true];
                foreach ($def['fields'] as $name => $f) {
                    $column = !empty($f['i18n']) ? $name . '_' . $lng : $name;
                    $covered[$column] = !empty($f['required']) || in_array($f['type'], ['bool', 'files', 'people'], true);
                    if (!isset($cols[$column])) {
                        $fail("$entity.$column — field '$name' in entities.php has no column");
                        continue;
                    }
                    $dbType = $cols[$column]['data_type'];
                    if (isset($typeOk[$f['type']]) && !in_array($dbType, $typeOk[$f['type']], true)) {
                        $fail("$entity.$column is '$dbType' but the field type is '{$f['type']}'");
                    }
                    if ($f['type'] === 'select' && isset($f['options']) && !isset($entities['entities'][$f['options']])) {
                        $fail("$entity.$name → options '{$f['options']}' is not an entity");
                    }
                }
                foreach ($cols as $column => $c) {
                    if ($c['is_nullable'] === 'NO' && $c['column_default'] === null && empty($covered[$column])) {
                        $fail("$entity.$column is NOT NULL without a default, but no required field fills it — saving from the edit window would fail");
                    }
                }
            }
            $archive = array_keys(cms_archive());
            foreach ($entities['entities'] as $entity => $def) {
                if (!empty($def['soft_delete']) && !in_array($entity, $archive, true)) {
                    $fail("$entity uses soft_delete but cms_archive() (includes/cms.php) does not list it — archived rows could never be restored");
                }
            }
            $fail = $strictFail;
            if ($failures === $before && !$pending) {
                $pass(count($entities['entities']) . ' entities match the database (' . preg_replace('/^.*dbname=/', '', (string) config('db.dsn')) . ')');
            }
        }
    } catch (Throwable $e) {
        ($strictFail ?? $fail)('schema check crashed: ' . $e->getMessage());
    }
}

echo PHP_EOL, $failures ? "FAILED: $failures problem(s)" : 'ALL CHECKS PASSED', PHP_EOL;
exit($failures ? 1 : 0);
