<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo;

use League\Container\ServiceProvider\AbstractServiceProvider;

/**
 * Предоставляет ключевые классы для работы приложения.
 */
final class AppServiceProvider extends AbstractServiceProvider
{
    public function provides(string $id): bool
    {
        $services = [
            TIniFileEx::class,
            WebTLO::class,
        ];

        return in_array($id, $services, true);
    }

    public function register(): void
    {
        $container = $this->getContainer();

        // Обработчик ini-файла с конфигом.
        $container->addShared(TIniFileEx::class, fn() => new TIniFileEx());

        // Подключаем описание версии WebTLO.
        $container->add(WebTLO::class, fn() => WebTLO::loadFromFile());
    }
}
