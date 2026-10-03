<?php

declare(strict_types=1);

$_SERVER['REQUEST_URI'] = '/';
$_SERVER['REQUEST_METHOD'] = 'GET';

ob_start();
require __DIR__ . '/../public/index.php';
$html = ob_get_clean();

if (str_contains($html, '<div id="app">') && str_contains($html, 'Smart Split')) {
    echo "WEB SHELL RENDER SUCCESS\n";
} else {
    echo "WEB SHELL RENDER FAILED\n";
    exit(1);
}
