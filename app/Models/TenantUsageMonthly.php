<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantUsageMonthly extends Model
{
    protected $table = 'tenant_usage_monthly';

    protected $fillable = [
        'tenant_id',
        'year_month',
        'sync_count',
        'api_calls_count',
        'webhook_count',
    ];

    protected function casts(): array
    {
        return [
            'sync_count' => 'integer',
            'api_calls_count' => 'integer',
            'webhook_count' => 'integer',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}

