<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TenantEntityConfig extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'logical_key',
        'title',
        'entity_type_id',
        'owner_type_code',
        'placement_code',
        'is_enabled',
        'auto_sync_enabled',
        'last_bound_at',
    ];

    protected function casts(): array
    {
        return [
            'entity_type_id' => 'integer',
            'is_enabled' => 'boolean',
            'auto_sync_enabled' => 'boolean',
            'last_bound_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function fieldMappings(): HasMany
    {
        return $this->hasMany(TenantFieldMapping::class, 'entity_config_id');
    }

    public static function ownerCodeForEntityTypeId(int $entityTypeId): string
    {
        return match ($entityTypeId) {
            1 => 'L',
            2 => 'D',
            3 => 'C',
            4 => 'CO',
            7 => 'Q',
            31 => 'SI',
            default => 'T'.strtolower(dechex($entityTypeId)),
        };
    }

    public static function placementCodeForEntityTypeId(int $entityTypeId): string
    {
        return match ($entityTypeId) {
            1 => 'CRM_LEAD_DETAIL_TAB',
            2 => 'CRM_DEAL_DETAIL_TAB',
            3 => 'CRM_CONTACT_DETAIL_TAB',
            4 => 'CRM_COMPANY_DETAIL_TAB',
            7 => 'CRM_QUOTE_DETAIL_TAB',
            31 => 'CRM_SMART_INVOICE_DETAIL_TAB',
            default => "CRM_DYNAMIC_{$entityTypeId}_DETAIL_TAB",
        };
    }

    public function isClassicEntity(): bool
    {
        return in_array($this->entity_type_id, [1, 2, 7], true);
    }
}
