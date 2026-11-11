<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantCatalogConfig extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'iblock_id',
        'catalog_name',
        'cost_type_prop_code',
        'cost_type_prop_id',
        'option_prop_code',
        'option_prop_id',
        'payments_prop_code',
        'payments_prop_id',
        'extra_attributes_json',
        'options_config_json',
        'cost_types_config_json',
        'option_meta_schema_json',
    ];

    protected function casts(): array
    {
        return [
            'iblock_id' => 'integer',
            'cost_type_prop_id' => 'integer',
            'option_prop_id' => 'integer',
            'payments_prop_id' => 'integer',
            'extra_attributes_json' => 'array',
            'options_config_json' => 'array',
            'cost_types_config_json' => 'array',
            'option_meta_schema_json' => 'array',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public static function defaultOptionsConfig(): array
    {
        return [
            ['key' => 'option_1', 'label' => 'Option 1', 'sort_order' => 1, 'is_enabled' => true],
            ['key' => 'option_2', 'label' => 'Option 2', 'sort_order' => 2, 'is_enabled' => true],
        ];
    }

    public static function defaultCostTypesConfig(): array
    {
        return [
            ['key' => 'professional', 'label' => 'Professional Fees', 'sort_order' => 1, 'is_enabled' => true],
            ['key' => 'government', 'label' => 'Government Cost', 'sort_order' => 2, 'is_enabled' => true],
        ];
    }

    public static function defaultPaymentSchedules(): array
    {
        return [
            ['key' => 'annually', 'label' => 'Annually', 'xml_id' => 'INLEX_PAY_ANNUALLY'],
            ['key' => 'one_time', 'label' => 'One Time', 'xml_id' => 'INLEX_PAY_ONE_TIME'],
            ['key' => 'quarterly', 'label' => 'Quarterly', 'xml_id' => 'INLEX_PAY_QUARTERLY'],
            ['key' => 'every_2_years', 'label' => 'Every 2 Years', 'xml_id' => 'INLEX_PAY_2YEARS'],
            ['key' => 'monthly', 'label' => 'Monthly', 'xml_id' => 'INLEX_PAY_MONTHLY'],
            ['key' => 'discussion', 'label' => 'In The Order Of Discussion', 'xml_id' => 'INLEX_PAY_DISCUSSION'],
            ['key' => 'variable_one_time', 'label' => 'One Time (Variable)', 'xml_id' => 'INLEX_PAY_VAR_ONE_TIME'],
        ];
    }

    public static function defaultOptionMetaSchema(): array
    {
        return [
            ['key' => 'business_activity', 'label' => 'Business Activity', 'field_type' => 'string', 'sort_order' => 1],
            ['key' => 'additional_approval', 'label' => 'Additional Approval Required', 'field_type' => 'string', 'sort_order' => 2],
            ['key' => 'estimated_timeframe', 'label' => 'Estimated Timeframe', 'field_type' => 'string', 'sort_order' => 3],
        ];
    }

    public function resolvedOptionsConfig(): array
    {
        return $this->options_config_json ?: self::defaultOptionsConfig();
    }

    public function resolvedCostTypesConfig(): array
    {
        return $this->cost_types_config_json ?: self::defaultCostTypesConfig();
    }

    public function resolvedOptionMetaSchema(): array
    {
        return $this->option_meta_schema_json ?: self::defaultOptionMetaSchema();
    }
}

