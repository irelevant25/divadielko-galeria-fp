<?php
/**
 * Len na vývoj — zastupuje .htaccess pre vstavaný server PHP:
 *
 *   php -S localhost:8000 router.php
 *
 * Na hostingu sa nepoužíva (a .htaccess ho blokuje).
 */

declare(strict_types=1);

$path = rawurldecode((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH));

// Vnútornosti a skryté súbory
if (preg_match('~^/(includes|pages|storage|assets_original|tools)(/|$)~', $path)
    || preg_match('~/\.(?!well-known/)~', $path)
    || preg_match('~\.(md|sql|lock|log|dist)$~', $path)
    || $path === '/router.php'
    || (strpos($path, '/assets/') === 0 && preg_match('~\.(php\d?|phtml|phar|html?|svg|js)$~i', $path))) {
    http_response_code(403);
    echo 'Forbidden';
    return true;
}

$file = __DIR__ . $path;
if ($path !== '/' && is_file($file)) {
    // Typy, ktoré vstavaný server nepozná.
    $types = ['avif' => 'image/avif', 'opus' => 'audio/ogg'];
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    if (isset($types[$ext])) {
        header('Content-Type: ' . $types[$ext]);
        header('Content-Length: ' . filesize($file));
        readfile($file);
        return true;
    }

    return false; // statický súbor alebo .php — obslúži server sám
}

require __DIR__ . '/index.php';
