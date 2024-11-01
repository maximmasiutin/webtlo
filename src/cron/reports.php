<?php

require __DIR__ . '/../vendor/autoload.php';

use KeepersTeam\Webtlo\App;
use KeepersTeam\Webtlo\Legacy\Log;

try {
    // Инициализируем контейнер, без имени лога, чтобы записи не двоились от legacy/di.
    App::create();

    // дёргаем скрипт
    $checkEnabledCronAction = 'reports';
    include_once dirname(__FILE__) . '/../php/common/reports.php';
} catch (Exception $e) {
    Log::append($e->getMessage());
}

// записываем в лог
Log::write('reports.log');
