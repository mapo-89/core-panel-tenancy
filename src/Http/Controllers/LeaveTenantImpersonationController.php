<?php

declare(strict_types=1);

namespace CorePanelTenancy\Http\Controllers;

use CorePanelTenancy\Support\Tenancy\CentralImpersonationContext;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

final class LeaveTenantImpersonationController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $context = app(CentralImpersonationContext::class);

        abort_unless($context->restoreCentralAuthentication($request) !== null, 403);

        $url = $context->centralAppUrl($request);

        if ($request->header('X-Inertia')) {
            return Inertia::location($url);
        }

        return redirect()->away($url);
    }
}
