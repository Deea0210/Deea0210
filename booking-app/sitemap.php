<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

header('Content-Type: application/xml; charset=utf-8');

$pages = [
    ['', 'weekly', '1.0'],
    ['book.php', 'weekly', '0.9'],
    ['privacy.php', 'yearly', '0.2'],
];
echo '<?xml version="1.0" encoding="UTF-8"?>', "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">', "\n";
foreach ($pages as [$path, $freq, $priority]) {
    printf("  <url><loc>%s</loc><changefreq>%s</changefreq><priority>%s</priority></url>\n", e(absolute_url($path)), $freq, $priority);
}
echo '</urlset>', "\n";
