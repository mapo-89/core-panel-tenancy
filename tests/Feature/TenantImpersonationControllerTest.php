<?php

declare(strict_types=1);

use CorePanel\Tests\FakeUser;
use CorePanelTenancy\Http\Controllers\TenantImpersonationController;
use CorePanelTenancy\Support\Tenancy\CentralImpersonationContext;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Stancl\Tenancy\Contracts\Tenant as TenantContract;
use Stancl\Tenancy\Database\Models\ImpersonationToken;
use Stancl\Tenancy\Database\Models\Tenant;
use Symfony\Component\HttpKernel\Exception\HttpException;

it('aborts failed tenant impersonation logins before storing context or deleting the token', function (): void {
    $this->migrateScaffoldDatabase();

    if (! Schema::hasTable('tenant_user_impersonation_tokens')) {
        Schema::create('tenant_user_impersonation_tokens', static function (Blueprint $table): void {
            $table->string('token', 128)->primary();
            $table->string('tenant_id');
            $table->string('user_id');
            $table->string('auth_guard');
            $table->string('redirect_url');
            $table->timestamp('created_at');
        });
    }

    $currentTenantUser = FakeUser::query()->create([
        'email' => 'tenant-user@example.test',
        'first_name' => 'Tenant',
        'last_name' => 'User',
        'password' => bcrypt('password'),
    ]);
    $centralUser = FakeUser::query()->create([
        'email' => 'central-admin@example.test',
        'first_name' => 'Central',
        'last_name' => 'Admin',
        'password' => bcrypt('password'),
    ]);

    $tenant = new Tenant;
    $tenant->forceFill([
        'id' => 'tenant-alpha',
    ]);

    tenancy()->getBootstrappersUsing = static fn (): array => [];
    tenancy()->initialize($tenant);
    app()->instance(TenantContract::class, $tenant);

    $token = 'failed-login-token';
    ImpersonationToken::query()->insert([
        'token' => $token,
        'tenant_id' => 'tenant-alpha',
        'user_id' => 'missing-user-id',
        'auth_guard' => 'web',
        'redirect_url' => '/dashboard',
        'created_at' => Carbon::now(),
    ]);

    $request = Request::create('/testing/tenant-impersonation/'.$token, 'GET', [
        CentralImpersonationContext::QUERY_KEY => app(CentralImpersonationContext::class)->encryptedPayload($centralUser),
    ]);
    $session = app('session.store');
    $session->start();
    $request->setLaravelSession($session);
    app()->instance('request', $request);

    $guard = Auth::guard('web');
    if (method_exists($guard, 'setRequest')) {
        $guard->setRequest($request);
    }

    $guard->login($currentTenantUser);

    try {
        app(TenantImpersonationController::class)($request, $token);
        $this->fail('Tenant impersonation should abort when loginUsingId() fails.');
    } catch (HttpException $exception) {
        expect($exception->getStatusCode())->toBe(403);
    }

    expect($session->has(CentralImpersonationContext::SESSION_KEY))->toBeFalse()
        ->and(ImpersonationToken::find($token))->not->toBeNull()
        ->and($guard->id())->toBe($currentTenantUser->getAuthIdentifier());
});
