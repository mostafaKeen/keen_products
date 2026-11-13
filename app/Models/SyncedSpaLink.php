<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SyncedSpaLink extends Model
{
    protected $fillable = [
        'tenant_id',
        'parent_entity_type_id',
        'parent_entity_id',
        'product_row_id',
        'product_id',
        'bucket_key',
        'spa_entity_type_id',
        'spa_item_id',
        'external_id',
        'payload_hash',
    ];

    protected function casts(): array
    {
        return [
            'parent_entity_type_id' => 'integer',
            'parent_entity_id' => 'integer',
            'product_row_id' => 'integer',
            'product_id' => 'integer',
            'spa_entity_type_id' => 'integer',
            'spa_item_id' => 'integer',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
