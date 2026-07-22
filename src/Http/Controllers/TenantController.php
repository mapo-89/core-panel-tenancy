<?php

declare(strict_types=1);

namespace CorePanelTenancy\Http\Controllers;

use CorePanelTenancy\Domain\Tenancy\Actions\DeleteTenantAction;
use CorePanelTenancy\Domain\Tenancy\Actions\ProvisionTenantAction;
use CorePanelTenancy\Domain\Tenancy\Actions\UpdateTenantAction;
use CorePanelTenancy\Domain\Tenancy\Actions\UpsertTenantSuperAdminAction;
use CorePanelTenancy\Http\Requests\StoreTenantRequest;
use CorePanelTenancy\Http\Requests\UpdateTenantRequest;
use CorePanelTenancy\Http\Resources\TenantResource;
use CorePanelTenancy\Support\TenantModelResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

final class TenantController extends Controller
{
    public function __construct(private TenantModelResolver $models) {}

    public function index(): RedirectResponse
    {
        $tenantModel = $this->models->tenantModelClass();
        Gate::authorize('viewAny', $tenantModel);

        return redirect()->route('core-panel.users.index', ['tab' => 'tenants']);
    }

    public function dtApi(): JsonResponse
    {
        $tenantModel = $this->models->tenantModelClass();
        Gate::authorize('viewAny', $tenantModel);

        $tenants = $tenantModel::query()
            ->with('domains')
            ->withCount('domains')
            ->orderBy('id')
            ->get();

        return response()->json([
            'tenants' => TenantResource::collection($tenants)->resolve(),
        ]);
    }

    public function store(
        StoreTenantRequest $request,
        ProvisionTenantAction $action,
        UpsertTenantSuperAdminAction $upsertTenantSuperAdmin,
    ): RedirectResponse {
        $tenantModel = $this->models->tenantModelClass();
        Gate::authorize('create', $tenantModel);

        $tenant = $action->execute(
            domains: $request->domains()->all(),
            tenantId: $request->resolvedTenantId(),
            databaseName: $request->databaseName(),
            data: $request->tenantData(),
        );
        $upsertTenantSuperAdmin->execute($tenant, $request->tenantSuperAdminData());

        return redirect()
            ->route('core-panel.users.index', ['tab' => 'tenants'])
            ->with('status', __('core-panel-tenancy::page-tenants.created'));
    }

    public function edit(string $tenant): Response
    {
        $record = $this->findTenantOrFail($tenant);
        Gate::authorize('update', $record);

        return Inertia::render('Admin/Tenants/Edit', [
            'tenant' => (new TenantResource($record->load('domains')->loadCount('domains')))->resolve(),
        ]);
    }

    public function data(string $tenant): JsonResponse
    {
        $record = $this->findTenantOrFail($tenant);
        Gate::authorize('view', $record);

        return response()->json([
            'tenant' => (new TenantResource($record->load('domains')->loadCount('domains')))->resolve(),
        ]);
    }

    public function update(
        string $tenant,
        UpdateTenantRequest $request,
        UpdateTenantAction $action,
        UpsertTenantSuperAdminAction $upsertTenantSuperAdmin,
    ): RedirectResponse {
        $record = $this->findTenantOrFail($tenant);
        Gate::authorize('update', $record);

        $action->execute(
            tenant: $record,
            domains: $request->domains()->all(),
            data: $request->tenantData(),
        );

        if ($request->shouldManageTenantSuperAdmin()) {
            $upsertTenantSuperAdmin->execute($record->fresh(['domains']), $request->tenantSuperAdminData());
        }

        return redirect()
            ->route('core-panel.users.index', ['tab' => 'tenants'])
            ->with('status', __('core-panel-tenancy::page-tenants.updated'));
    }

    public function destroy(string $tenant, DeleteTenantAction $action): RedirectResponse
    {
        $record = $this->findTenantOrFail($tenant);
        Gate::authorize('delete', $record);

        $action->execute($record);

        return redirect()
            ->route('core-panel.users.index', ['tab' => 'tenants'])
            ->with('status', __('core-panel-tenancy::page-tenants.deleted'));
    }

    private function findTenantOrFail(string $tenant): object
    {
        $tenantModel = $this->models->tenantModelClass();

        return $tenantModel::query()
            ->with('domains')
            ->findOrFail($tenant);
    }
}
