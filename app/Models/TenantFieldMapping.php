<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantFieldMapping extends Model
{
    use HasFactory;

    public const ROLE_SUBTOTAL = 'subtotal';

    public const ROLE_OPTION_TOTAL = 'option_total';

    public const ROLE_SPA_LINK = 'spa_link';

    public const ROLE_OPTION_META = 'option_meta';

    public const ROLE_SYNC_STATUS = 'sync_status';

    protected $fillable = [
        'tenant_id',
        'entity_config_id',
        'role',
        'role_qualifier',
        'b24_field_code',
        'b24_field_type',
        'b24_settings_json',
    ];

    protected function casts(): array
    {
        return [
            'b24_settings_json' => 'array',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function entityConfig(): BelongsTo
    {
        return $this->belongsTo(TenantEntityConfig::class, 'entity_config_id');
    }
}
