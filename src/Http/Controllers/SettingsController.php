<?php

declare(strict_types=1);

namespace CorePanelTenancy\Http\Controllers;

use CorePanel\Domains\ApiToken\Actions\ListApiTokensAction;
use CorePanel\Domains\Setting\Actions\GetSettingsGroupAction;
use CorePanel\Domains\Setting\Actions\UpdateSettingsGroupAction;
use CorePanel\Http\Requests\UpdateSettingsRequest;
use CorePanel\Http\Requests\UpdateStyleSettingsRequest;
use CorePanel\Support\ActivityLog\ActivityLogService;
use CorePanel\Support\Api\ApiTokenAbilityOptions;
use CorePanel\Support\Config\CorePanelConfig;
use CorePanel\Support\Settings\SettingsSchema;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;

final class SettingsController extends Controller
{
    public function __construct(
        private readonly CorePanelConfig $corePanel,
        private readonly GetSettingsGroupAction $getSettingsGroup,
        private readonly UpdateSettingsGroupAction $updateSettingsGroup,
        private readonly ListApiTokensAction $listApiTokens,
        private readonly ActivityLogService $activityLog,
    ) {}

    public function index(Request $request): Response
    {
        abort_unless($request->user()?->can('settings.view') ?? false, 403);

        return Inertia::render('Settings/Index', [
            'apiTokenManager' => $this->apiTokenManagerPayload($request),
            'currentGroup' => (string) $request->query('tab', 'general'),
            'groups' => $this->groupsPayload($request),
        ]);
    }

    public function update(UpdateSettingsRequest $request, string $group): RedirectResponse
    {
        abort_unless($request->user()?->can('settings.update') ?? false, 403);

        $definition = SettingsSchema::group($group);
        abort_if($definition === null, 404);

        $payload = $this->groupPayloadFromValues(
            $group,
            (array) $request->validated('values', []),
        );

        $updated = $this->updateSettingsGroup->execute($group, $payload);

        $this->activityLog
            ->withCauser($request->user())
            ->log($request->user(), 'settings.updated', [
                'group' => $group,
                'keys' => array_keys($updated),
            ]);

        $tab = match ($group) {
            'i18n' => 'general',
            'ui' => 'appearance',
            default => $group,
        };

        return redirect()
            ->route($this->tenantAwareRouteName('core-panel.settings.index'), ['tab' => $tab])
            ->with('status', __('core-panel::settings.messages.saved'));
    }

    public function updateStyles(UpdateStyleSettingsRequest $request): RedirectResponse
    {
        abort_unless($request->user()?->can('settings.update') ?? false, 403);

        $values = (array) $request->validated('values', []);

        $updatedAppearance = $this->updateSettingsGroup->execute(
            'appearance',
            $this->groupPayloadFromValues('appearance', $values),
        );
        $updatedUi = $this->updateSettingsGroup->execute(
            'ui',
            $this->groupPayloadFromValues('ui', $values),
        );

        $this->activityLog
            ->withCauser($request->user())
            ->log($request->user(), 'settings.updated', [
                'group' => 'appearance',
                'keys' => array_values(array_unique([
                    ...array_keys($updatedAppearance),
                    ...array_keys($updatedUi),
                ])),
                'updated_groups' => ['appearance', 'ui'],
            ]);

        return redirect()
            ->route($this->tenantAwareRouteName('core-panel.settings.index'), ['tab' => 'appearance'])
            ->with('status', __('core-panel::settings.messages.saved'));
    }

    private function tenantAwareRouteName(string $routeName): string
    {
        if (! function_exists('tenancy') || ! tenancy()->initialized) {
            return $routeName;
        }

        return 'tenant.'.$routeName;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function groupsPayload(Request $request): array
    {
        $groups = collect(SettingsSchema::definitions())
            ->map(function (array $definition, string $group): array {
                $storedValues = $this->getSettingsGroup->execute($group);

                return [
                    'description' => (string) $definition['description'],
                    'fields' => collect((array) $definition['fields'])
                        ->map(function (array $field, string $key) use ($storedValues): array {
                            return [
                                'help' => $field['help'] ?? null,
                                'isLocalized' => (bool) ($field['is_localized'] ?? false),
                                'isPublic' => (bool) ($field['is_public'] ?? false),
                                'key' => $key,
                                'label' => (string) $field['label'],
                                'options' => array_values((array) ($field['options'] ?? [])),
                                'type' => (string) ($field['type'] ?? 'text'),
                                'value' => $storedValues[$key] ?? $field['default'] ?? null,
                            ];
                        })
                        ->values()
                        ->all(),
                    'key' => $group,
                    'label' => (string) $definition['label'],
                ];
            })
            ->values()
            ->all();

        if ($this->canShowApiTokens()) {
            $groups[] = [
                'description' => (string) __('page-api-tokens.description'),
                'fields' => [],
                'key' => 'api',
                'label' => (string) __('page-settings.tab_api'),
            ];
        }

        return $groups;
    }

    /**
     * @return array{abilities: list<array{label:string,value:mixed}>, canCreate: bool, canDelete: bool, tokens: array<int, mixed>}|null
     */
    private function apiTokenManagerPayload(Request $request): ?array
    {
        if (! $this->canShowApiTokens()) {
            return null;
        }

        $user = $request->user();
        abort_unless($user !== null, 403);

        return [
            'abilities' => ApiTokenAbilityOptions::options(),
            'canCreate' => true,
            'canDelete' => true,
            'tokens' => $this->listApiTokens->execute($user),
        ];
    }

    private function canShowApiTokens(): bool
    {
        return $this->corePanel->auth->usesPassport();
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, array{is_localized: bool, is_public: bool, type: string, value: mixed}>
     */
    private function groupPayloadFromValues(string $group, array $values): array
    {
        $definition = SettingsSchema::group($group);
        abort_if($definition === null, 404);

        $payload = [];

        foreach ((array) $definition['fields'] as $key => $field) {
            $value = data_get($values, "{$key}.value");

            if ($group === 'general' && $key === 'app_subtitle' && $value === null) {
                $value = '';
            }

            $payload[$key] = [
                'is_localized' => (bool) ($field['is_localized'] ?? false),
                'is_public' => (bool) ($field['is_public'] ?? false),
                'type' => (string) ($field['type'] ?? 'string'),
                'value' => $value,
            ];
        }

        return $payload;
    }
}
