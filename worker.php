<?php
// Bare mode: each process boots the app on its own,
// like running `php artisan queue:work` in several terminals.
require __DIR__ . '/lib.php';

$config = boot_app();
cli_set_process_title('lab-bare-worker');

while (true) {
    $value = $config['key.' . mt_rand(0, 199999)];
    $class = 'App\\Service' . mt_rand(0, 2999);
    (new $class())->{'method' . mt_rand(0, 9)}([$value]); // runs app code, like a real job
    usleep(200_000);
}
