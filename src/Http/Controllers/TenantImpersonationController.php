<?php

declare(strict_types=1);

namespace CorePanelTenancy\Http\Controllers;

use Carbon\Carbon;
use CorePanelTenancy\Support\Tenancy\CentralImpersonationContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Stancl\Tenancy\Database\Models\ImpersonationToken;
use Stancl\Tenancy\Features\UserImpersonation;

final class TenantImpersonationController extends Controller
{
    public function __invoke(Request $request, string $token): RedirectResponse
    {
        /** @var ImpersonationToken $impersonationToken */
        $impersonationToken = ImpersonationToken::findOrFail($token);
        $tenantId = $this->requiredStringAttribute($impersonationToken, 'tenant_id');
        $createdAt = $impersonationToken->getAttribute('created_at');
        $authGuard = $this->requiredStringAttribute($impersonationToken, 'auth_guard');
        $userId = $this->requiredStringAttribute($impersonationToken, 'user_id');
        $redirectUrl = $this->requiredStringAttribute($impersonationToken, 'redirect_url');

        if ($tenantId !== ((string) tenant()->getTenantKey())) {
            abort(403);
        }

        if (! $createdAt instanceof Carbon) {
            abort(403);
        }

        if ($createdAt->diffInSeconds(Carbon::now()) > UserImpersonation::$ttl) {
            abort(403);
        }

        abort_unless(Auth::guard($authGuard)->loginUsingId($userId) !== false, 403);

        $context = app(CentralImpersonationContext::class);

        $context->storeFromEncryptedPayload(
            $request,
            $request->query(CentralImpersonationContext::QUERY_KEY),
        );
        $context->storeTenantAuthentication($request, $authGuard, $userId);

        $impersonationToken->delete();

        return redirect($redirectUrl);
    }

    private function requiredStringAttribute(ImpersonationToken $token, string $key): string
    {
        $value = $token->getAttribute($key);

        if (! is_string($value) || trim($value) === '') {
            abort(403);
        }

        return trim($value);
    }
}
