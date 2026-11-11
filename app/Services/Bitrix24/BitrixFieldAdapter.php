<?php

namespace App\Services\Bitrix24;

use App\Models\Tenant;
use App\Models\TenantEnumMapping;
use App\Models\TenantFieldMapping;

class BitrixFieldAdapter
{
    public function extractLabel(array $fieldDef, string $fallback = ''): string
    {
        foreach (['formLabel', 'listLabel', 'title', 'EDIT_FORM_LABEL', 'LIST_COLUMN_LABEL', 'LABEL', 'name'] as $key) {
            if (! array_key_exists($key, $fieldDef)) {
                continue;
            }
            $val = $fieldDef[$key];
            if (is_string($val) && trim($val) !== '') {
                return trim($val);
            }
            if (is_array($val)) {
                if (! empty($val['en']) && is_string($val['en'])) {
                    return trim($val['en']);
                }
                foreach ($val as $langVal) {
                    if (is_string($langVal) && trim($langVal) !== '') {
                        return trim($langVal);
                    }
                }
            }
        }

        return $fallback;
    }

    public function formatMoney(float $amount, string $currencyId): string
    {
        $currency = trim($currencyId) !== '' ? strtoupper(trim($currencyId)) : 'USD';

        return number_format($amount, 2, '.', '').'|'.$currency;
    }

    /**
     * @return array{amount: float, currency: ?string}
     */
    public function parseMoney(mixed $raw): array
    {
        if (is_numeric($raw)) {
            return ['amount' => (float) $raw, 'currency' => null];
        }

        if (is_string($raw) && str_contains($raw, '|')) {
            [$amt, $curr] = array_pad(explode('|', $raw, 2), 2, null);

            return [
                'amount' => is_numeric($amt) ? (float) $amt : 0.0,
                'currency' => $curr ? strtoupper(trim($curr)) : null,
            ];
        }

        return ['amount' => 0.0, 'currency' => null];
    }

    /**
     * Read a field value safely from a Bitrix24 record regardless of UF_CRM vs ufCrm casing.
     */
    public function readFieldValue(array $record, string $fieldCode, mixed $default = null): mixed
    {
        if (array_key_exists($fieldCode, $record)) {
            return $record[$fieldCode];
        }

        $lowerTarget = strtolower(str_replace('_', '', $fieldCode));
        foreach ($record as $key => $value) {
            if (strtolower(str_replace('_', '', (string) $key)) === $lowerTarget) {
                return $value;
            }
        }

        return $default;
    }

    /**
     * @return list<int>
     */
    public function normalizeLinkedIds(mixed $raw): array
    {
        if ($raw === null || $raw === '' || $raw === false) {
            return [];
        }

        $items = is_array($raw) ? $raw : [$raw];
        $ids = [];

        foreach ($items as $item) {
            if (is_numeric($item)) {
                $id = (int) $item;
                if ($id > 0) {
                    $ids[] = $id;
                }

                continue;
            }

            if (is_string($item) && preg_match('/(\d+)$/', trim($item), $matches)) {
                $id = (int) $matches[1];
                if ($id > 0) {
                    $ids[] = $id;
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Format a CRM binding custom field payload according to the target field's Bitrix24 settings.
     *
     * @param  list<int>  $ids
     * @return list<int|string>
     */
    public function formatCrmBindingValue(array $ids, ?TenantFieldMapping $mapping = null): array
    {
        $cleanIds = array_values(array_unique(array_filter(array_map('intval', $ids), fn (int $id) => $id > 0)));
        if ($cleanIds === []) {
            return [];
        }

        $settings = $mapping?->b24_settings_json ?? [];
        $enabledTypes = [];
        foreach ($settings as $key => $enabled) {
            if (($enabled === 'Y' || $enabled === true || $enabled === '1') && is_string($key)) {
                $enabledTypes[] = strtoupper($key);
            }
        }

        if (count($enabledTypes) > 1) {
            // Multiple bound CRM types require explicit prefix e.g. T422_15.
            foreach ($enabledTypes as $typeKey) {
                if (preg_match('/^DYNAMIC_(\d+)$/', $typeKey, $matches)) {
                    $prefix = 'T'.strtolower(dechex((int) $matches[1]));

                    return array_map(fn (int $id) => "{$prefix}_{$id}", $cleanIds);
                }
            }
        }

        return $cleanIds;
    }

    public function extractCatalogPropertyValue(?array $product, ?string $propCode): mixed
    {
        if (! $product || ! $propCode) {
            return null;
        }

        $raw = $this->readFieldValue($product, $propCode);
        if ($raw === null || $raw === '') {
            return null;
        }

        if (is_array($raw)) {
            if (array_key_exists('value', $raw)) {
                return $raw['value'];
            }
            if (array_key_exists('VALUE', $raw)) {
                return $raw['VALUE'];
            }
            if (isset($raw[0])) {
                if (is_array($raw[0])) {
                    return $raw[0]['value'] ?? $raw[0]['VALUE'] ?? null;
                }

                return $raw[0];
            }

            return null;
        }

        return $raw;
    }

    public function extractCatalogPropertyValueId(?array $product, ?string $propCode): ?int
    {
        if (! $product || ! $propCode) {
            return null;
        }

        $raw = $this->readFieldValue($product, $propCode);
        if (! is_array($raw)) {
            return null;
        }

        if (isset($raw['valueId']) && is_numeric($raw['valueId'])) {
            return (int) $raw['valueId'];
        }
        if (isset($raw[0]['valueId']) && is_numeric($raw[0]['valueId'])) {
            return (int) $raw[0]['valueId'];
        }

        return null;
    }

    public function resolveLogicalKeyFromCatalogEnum(
        Tenant $tenant,
        string $mappingGroup,
        string $attrKey,
        mixed $catalogEnumId,
    ): ?string {
        if ($catalogEnumId === null || $catalogEnumId === '') {
            return null;
        }

        $needle = (string) $catalogEnumId;

        $mapping = $tenant->enumMappings()
            ->where('mapping_group', $mappingGroup)
            ->where('attr_key', $attrKey)
            ->where('spa_entity_type_id', 0)
            ->where('catalog_enum_id', $needle)
            ->first();

        return $mapping?->logical_key;
    }

    public function resolveCatalogEnumIdFromLogicalKey(
        Tenant $tenant,
        string $mappingGroup,
        string $attrKey,
        string $logicalKey,
    ): ?string {
        $mapping = $tenant->enumMappings()
            ->where('mapping_group', $mappingGroup)
            ->where('attr_key', $attrKey)
            ->where('spa_entity_type_id', 0)
            ->where('logical_key', $logicalKey)
            ->first();

        return $mapping?->catalog_enum_id;
    }

    public function mapCatalogEnumToSpaEnum(
        Tenant $tenant,
        int $spaEntityTypeId,
        string $spaFieldCode,
        mixed $catalogEnumId,
    ): mixed {
        if ($catalogEnumId === null || $catalogEnumId === '') {
            return null;
        }

        $needle = (string) $catalogEnumId;

        /** @var ?TenantEnumMapping $direct */
        $direct = $tenant->enumMappings()
            ->where('spa_entity_type_id', $spaEntityTypeId)
            ->where('spa_field_code', $spaFieldCode)
            ->where('catalog_enum_id', $needle)
            ->first();

        if ($direct && $direct->spa_enum_id !== null && $direct->spa_enum_id !== '') {
            return $direct->spa_enum_id;
        }

        return $catalogEnumId;
    }

    /**
     * Normalize `UF_CRM_29_1784036987` to Bitrix24 REST camelCase `ufCrm29_1784036987` if needed.
     */
    public function toCamelCaseUf(string $fieldName): string
    {
        if (preg_match('/^UF_CRM_(\d+)_(.+)$/', $fieldName, $m)) {
            $suffix = $m[2];
            if (ctype_digit($suffix)) {
                return 'ufCrm'.$m[1].'_'.$suffix;
            }

            return 'ufCrm'.$m[1].str_replace('_', '', ucwords(strtolower($suffix), '_'));
        }

        if (preg_match('/^UF_CRM_(.+)$/', $fieldName, $m)) {
            return 'ufCrm'.str_replace('_', '', ucwords(strtolower($m[1]), '_'));
        }

        return $fieldName;
    }
}
