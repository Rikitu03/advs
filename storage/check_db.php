<?php

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$tables = DB::select('SHOW TABLES');
echo 'Total tables: ' . count($tables) . "\n";
foreach ($tables as $t) {
    $name = array_values((array) $t)[0];
    echo $name . "\n";
}

echo "\n--- migrations count ---\n";
try {
    echo DB::table('migrations')->count() . "\n";
} catch (Throwable $e) {
    echo 'ERROR: ' . $e->getMessage() . "\n";
}