<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

$app = require __DIR__ . '/engine/bootstrap.php';

exit($app->run());
