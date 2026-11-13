<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SyncedEntityState extends Model
{
    protected $fillable = [
        'tenant_id',
        'entity_type_id',
        'entity_id',
        'last_hash',
        'last_synced_at',
        'summary_json',
    ];

    protected function casts(): array
    {
        return [
            'entity_type_id' => 'integer',
            'entity_id' => 'integer',
            'last_synced_at' => 'datetime',
            'summary_json' => 'array',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
