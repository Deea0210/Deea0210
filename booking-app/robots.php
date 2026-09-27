<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

header('Content-Type: text/plain; charset=utf-8');
echo "User-agent: *\n";
echo 'Disallow: ' . url('admin/') . "\n";
echo 'Disallow: ' . url('manage.php') . "\n";
echo 'Disallow: ' . url('install.php') . "\n\n";
echo 'Sitemap: ' . absolute_url('sitemap.xml') . "\n";
