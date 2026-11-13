<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantEnumMapping extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'mapping_group',
        'attr_key',
        'logical_key',
        'logical_label',
        'catalog_prop_code',
        'catalog_enum_id',
        'catalog_enum_xml_id',
        'spa_entity_type_id',
        'spa_field_code',
        'spa_enum_id',
    ];

    protected function casts(): array
    {
        return [
            'spa_entity_type_id' => 'integer',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
