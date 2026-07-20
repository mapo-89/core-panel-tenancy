<?php

declare(strict_types=1);

namespace App\Models;

use Stancl\Tenancy\Contracts\TenantWithDatabase;
use Stancl\Tenancy\Database\Concerns\HasDatabase;
use Stancl\Tenancy\Database\Concerns\HasDomains;
use Stancl\Tenancy\Database\Models\Tenant as BaseTenant;

class Tenant extends BaseTenant implements TenantWithDatabase
{
    use HasDatabase;
    use HasDomains;

    protected function casts(): array
    {
        return [
            ...$this->casts,
        ];
    }

    public function getPrimaryDomainAttribute(): ?string
    {
        return $this->domains()
            ->orderBy('id')
            ->first()?->domain;
    }

    public function getDisplayNameAttribute(): string
    {
        $name = is_string($this->name) ? trim($this->name) : '';

        if ($name !== '') {
            return $name;
        }

        return $this->primary_domain ?? (string) $this->getKey();
    }
}
