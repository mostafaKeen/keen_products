<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SyncLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'entity_type_id',
        'entity_id',
        'trigger_source',
        'status',
        'duration_ms',
        'api_calls_count',
        'actions_count',
        'actions_json',
        'errors_json',
        'operating_max',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'entity_type_id' => 'integer',
            'entity_id' => 'integer',
            'duration_ms' => 'integer',
            'api_calls_count' => 'integer',
            'actions_count' => 'integer',
            'actions_json' => 'array',
            'errors_json' => 'array',
            'operating_max' => 'float',
            'created_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}

