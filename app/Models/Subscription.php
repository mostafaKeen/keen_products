<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Subscription extends Model
{
    use HasFactory;

    public const STATUS_TRIALING = 'trialing';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_GRACE_PERIOD = 'grace_period';

    public const STATUS_PAST_DUE = 'past_due';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_CANCELED = 'canceled';

    protected $fillable = [
        'tenant_id',
        'plan_id',
        'status',
        'billing_cycle',
        'billing_provider',
        'external_customer_id',
        'external_subscription_id',
        'trial_ends_at',
        'current_period_start',
        'current_period_end',
        'grace_ends_at',
        'canceled_at',
        'custom_limits_json',
        'admin_notes',
        'upgrade_requested_plan_id',
        'upgrade_requested_at',
    ];

    protected function casts(): array
    {
        return [
            'trial_ends_at' => 'datetime',
            'current_period_start' => 'datetime',
            'current_period_end' => 'datetime',
            'grace_ends_at' => 'datetime',
            'canceled_at' => 'datetime',
            'custom_limits_json' => 'array',
            'upgrade_requested_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function upgradeRequestedPlan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'upgrade_requested_plan_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SubscriptionPayment::class);
    }

    public function effectiveMaxMonthlySyncs(): ?int
    {
        $custom = $this->custom_limits_json ?? [];
        if (array_key_exists('max_monthly_syncs', $custom)) {
            return $custom['max_monthly_syncs'] !== null ? (int) $custom['max_monthly_syncs'] : null;
        }

        return $this->plan?->max_monthly_syncs;
    }

    public function effectiveMaxOptions(): int
    {
        $custom = $this->custom_limits_json ?? [];
        if (isset($custom['max_options'])) {
            return max(1, (int) $custom['max_options']);
        }

        return max(1, (int) ($this->plan?->max_options ?? 2));
    }

    public function effectiveFeature(string $key, bool $default = false): bool
    {
        $custom = $this->custom_limits_json ?? [];
        if (isset($custom['features'][$key])) {
            return (bool) $custom['features'][$key];
        }

        return $this->plan?->hasFeature($key, $default) ?? $default;
    }
}
