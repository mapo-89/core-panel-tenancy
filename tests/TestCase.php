<?php

declare(strict_types=1);

namespace CorePanelTenancy\Tests;

use CorePanel\Tests\TestCase as CorePanelTestCase;
use CorePanelTenancy\CorePanelTenancyServiceProvider;

abstract class TestCase extends CorePanelTestCase
{
    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            ...parent::getPackageProviders($app),
            CorePanelTenancyServiceProvider::class,
        ];
    }
}
