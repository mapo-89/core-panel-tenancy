<?php

declare(strict_types=1);

namespace CorePanelTenancy\Http\Middleware;

use Closure;
use CorePanelTenancy\Support\Tenancy\CentralImpersonationContext;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

final class RedirectImpersonatingTenantGuest
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() !== null) {
            return $next($request);
        }

        if (app(CentralImpersonationContext::class)->centralUser($request) === null) {
            return $next($request);
        }

        $leaveImpersonationUrl = route('tenant.leave-impersonation');

        if ($request->header('X-Inertia')) {
            return Inertia::location($leaveImpersonationUrl);
        }

        return redirect()->to($leaveImpersonationUrl);
    }
}
