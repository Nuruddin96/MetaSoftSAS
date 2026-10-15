<?php

use App\Providers\AppServiceProvider;

$providers = [
    AppServiceProvider::class,
];

// PRIVATE, LOCAL-ONLY features (Remote Support, Live Screen Sharing,
// Device Intelligence). The entire local-features/ folder is gitignored
// and never deployed, so this require is a no-op in production and on any
// clone that doesn't have the private overlay. See local-features/README.md.
if (is_file(__DIR__.'/../local-features/bootstrap.php')) {
    $providers = array_merge($providers, require __DIR__.'/../local-features/bootstrap.php');
}

return $providers;
