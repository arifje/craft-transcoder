<?php

return [
    'runQueueAutomatically' => false,
    'aliases' => [
        '@webroot' => sys_get_temp_dir() . '/transcoder-craft5-test/web',
        '@web' => 'https://transcoder.test',
    ],
];
