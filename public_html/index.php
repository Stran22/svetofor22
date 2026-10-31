<?php
declare(strict_types=1);

use App\Core\Application;
use App\Core\Config;

require dirname(__DIR__) . '/vendor/autoload.php';
Config::load(dirname(__DIR__) . '/.env');
(new Application())->run();
