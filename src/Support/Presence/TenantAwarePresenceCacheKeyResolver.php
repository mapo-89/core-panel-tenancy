<?php

declare(strict_types=1);

namespace CorePanelTenancy\Support\Presence;

use CorePanel\Contracts\PresenceCacheKeyResolver;
use CorePanel\Support\Presence\DefaultPresenceCacheKeyResolver;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

final readonly class TenantAwarePresenceCacheKeyResolver implements PresenceCacheKeyResolver
{
    public function __construct(private DefaultPresenceCacheKeyResolver $default) {}

    public function resolve(Model|Authenticatable|string|int $user): string
    {
        return $this->scope($this->default->resolve($user));
    }

    public function scope(string $key): string
    {

        if (! tenancy()->initialized) {
            return $key;
        }

        return sprintf('tenant:%s:%s', (string) tenant('id'), $key);
    }
}
