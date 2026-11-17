<?php

namespace App\Services\Bitrix24;

use App\Exceptions\BitrixApiException;
use App\Models\Tenant;
use Illuminate\Support\Facades\Log;

class BitrixPlacementEventSyncer
{
    public function __construct(
        private readonly Bitrix24Client $client,
    ) {}

    /**
     * Synchronize CRM Detail Tab placements and Bitrix24 webhooks for a tenant.
     *
     * @return array{placements_bound: list<string>, placements_unbound: list<string>, events_bound: list<string>, warnings: list<string>}
     */
    public function sync(Tenant $tenant, bool $includeAutoSyncEvents = true): array
    {
        $publicBase = rtrim((string) (config('services.bitrix24.public_url') ?: config('app.url')), '/');
        if (! app()->environment('testing') && str_starts_with($publicBase, 'http://')) {
            $publicBase = 'https://'.substr($publicBase, 7);
        }
        $widgetHandlerUrl = $publicBase.'/bitrix/widget';
        $eventHandlerUrl = $publicBase.'/bitrix/events';
        $tabTitle = (string) config('services.bitrix24.widget_tab_title', 'KEEN Inventory');

        $warnings = [];
        $placementsBound = [];
        $placementsUnbound = [];
        $eventsBound = [];

        // Ensure default CRM tabs (Deals, Leads, Quotes) exist for fresh tenants
        if ($tenant->entityConfigs()->count() === 0) {
            $defaultConfigs = [
                [
                    'logical_key' => 'deal',
                    'title' => 'Deals',
                    'entity_type_id' => 2,
                    'owner_type_code' => 'D',
                    'placement_code' => 'CRM_DEAL_DETAIL_TAB',
                    'is_enabled' => true,
                    'auto_sync_enabled' => true,
                ],
                [
                    'logical_key' => 'lead',
                    'title' => 'Leads',
                    'entity_type_id' => 1,
                    'owner_type_code' => 'L',
                    'placement_code' => 'CRM_LEAD_DETAIL_TAB',
                    'is_enabled' => true,
                    'auto_sync_enabled' => true,
                ],
                [
                    'logical_key' => 'quote',
                    'title' => 'Quotes',
                    'entity_type_id' => 7,
                    'owner_type_code' => 'Q',
                    'placement_code' => 'CRM_QUOTE_DETAIL_TAB',
                    'is_enabled' => true,
                    'auto_sync_enabled' => true,
                ],
            ];
            foreach ($defaultConfigs as $def) {
                $tenant->entityConfigs()->create($def);
            }
        }

        $existingPlacements = $this->fetchExistingPlacements($tenant, $warnings);

        $entityConfigs = $tenant->entityConfigs()->get();
        $desiredPlacements = [];
        foreach ($entityConfigs as $config) {
            if ($config->is_enabled) {
                $desiredPlacements[$config->placement_code] = $config;
            }
        }

        foreach ($desiredPlacements as $placementCode => $config) {
            // Clean up any stale/outdated handler URL for this placement first
            foreach ($existingPlacements as $existing) {
                if (($existing['placement'] ?? '') === $placementCode && ($existing['handler'] ?? '') !== $widgetHandlerUrl) {
                    try {
                        $this->client->call($tenant, 'placement.unbind', [
                            'PLACEMENT' => $placementCode,
                            'HANDLER' => (string) $existing['handler'],
                        ]);
                    } catch (\Throwable) {
                        // Suppress if already removed or inaccessible
                    }
                }
            }

            $alreadyBoundSame = collect($existingPlacements)->contains(function (array $p) use ($placementCode, $widgetHandlerUrl) {
                return ($p['placement'] ?? '') === $placementCode && ($p['handler'] ?? '') === $widgetHandlerUrl;
            });

            if (! $alreadyBoundSame) {
                try {
                    $this->client->call($tenant, 'placement.bind', [
                        'PLACEMENT' => $placementCode,
                        'HANDLER' => $widgetHandlerUrl,
                        'TITLE' => $tabTitle,
                        'DESCRIPTION' => 'KEEN Inventory - Multi-option fee calculator & CRM synchronizer',
                    ]);
                    $placementsBound[] = $placementCode;
                } catch (BitrixApiException $e) {
                    $warnings[] = "placement.bind({$placementCode}): {$e->getMessage()}";
                }
            } else {
                $placementsBound[] = $placementCode;
            }

            $config->forceFill(['last_bound_at' => now()])->save();
        }

        // Unbind disabled placements belonging to our handler URL
        foreach ($existingPlacements as $existing) {
            $code = (string) ($existing['placement'] ?? '');
            $handler = (string) ($existing['handler'] ?? '');
            if ($code !== '' && $handler === $widgetHandlerUrl && ! isset($desiredPlacements[$code])) {
                try {
                    $this->client->call($tenant, 'placement.unbind', [
                        'PLACEMENT' => $code,
                        'HANDLER' => $widgetHandlerUrl,
                    ]);
                    $placementsUnbound[] = $code;
                } catch (BitrixApiException $e) {
                    $warnings[] = "placement.unbind({$code}): {$e->getMessage()}";
                }
            }
        }

        // Event bindings: always bind app lifecycle events + relevant CRM/Catalog events
        $desiredEvents = [
            'ONAPPUPDATE',
            'ONAPPUNINSTALL',
        ];

        if ($includeAutoSyncEvents) {
            $desiredEvents = array_merge($desiredEvents, [
                'ONCRMDEALADD',
                'ONCRMDEALUPDATE',
                'ONCRMLEADADD',
                'ONCRMLEADUPDATE',
                'ONCRMDYNAMICITEMADD',
                'ONCRMDYNAMICITEMUPDATE',
                'ONCRMPRODUCTUPDATE',
                'CATALOG.PRODUCT.ON.UPDATE',
            ]);
        }

        $existingEvents = $this->fetchExistingEvents($tenant, $warnings);

        foreach (array_unique($desiredEvents) as $eventName) {
            $alreadyBound = collect($existingEvents)->contains(function (array $ev) use ($eventName, $eventHandlerUrl) {
                return strtoupper((string) ($ev['event'] ?? '')) === strtoupper($eventName)
                    && ($ev['handler'] ?? '') === $eventHandlerUrl;
            });

            if (! $alreadyBound) {
                try {
                    $this->client->call($tenant, 'event.bind', [
                        'event' => $eventName,
                        'handler' => $eventHandlerUrl,
                    ]);
                    $eventsBound[] = $eventName;
                } catch (BitrixApiException $e) {
                    $warnings[] = "event.bind({$eventName}): {$e->getMessage()}";
                }
            } else {
                $eventsBound[] = $eventName;
            }
        }

        if ($warnings !== []) {
            Log::info('BitrixPlacementEventSyncer warnings', [
                'member_id' => $tenant->member_id,
                'warnings' => $warnings,
            ]);
        }

        return [
            'placements_bound' => $placementsBound,
            'placements_unbound' => $placementsUnbound,
            'events_bound' => $eventsBound,
            'warnings' => $warnings,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchExistingPlacements(Tenant $tenant, array &$warnings): array
    {
        try {
            $res = $this->client->call($tenant, 'placement.get');

            return is_array($res['result'] ?? null) ? $res['result'] : [];
        } catch (BitrixApiException $e) {
            $warnings[] = 'placement.get: '.$e->getMessage();

            return [];
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchExistingEvents(Tenant $tenant, array &$warnings): array
    {
        try {
            $res = $this->client->call($tenant, 'event.get');

            return is_array($res['result'] ?? null) ? $res['result'] : [];
        } catch (BitrixApiException $e) {
            $warnings[] = 'event.get: '.$e->getMessage();

            return [];
        }
    }
}
