<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Models\Tenant;
use App\Services\Subscriptions\SubscriptionGate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AdminSubscriptionController extends Controller
{
    public function __construct(
        private readonly SubscriptionGate $gate,
    ) {}

    public function index(Request $request): Response
    {
        $statusFilter = trim((string) $request->input('status', ''));

        $query = Subscription::with([
            'tenant:id,domain,member_id,install_status',
            'plan:id,name,code,price_monthly_cents,currency',
            'upgradeRequestedPlan:id,name,code',
        ])->latest('id');

        if ($statusFilter === 'upgrade_requests') {
            $query->whereNotNull('upgrade_requested_plan_id');
        } elseif ($statusFilter !== '') {
            $query->where('status', $statusFilter);
        }

        $subscriptions = $query->paginate(20)->withQueryString();
        $recentPayments = SubscriptionPayment::with([
            'tenant:id,domain',
            'plan:id,name',
            'recordedBy:id,name',
        ])->latest('id')->limit(25)->get();

        return Inertia::render('admin/subscriptions/index', [
            'subscriptions' => $subscriptions,
            'recent_payments' => $recentPayments,
            'plans' => Plan::where('is_active', true)->orderBy('sort_order')->get(),
            'filter_status' => $statusFilter,
        ]);
    }

    public function activate(Request $request, Tenant $tenant): RedirectResponse
    {
        $subscription = $this->gate->ensureInitialSubscription($tenant);

        $now = now();
        $currentEnd = $subscription->current_period_end;
        if ($currentEnd === null || $currentEnd->isPast()) {
            $currentEnd = match ($subscription->billing_cycle) {
                'yearly' => $now->copy()->addYear(),
                default => $now->copy()->addMonth(),
            };
        }

        $subscription->update([
            'status' => Subscription::STATUS_ACTIVE,
            'current_period_start' => $now,
            'current_period_end' => $currentEnd,
            'grace_ends_at' => null,
            'canceled_at' => null,
            'upgrade_requested_plan_id' => null,
            'upgrade_requested_at' => null,
        ]);

        AdminAuditLog::create([
            'user_id' => $request->user()->id,
            'tenant_id' => $tenant->id,
            'action' => 'subscription_activated',
            'details_json' => [
                'status' => Subscription::STATUS_ACTIVE,
                'current_period_end' => $currentEnd->toIso8601String(),
            ],
            'ip_address' => $request->ip(),
            'created_at' => now(),
        ]);

        return back()->with('success', "Subscription for {$tenant->domain} has been activated.");
    }

    public function deactivate(Request $request, Tenant $tenant): RedirectResponse
    {
        $subscription = $this->gate->ensureInitialSubscription($tenant);

        $subscription->update([
            'status' => Subscription::STATUS_SUSPENDED,
        ]);

        AdminAuditLog::create([
            'user_id' => $request->user()->id,
            'tenant_id' => $tenant->id,
            'action' => 'subscription_deactivated',
            'details_json' => [
                'status' => Subscription::STATUS_SUSPENDED,
            ],
            'ip_address' => $request->ip(),
            'created_at' => now(),
        ]);

        return back()->with('success', "Subscription for {$tenant->domain} has been deactivated (suspended).");
    }

    public function update(Request $request, Tenant $tenant): RedirectResponse
    {
        $validated = $request->validate([
            'plan_id' => ['required', 'exists:plans,id'],
            'seats_count' => ['nullable', 'integer', 'min:1'],
            'status' => ['required', 'in:trialing,active,grace_period,past_due,suspended,expired,canceled'],
            'billing_cycle' => ['required', 'in:monthly,yearly,manual'],
            'trial_ends_at' => ['nullable', 'date'],
            'current_period_end' => ['nullable', 'date'],
            'grace_ends_at' => ['nullable', 'date'],
            'max_monthly_syncs_override' => ['nullable', 'integer', 'min:1'],
            'max_options_override' => ['nullable', 'integer', 'min:1', 'max:10'],
            'admin_notes' => ['nullable', 'string'],
            'clear_upgrade_request' => ['nullable', 'boolean'],
        ]);

        $subscription = $this->gate->ensureInitialSubscription($tenant);

        $customLimits = $subscription->custom_limits_json ?? [];
        if (array_key_exists('max_monthly_syncs_override', $validated)) {
            if ($validated['max_monthly_syncs_override'] !== null) {
                $customLimits['max_monthly_syncs'] = (int) $validated['max_monthly_syncs_override'];
            } else {
                unset($customLimits['max_monthly_syncs']);
            }
        }
        if (array_key_exists('max_options_override', $validated)) {
            if ($validated['max_options_override'] !== null) {
                $customLimits['max_options'] = (int) $validated['max_options_override'];
            } else {
                unset($customLimits['max_options']);
            }
        }

        $updateData = [
            'plan_id' => $validated['plan_id'],
            'status' => $validated['status'],
            'billing_cycle' => $validated['billing_cycle'],
            'trial_ends_at' => $validated['trial_ends_at'] ?? null,
            'current_period_end' => $validated['current_period_end'] ?? null,
            'grace_ends_at' => $validated['grace_ends_at'] ?? null,
            'custom_limits_json' => $customLimits ?: null,
            'admin_notes' => $validated['admin_notes'] ?? null,
        ];

        if (! empty($validated['seats_count'])) {
            $updateData['seats_count'] = (int) $validated['seats_count'];
        }

        if (! empty($validated['clear_upgrade_request'])) {
            $updateData['upgrade_requested_plan_id'] = null;
            $updateData['upgrade_requested_at'] = null;
        }

        $subscription->update($updateData);

        AdminAuditLog::create([
            'user_id' => $request->user()->id,
            'tenant_id' => $tenant->id,
            'action' => 'subscription_updated',
            'details_json' => [
                'plan_id' => $validated['plan_id'],
                'status' => $validated['status'],
                'billing_cycle' => $validated['billing_cycle'],
            ],
            'ip_address' => $request->ip(),
            'created_at' => now(),
        ]);

        return back()->with('success', 'Tenant subscription updated.');
    }

    public function recordPayment(Request $request, Tenant $tenant): RedirectResponse
    {
        $validated = $request->validate([
            'plan_id' => ['required', 'exists:plans,id'],
            'amount_cents' => ['required', 'integer', 'min:0'],
            'currency' => ['required', 'string', 'size:3'],
            'billing_cycle' => ['required', 'in:monthly,yearly,manual'],
            'provider' => ['required', 'in:manual,stripe,paddle,wire'],
            'reference' => ['nullable', 'string', 'max:128'],
            'invoice_number' => ['nullable', 'string', 'max:64'],
            'notes' => ['nullable', 'string'],
        ]);

        $subscription = $this->gate->ensureInitialSubscription($tenant);
        $periodStart = now();
        $periodEnd = match ($validated['billing_cycle']) {
            'yearly' => $periodStart->copy()->addYear(),
            default => $periodStart->copy()->addMonth(),
        };

        $payment = SubscriptionPayment::create([
            'subscription_id' => $subscription->id,
            'tenant_id' => $tenant->id,
            'plan_id' => $validated['plan_id'],
            'amount_cents' => $validated['amount_cents'],
            'currency' => strtoupper($validated['currency']),
            'status' => 'paid',
            'provider' => $validated['provider'],
            'reference' => $validated['reference'] ?? null,
            'invoice_number' => $validated['invoice_number'] ?? ('INV-'.strtoupper(uniqid())),
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'paid_at' => now(),
            'recorded_by_user_id' => $request->user()->id,
            'notes' => $validated['notes'] ?? null,
        ]);

        $subscription->update([
            'plan_id' => $validated['plan_id'],
            'status' => Subscription::STATUS_ACTIVE,
            'billing_cycle' => $validated['billing_cycle'],
            'billing_provider' => $validated['provider'],
            'current_period_start' => $periodStart,
            'current_period_end' => $periodEnd,
            'grace_ends_at' => null,
            'upgrade_requested_plan_id' => null,
            'upgrade_requested_at' => null,
        ]);

        AdminAuditLog::create([
            'user_id' => $request->user()->id,
            'tenant_id' => $tenant->id,
            'action' => 'payment_recorded',
            'details_json' => [
                'payment_id' => $payment->id,
                'amount_cents' => $payment->amount_cents,
                'currency' => $payment->currency,
                'invoice_number' => $payment->invoice_number,
            ],
            'ip_address' => $request->ip(),
            'created_at' => now(),
        ]);

        return back()->with('success', "Payment {$payment->invoice_number} recorded and subscription activated.");
    }
}
