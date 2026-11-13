<?php

namespace App\Http\Controllers\Bitrix;

use App\Exceptions\BitrixApiException;
use App\Http\Controllers\Controller;
use App\Jobs\PortalBackfillJob;
use App\Models\Plan;
use App\Models\Tenant;
use App\Services\Bitrix24\BitrixPlacementEventSyncer;
use App\Services\Bitrix24\BitrixSchemaProvisioner;
use App\Services\Bitrix24\BitrixSchemaScanner;
use App\Services\Subscriptions\SubscriptionGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BitrixPortalApiController extends Controller
{
    public function __construct(
        private readonly BitrixSchemaScanner $scanner,
        private readonly BitrixSchemaProvisioner $provisioner,
        private readonly BitrixPlacementEventSyncer $syncer,
        private readonly SubscriptionGate $gate,
    ) {}

    public function completeInstall(Request $request): JsonResponse
    {
        /** @var Tenant $tenant */
        $tenant = $request->attributes->get('bitrix_tenant');

        if (! in_array($tenant->install_status, [Tenant::INSTALL_INSTALLING, Tenant::INSTALL_ACTIVE], true)) {
            return response()->json([
                'success' => false,
                'message' => 'Portal installation cannot be completed in its current status.',
            ], 403);
        }

        $tenant->forceFill([
            'install_status' => Tenant::INSTALL_ACTIVE,
            'installed_at' => $tenant->installed_at ?: now(),
            'uninstalled_at' => null,
        ])->save();

        $subscription = $this->gate->ensureInitialSubscription($tenant);
        $bindings = $this->syncer->sync($tenant);

        return response()->json([
            'success' => true,
            'install_status' => $tenant->install_status,
            'subscription' => $subscription,
            'bindings' => $bindings,
        ]);
    }

    public function scanSchema(Request $request): JsonResponse
    {
        /** @var Tenant $tenant */
        $tenant = $request->attributes->get('bitrix_tenant');
        $subEval = $this->gate->evaluate($tenant, requireSyncQuota: false);
        if (! $subEval['allowed']) {
            return response()->json(['success' => false, 'message' => $subEval['reason']], 403);
        }

        $inspectIds = array_values(array_filter(array_map('intval', (array) $request->input('entity_type_ids', []))));

        try {
            $snapshot = $this->scanner->scanPortal($tenant, $inspectIds);

            return response()->json([
                'success' => true,
                'snapshot' => $snapshot,
            ]);
        } catch (BitrixApiException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error_code' => $e->errorCode,
            ], 422);
        }
    }

    public function provisionAuto(Request $request): JsonResponse
    {
        /** @var Tenant $tenant */
        $tenant = $request->attributes->get('bitrix_tenant');
        $subEval = $this->gate->evaluate($tenant, requireSyncQuota: false);
        if (! $subEval['allowed']) {
            return response()->json(['success' => false, 'message' => $subEval['reason']], 403);
        }

        $validated = $request->validate([
            'fee_sync_mode' => ['nullable', 'in:dedicated_spas,consolidated_spas,subtotals_only'],
            'entity_type_ids' => ['required', 'array', 'min:1'],
            'entity_type_ids.*' => ['integer', 'min:1'],
            'options_config' => ['nullable', 'array'],
            'cost_types_config' => ['nullable', 'array'],
            'option_meta_schema' => ['nullable', 'array'],
            'extra_attributes' => ['nullable', 'array'],
        ]);

        try {
            $result = $this->provisioner->provisionAuto($tenant, $validated);
            $tenant->refresh()->load(['catalogConfig', 'spaBuckets', 'entityConfigs.fieldMappings', 'enumMappings']);

            return response()->json([
                'success' => true,
                'result' => $result,
                'tenant' => [
                    'fee_sync_mode' => $tenant->fee_sync_mode,
                    'schema_status' => $tenant->schema_status,
                    'catalog_config' => $tenant->catalogConfig,
                    'spa_buckets' => $tenant->spaBuckets,
                    'entity_configs' => $tenant->entityConfigs,
                    'enum_mappings' => $tenant->enumMappings,
                ],
            ]);
        } catch (BitrixApiException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error_code' => $e->errorCode,
                'is_spa_restricted' => $e->isSpaRestricted(),
            ], 422);
        }
    }

    public function saveMappings(Request $request): JsonResponse
    {
        /** @var Tenant $tenant */
        $tenant = $request->attributes->get('bitrix_tenant');
        $subEval = $this->gate->evaluate($tenant, requireSyncQuota: false);
        if (! $subEval['allowed']) {
            return response()->json(['success' => false, 'message' => $subEval['reason']], 403);
        }

        $validated = $request->validate([
            'fee_sync_mode' => ['required', 'in:dedicated_spas,consolidated_spas,subtotals_only'],
            'catalog' => ['required', 'array'],
            'catalog.iblock_id' => ['nullable', 'integer', 'min:1'],
            'catalog.catalog_name' => ['nullable', 'string', 'max:255'],
            'catalog.cost_type_prop_code' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9_]+$/'],
            'catalog.cost_type_prop_id' => ['nullable', 'integer', 'min:1'],
            'catalog.option_prop_code' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9_]+$/'],
            'catalog.option_prop_id' => ['nullable', 'integer', 'min:1'],
            'catalog.payments_prop_code' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9_]+$/'],
            'catalog.payments_prop_id' => ['nullable', 'integer', 'min:1'],
            'options_config' => ['nullable', 'array', 'max:10'],
            'cost_types_config' => ['nullable', 'array', 'max:20'],
            'option_meta_schema' => ['nullable', 'array', 'max:20'],
            'extra_attributes' => ['nullable', 'array', 'max:30'],
            'spa_buckets' => ['nullable', 'array', 'max:30'],
            'spa_buckets.*.bucket_key' => ['nullable', 'string', 'max:64', 'alpha_dash'],
            'spa_buckets.*.title' => ['nullable', 'string', 'max:120'],
            'spa_buckets.*.cost_type_key' => ['nullable', 'string', 'max:64'],
            'spa_buckets.*.option_key' => ['nullable', 'string', 'max:64'],
            'spa_buckets.*.spa_id' => ['nullable', 'integer', 'min:1'],
            'spa_buckets.*.spa_entity_type_id' => ['nullable', 'integer', 'min:128'],
            'spa_buckets.*.is_active' => ['nullable', 'boolean'],
            'spa_buckets.*.field_map' => ['nullable', 'array'],
            'spa_buckets.*.field_map.*' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9_]+$/'],
            'entities' => ['required', 'array', 'min:1', 'max:20'],
            'entities.*.entity_type_id' => ['required', 'integer', 'min:1'],
            'entities.*.logical_key' => ['nullable', 'string', 'max:64', 'alpha_dash'],
            'entities.*.title' => ['nullable', 'string', 'max:120'],
            'entities.*.is_enabled' => ['nullable', 'boolean'],
            'entities.*.auto_sync_enabled' => ['nullable', 'boolean'],
            'entities.*.mappings' => ['nullable', 'array', 'max:100'],
            'entities.*.mappings.*.role' => ['required_with:entities.*.mappings', 'in:subtotal,option_total,spa_link,option_meta,sync_status'],
            'entities.*.mappings.*.role_qualifier' => ['required_with:entities.*.mappings', 'string', 'max:64'],
            'entities.*.mappings.*.b24_field_code' => ['required_with:entities.*.mappings', 'string', 'max:64', 'regex:/^[A-Za-z0-9_]+$/'],
            'entities.*.mappings.*.b24_field_type' => ['nullable', 'string', 'max:32'],
            'entities.*.mappings.*.b24_settings_json' => ['nullable', 'array'],
            'enum_mappings' => ['nullable', 'array', 'max:200'],
            'enum_mappings.*.mapping_group' => ['nullable', 'string', 'max:64'],
            'enum_mappings.*.attr_key' => ['nullable', 'string', 'max:64'],
            'enum_mappings.*.logical_key' => ['nullable', 'string', 'max:64'],
            'enum_mappings.*.logical_label' => ['nullable', 'string', 'max:120'],
            'enum_mappings.*.catalog_prop_code' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9_]+$/'],
            'enum_mappings.*.catalog_enum_id' => ['nullable', 'string', 'max:64'],
            'enum_mappings.*.catalog_enum_xml_id' => ['nullable', 'string', 'max:128'],
            'enum_mappings.*.spa_entity_type_id' => ['nullable', 'integer', 'min:0'],
            'enum_mappings.*.spa_field_code' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9_]+$/'],
            'enum_mappings.*.spa_enum_id' => ['nullable', 'string', 'max:64'],
        ]);

        $result = $this->provisioner->saveCustomMappings($tenant, $validated);
        $tenant->refresh()->load(['catalogConfig', 'spaBuckets', 'entityConfigs.fieldMappings', 'enumMappings']);

        return response()->json([
            'success' => true,
            'result' => $result,
            'tenant' => [
                'fee_sync_mode' => $tenant->fee_sync_mode,
                'schema_status' => $tenant->schema_status,
                'catalog_config' => $tenant->catalogConfig,
                'spa_buckets' => $tenant->spaBuckets,
                'entity_configs' => $tenant->entityConfigs,
                'enum_mappings' => $tenant->enumMappings,
            ],
        ]);
    }

    public function syncBindings(Request $request): JsonResponse
    {
        /** @var Tenant $tenant */
        $tenant = $request->attributes->get('bitrix_tenant');
        $subEval = $this->gate->evaluate($tenant, requireSyncQuota: false);
        if (! $subEval['allowed']) {
            return response()->json(['success' => false, 'message' => $subEval['reason']], 403);
        }

        $bindings = $this->syncer->sync(
            $tenant,
            (bool) ($subEval['features']['allow_auto_background_sync'] ?? true)
        );

        return response()->json([
            'success' => true,
            'bindings' => $bindings,
        ]);
    }

    public function runBackfill(Request $request): JsonResponse
    {
        /** @var Tenant $tenant */
        $tenant = $request->attributes->get('bitrix_tenant');
        $subEval = $this->gate->evaluate($tenant, requireSyncQuota: true);

        if (! $subEval['allowed']) {
            return response()->json(['success' => false, 'message' => $subEval['reason']], 403);
        }
        if (empty($subEval['features']['allow_backfill'])) {
            return response()->json(['success' => false, 'message' => 'Batch backfill requires Starter plan or higher.'], 403);
        }

        $validated = $request->validate([
            'entity_type_id' => ['required', 'integer', 'min:1'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:250'],
        ]);

        PortalBackfillJob::dispatch(
            $tenant->id,
            (int) $validated['entity_type_id'],
            (int) ($validated['limit'] ?? 50)
        );

        return response()->json([
            'success' => true,
            'message' => 'Backfill job queued cleanly. Progress will appear in Sync Logs.',
        ]);
    }

    public function logs(Request $request): JsonResponse
    {
        /** @var Tenant $tenant */
        $tenant = $request->attributes->get('bitrix_tenant');

        return response()->json([
            'success' => true,
            'logs' => $tenant->syncLogs()->latest('id')->limit(50)->get(),
        ]);
    }

    public function requestUpgrade(Request $request): JsonResponse
    {
        /** @var Tenant $tenant */
        $tenant = $request->attributes->get('bitrix_tenant');

        $validated = $request->validate([
            'plan_id' => ['required', 'exists:plans,id'],
            'billing_cycle' => ['nullable', 'in:monthly,yearly'],
        ]);

        $plan = Plan::where('is_active', true)->where('is_public', true)->findOrFail($validated['plan_id']);
        $subscription = $this->gate->ensureInitialSubscription($tenant);

        $subscription->forceFill([
            'upgrade_requested_plan_id' => $plan->id,
            'upgrade_requested_at' => now(),
            'billing_cycle' => $validated['billing_cycle'] ?? $subscription->billing_cycle,
        ])->save();

        return response()->json([
            'success' => true,
            'message' => "Upgrade request for '{$plan->name}' recorded. Our team will activate your plan or send your invoice shortly.",
            'subscription' => $subscription->fresh('plan', 'upgradeRequestedPlan'),
        ]);
    }
}
