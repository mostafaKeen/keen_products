<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'description',
        'price_monthly_cents',
        'price_yearly_cents',
        'currency',
        'trial_days',
        'max_monthly_syncs',
        'max_options',
        'features_json',
        'sort_order',
        'is_active',
        'is_public',
    ];

    protected function casts(): array
    {
        return [
            'price_monthly_cents' => 'integer',
            'price_yearly_cents' => 'integer',
            'trial_days' => 'integer',
            'max_monthly_syncs' => 'integer',
            'max_options' => 'integer',
            'features_json' => 'array',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
            'is_public' => 'boolean',
        ];
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function hasFeature(string $key, bool $default = false): bool
    {
        $features = $this->features_json ?? [];

        return (bool) ($features[$key] ?? $default);
    }
}

