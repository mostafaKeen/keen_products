<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use App\Models\Plan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AdminPlanController extends Controller
{
    public function index(): Response
    {
        $plans = Plan::withCount('subscriptions')
            ->orderBy('sort_order')
            ->get();

        return Inertia::render('admin/plans/index', [
            'plans' => $plans,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validatePlan($request);
        $plan = Plan::create($validated);

        AdminAuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'plan_created',
            'details_json' => ['plan_id' => $plan->id, 'code' => $plan->code],
            'ip_address' => $request->ip(),
            'created_at' => now(),
        ]);

        return back()->with('success', "Plan '{$plan->name}' created.");
    }

    public function update(Request $request, Plan $plan): RedirectResponse
    {
        $validated = $this->validatePlan($request, $plan->id);
        $plan->update($validated);

        AdminAuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'plan_updated',
            'details_json' => ['plan_id' => $plan->id, 'code' => $plan->code],
            'ip_address' => $request->ip(),
            'created_at' => now(),
        ]);

        return back()->with('success', "Plan '{$plan->name}' updated.");
    }

    private function validatePlan(Request $request, ?int $ignoreId = null): array
    {
        $uniqueRule = $ignoreId ? "unique:plans,code,{$ignoreId}" : 'unique:plans,code';

        $data = $request->validate([
            'code' => ['required', 'string', 'max:64', 'alpha_dash', $uniqueRule],
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string'],
            'price_monthly_cents' => ['required', 'integer', 'min:0'],
            'price_yearly_cents' => ['required', 'integer', 'min:0'],
            'billing_type' => ['nullable', 'in:fixed,per_user'],
            'price_per_user_cents' => ['nullable', 'integer', 'min:0'],
            'currency' => ['required', 'string', 'size:3'],
            'trial_days' => ['required', 'integer', 'min:0', 'max:365'],
            'max_monthly_syncs' => ['nullable', 'integer', 'min:1'],
            'max_options' => ['required', 'integer', 'min:1', 'max:10'],
            'sort_order' => ['required', 'integer', 'min:0'],
            'is_active' => ['required', 'boolean'],
            'is_public' => ['required', 'boolean'],
            'features_json' => ['required', 'array'],
            'features_json.allow_auto_background_sync' => ['boolean'],
            'features_json.allow_bidirectional_spa_sync' => ['boolean'],
            'features_json.allow_custom_spas' => ['boolean'],
            'features_json.allow_custom_product_attributes' => ['boolean'],
            'features_json.allow_backfill' => ['boolean'],
        ]);

        $data['currency'] = strtoupper($data['currency']);

        return $data;
    }
}
