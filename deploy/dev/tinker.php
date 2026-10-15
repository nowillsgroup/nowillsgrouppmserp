<?php
// Quick dev tinker: run PHP with the app booted. Usage: php -f deploy/dev/tinker.php
require '/app/build/vendor/autoload.php';
$app = require '/app/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
\App\Support\Tenant::set(\App\Models\Hotel::find(1));

$code = getenv('TINKER') ?: 'echo "tinker ready\\n";';
eval($code);
