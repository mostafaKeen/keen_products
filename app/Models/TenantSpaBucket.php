<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantSpaBucket extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'bucket_key',
        'title',
        'cost_type_key',
        'option_key',
        'spa_id',
        'spa_entity_type_id',
        'is_active',
        'field_map_json',
    ];

    protected function casts(): array
    {
        return [
            'spa_id' => 'integer',
            'spa_entity_type_id' => 'integer',
            'is_active' => 'boolean',
            'field_map_json' => 'array',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function resolveFieldCode(string $logicalKey, ?string $default = null): ?string
    {
        $map = $this->field_map_json ?? [];

        return $map[$logicalKey] ?? $default;
    }
}
