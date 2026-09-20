<?php
/**
 * Pošta z kontaktného formulára. Podľa anotoki (php/api/mail.php):
 *   driver 'mail' = PHP mail() (Websupport), 'file' = uloží .eml do storage/mail/.
 * Nič tu nevyhadzuje výnimku — správa je vždy uložená v databáze, pošta je bonus.
 */

declare(strict_types=1);

/** Predmet / meno odosielateľa s diakritikou musí byť zakódované, inak príde rozsypaný. */
function mail_encode(string $text): string
{
    return preg_match('/^[\x20-\x7E]*$/', $text) ? $text : '=?UTF-8?B?' . base64_encode($text) . '?=';
}

/** Odstráni zalomenia riadkov — ochrana pred vložením vlastných hlavičiek. */
function mail_header_safe(string $value): string
{
    return trim((string) preg_replace('/[\r\n\t]+/', ' ', $value));
}

/**
 * Meno pri adrese („Meno <adresa>"). S diakritikou sa zakóduje, obyčajné ide do
 * úvodzoviek — čiarka či zátvorka v mene („Novak, Jan") by inak adresu rozdelila na dve.
 */
function mail_name(string $name): string
{
    $name = mail_header_safe($name);

    return preg_match('/^[\x20-\x7E]*$/', $name) ? '"' . addcslashes($name, '"\\') . '"' : mail_encode($name);
}

function send_mail(string $to, string $subject, string $body, ?string $replyTo = null, ?string $replyName = null): bool
{
    $cfg  = config('mail');
    $from = mail_header_safe((string) $cfg['from']);
    $name = mail_header_safe((string) ($cfg['from_name'] ?? ''));

    $headers = [
        'From: ' . ($name !== '' ? mail_name($name) . ' <' . $from . '>' : $from),
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
    ];
    if ($replyTo !== null && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
        $reply = mail_header_safe($replyTo);
        $headers[] = 'Reply-To: ' . ($replyName ? mail_name($replyName) . ' <' . $reply . '>' : $reply);
    }

    $subject = mail_header_safe($subject);

    if (($cfg['driver'] ?? 'file') === 'mail') {
        return @mail($to, mail_encode($subject), $body, implode("\n", $headers));
    }

    $dir = ROOT . '/storage/mail';
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        return false;
    }
    [$micro, $sec] = explode(' ', microtime());
    $file = sprintf('%s/%s.%s-%s.eml', $dir, date('Ymd-His', (int) $sec), substr($micro, 2, 6), bin2hex(random_bytes(2)));
    $message = implode("\n", array_merge($headers, ['To: ' . $to, 'Subject: ' . $subject, '', $body]));

    return @file_put_contents($file, $message) !== false;
}

/** Kam idú správy z formulára: e-mail z administrácie → config. */
function contact_recipient(): string
{
    $email = contact('email');

    return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : (string) config('mail.to');
}

// ── Kontaktný formulár ───────────────────────────────────────────────────────

const CONTACT_RATE_LIMIT = 5;       // správ z jednej IP za hodinu
const CONTACT_MIN_SECONDS = 3;      // rýchlejšie vyplnený formulár posiela robot
const CONTACT_MAX_AGE = 6 * 60 * 60; // starší formulár treba načítať znova

/** Podpísaný čas vykreslenia formulára (bez session, teda bez cookies). */
function contact_form_token(): string
{
    $time = (string) time();

    return $time . '.' . sign('contact|' . $time);
}

/**
 * Spracuje odoslaný formulár. Vracia ['ok' => true] alebo ['error' => kľúč prekladu, 'field' => …].
 */
function contact_submit(array $in): array
{
    // Pasca na roboty: pole, ktoré človek nevidí. Tvárime sa, že je všetko v poriadku.
    if (trim((string) ($in['website'] ?? '')) !== '') {
        return ['ok' => true];
    }

    [$time, $sig] = array_pad(explode('.', (string) ($in['token'] ?? ''), 2), 2, '');
    if (!ctype_digit($time) || !hash_equals(sign('contact|' . $time), $sig) || time() - (int) $time > CONTACT_MAX_AGE) {
        return ['error' => 'cf_err_expired'];
    }
    if (time() - (int) $time < CONTACT_MIN_SECONDS) {
        return ['ok' => true];
    }

    // Meno a predmet sú jednoriadkové polia — zalomenia (robot) sa zmenia na medzeru.
    $name    = mb_substr(text_flat((string) ($in['name'] ?? '')), 0, 120);
    $email   = mb_substr(trim((string) ($in['email'] ?? '')), 0, 255);
    $subject = mb_substr(text_flat((string) ($in['subject'] ?? '')), 0, 200);
    $body    = mb_substr(trim((string) ($in['message'] ?? '')), 0, 5000);

    if ($name === '') {
        return ['error' => 'cf_err_name', 'field' => 'name'];
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['error' => 'cf_err_email', 'field' => 'email'];
    }
    if (mb_strlen($body) < 5) {
        return ['error' => 'cf_err_message', 'field' => 'message'];
    }

    $ip = ip_hash();
    $recent = (int) db_value("SELECT count(*) FROM messages WHERE ip_hash = ? AND created_at > now() - interval '1 hour'", [$ip]);
    if ($recent >= CONTACT_RATE_LIMIT) {
        return ['error' => 'cf_err_rate'];
    }

    $id = (int) db_value(
        'INSERT INTO messages (name, email, subject, body, ip_hash, user_agent) VALUES (?, ?, ?, ?, ?, ?) RETURNING id',
        [$name, $email, $subject !== '' ? $subject : null, $body, $ip, mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500)]
    );

    $text = "Nová správa z webu Divadielko Galéria\n"
        . "======================================\n\n"
        . "Meno:    $name\n"
        . "E-mail:  $email\n"
        . ($subject !== '' ? "Predmet: $subject\n" : '')
        . "\n" . $body . "\n\n"
        . "--\nNa správu môžete odpovedať priamo (Odpovedať). Všetky správy sú aj v administrácii webu.\n";

    $mailed = send_mail(
        contact_recipient(),
        'Web: ' . ($subject !== '' ? $subject : 'správa od ' . $name),
        $text,
        $email,
        $name
    );
    if ($mailed) {
        db_exec('UPDATE messages SET mailed = true WHERE id = ?', [$id]);
    }

    return ['ok' => true];
}
