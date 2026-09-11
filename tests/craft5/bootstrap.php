<?php

declare(strict_types=1);

// Never point this harness at an existing Craft installation.
if (getenv('CRAFT_DB_DATABASE') !== 'transcoder_test' || getenv('TRANSCODER_TESTING') !== '1') {
    throw new RuntimeException('Use a disposable transcoder_test database and TRANSCODER_TESTING=1.');
}
if (getenv('CRAFT_DB_DSN') || getenv('CRAFT_DB_URL')) {
    throw new RuntimeException('Unset CRAFT_DB_DSN and CRAFT_DB_URL before running isolated tests.');
}

$root = sys_get_temp_dir() . '/transcoder-craft5-test';
putenv('CRAFT_BASE_PATH=' . $root);
putenv('CRAFT_CONFIG_PATH=' . __DIR__ . '/config');
putenv('CRAFT_LICENSE_KEY_PATH=' . $root . '/license.key');
putenv('CRAFT_VENDOR_PATH=' . dirname(__DIR__, 2) . '/vendor');
putenv('CRAFT_SECURITY_KEY=transcoder-isolated-test-security-key');
putenv('CRAFT_ENVIRONMENT=test');
putenv('CRAFT_DEV_MODE=1');
putenv('CRAFT_ALLOW_ADMIN_CHANGES=1');

require dirname(__DIR__, 2) . '/vendor/autoload.php';

return require dirname(__DIR__, 2) . '/vendor/craftcms/cms/bootstrap/console.php';
