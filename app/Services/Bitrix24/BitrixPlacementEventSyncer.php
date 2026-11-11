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
        $widgetHandlerUrl = $publicBase.'/bitrix/widget';
        $eventHandlerUrl = $publicBase.'/bitrix/events';
        $tabTitle = (string) config('services.bitrix24.widget_tab_title', 'Fee Grid & SPA Sync');

        $warnings = [];
        $placementsBound = [];
        $placementsUnbound = [];
        $eventsBound = [];

        $existingPlacements = $this->fetchExistingPlacements($tenant, $warnings);

        $entityConfigs = $tenant->entityConfigs()->get();
        $desiredPlacements = [];
        foreach ($entityConfigs as $config) {
            if ($config->is_enabled) {
                $desiredPlacements[$config->placement_code] = $config;
            }
        }

        foreach ($desiredPlacements as $placementCode => $config) {
            $alreadyBoundSame = collect($existingPlacements)->contains(function (array $p) use ($placementCode, $widgetHandlerUrl) {
                return ($p['placement'] ?? '') === $placementCode && ($p['handler'] ?? '') === $widgetHandlerUrl;
            });

            if (! $alreadyBoundSame) {
                try {
                    $this->client->call($tenant, 'placement.bind', [
                        'PLACEMENT' => $placementCode,
                        'HANDLER' => $widgetHandlerUrl,
                        'TITLE' => $tabTitle,
                        'DESCRIPTION' => 'Multi-option fee calculator & SPA synchronizer',
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
