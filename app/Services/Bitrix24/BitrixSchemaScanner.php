<?php

namespace App\Services\Bitrix24;

use App\Exceptions\BitrixApiException;
use App\Models\Tenant;
use App\Models\TenantCatalogConfig;
use App\Models\TenantEntityConfig;
use Illuminate\Support\Str;

class BitrixSchemaScanner
{
    public function __construct(
        private readonly Bitrix24Client $client,
        private readonly BitrixFieldAdapter $adapter,
    ) {}

    /**
     * Introspect the portal's Bitrix24 Catalog, SPAs, Entity fields, and Currencies.
     *
     * @param  list<int>  $inspectEntityTypeIds
     * @return array<string, mixed>
     */
    public function scanPortal(Tenant $tenant, array $inspectEntityTypeIds = []): array
    {
        $warnings = [];

        // 1. Portal app.info & license metadata
        $appInfo = $this->safeCall($tenant, 'app.info', [], $warnings, 'app.info');
        if (! empty($appInfo['LICENSE'])) {
            $licenseType = (string) $appInfo['LICENSE'];
            $licenseFamily = str_contains(strtolower($licenseType), 'ent') ? 'ent' : 'std';
            $tenant->forceFill([
                'license_type' => $licenseType,
                'license_family' => $licenseFamily,
            ])->saveQuietly();
        }

        // 2. Currencies
        $currRes = $this->safeCall($tenant, 'crm.currency.list', [], $warnings, 'crm.currency.list');
        $currencies = [];
        foreach ((array) ($currRes ?? []) as $curr) {
            if (! empty($curr['CURRENCY'])) {
                $currencies[] = [
                    'code' => (string) $curr['CURRENCY'],
                    'name' => (string) ($curr['FULL_NAME'] ?? $curr['CURRENCY']),
                    'base' => ($curr['BASE'] ?? 'N') === 'Y',
                    'format' => (string) ($curr['FORMAT_STRING'] ?? ''),
                ];
            }
        }
        if ($currencies === []) {
            $currencies[] = ['code' => 'USD', 'name' => 'US Dollar', 'base' => true, 'format' => '$#'];
        }

        // 3. Catalog + properties + enum options
        $catalogRes = $this->safeCall($tenant, 'catalog.catalog.list', [], $warnings, 'catalog.catalog.list');
        $catalogs = (array) ($catalogRes['catalogs'] ?? []);
        $primaryCatalog = collect($catalogs)->first(function (array $cat) {
            return empty($cat['productIblockId']) || (int) $cat['productIblockId'] === 0;
        }) ?: ($catalogs[0] ?? null);

        $iblockId = $primaryCatalog ? (int) ($primaryCatalog['iblockId'] ?? $primaryCatalog['id'] ?? 0) : 0;

        $properties = [];
        if ($iblockId > 0) {
            $propRes = $this->safeCall($tenant, 'catalog.productProperty.list', [
                'filter' => ['iblockId' => $iblockId],
                'select' => ['id', 'iblockId', 'name', 'code', 'xmlId', 'propertyType', 'multiple', 'userType'],
            ], $warnings, 'catalog.productProperty.list');

            foreach ((array) ($propRes['productProperties'] ?? []) as $prop) {
                $propId = (int) ($prop['id'] ?? 0);
                if ($propId <= 0) {
                    continue;
                }

                $enums = [];
                if (strtoupper((string) ($prop['propertyType'] ?? '')) === 'L') {
                    $enumRes = $this->safeCall($tenant, 'catalog.productPropertyEnum.list', [
                        'filter' => ['propertyId' => $propId],
                        'select' => ['id', 'propertyId', 'value', 'xmlId', 'sort', 'def'],
                    ], $warnings, "catalog.productPropertyEnum.list({$propId})");

                    foreach ((array) ($enumRes['productPropertyEnums'] ?? []) as $enum) {
                        $enums[] = [
                            'id' => (string) $enum['id'],
                            'value' => (string) ($enum['value'] ?? ''),
                            'xmlId' => (string) ($enum['xmlId'] ?? ''),
                            'sort' => (int) ($enum['sort'] ?? 100),
                        ];
                    }
                }

                $properties[] = [
                    'id' => $propId,
                    'prop_code' => 'property'.$propId,
                    'name' => (string) ($prop['name'] ?? ''),
                    'code' => (string) ($prop['code'] ?? ''),
                    'xmlId' => (string) ($prop['xmlId'] ?? ''),
                    'propertyType' => (string) ($prop['propertyType'] ?? 'S'),
                    'multiple' => ($prop['multiple'] ?? 'N') === 'Y',
                    'enums' => $enums,
                ];
            }
        }

        // 4. Smart Processes (SPAs)
        $typesRes = $this->safeCall($tenant, 'crm.type.list', [], $warnings, 'crm.type.list');
        $spas = [];
        $spaFields = [];
        foreach ((array) ($typesRes['types'] ?? []) as $type) {
            $typeId = (int) ($type['id'] ?? 0);
            $entityTypeId = (int) ($type['entityTypeId'] ?? 0);
            if ($entityTypeId <= 0) {
                continue;
            }
            $spas[] = [
                'id' => $typeId,
                'entityTypeId' => $entityTypeId,
                'title' => (string) ($type['title'] ?? "SPA {$entityTypeId}"),
                'ownerTypeCode' => TenantEntityConfig::ownerCodeForEntityTypeId($entityTypeId),
                'placementCode' => TenantEntityConfig::placementCodeForEntityTypeId($entityTypeId),
            ];

            if ($inspectEntityTypeIds === [] || in_array($entityTypeId, $inspectEntityTypeIds, true) || count($spas) <= 12) {
                $spaFields[$entityTypeId] = $this->inspectItemFields($tenant, $entityTypeId, $typeId, $warnings);
            }
        }

        // 5. Classic CRM Entities (Lead = 1, Deal = 2, Quote = 7)
        $entityFields = [
            1 => $this->inspectClassicFields($tenant, 'crm.lead.fields', $warnings),
            2 => $this->inspectClassicFields($tenant, 'crm.deal.fields', $warnings),
            7 => $this->inspectItemFields($tenant, 7, null, $warnings),
        ];
        foreach ($spaFields as $etId => $fields) {
            $entityFields[$etId] = $fields;
        }

        $snapshot = [
            'scanned_at' => now()->toIso8601String(),
            'currencies' => $currencies,
            'catalog' => [
                'iblock_id' => $iblockId ?: null,
                'name' => $primaryCatalog['name'] ?? ($primaryCatalog['NAME'] ?? 'CRM Catalog'),
                'catalogs' => $catalogs,
                'properties' => $properties,
            ],
            'spas' => $spas,
            'entity_fields' => $entityFields,
            'warnings' => $warnings,
        ];

        $snapshot['suggestions'] = $this->buildSuggestions($snapshot);

        $tenant->forceFill([
            'schema_snapshot_json' => $snapshot,
            'schema_snapshot_at' => now(),
        ])->save();

        return $snapshot;
    }

    /**
     * Suggest automatic field and enum matches for existing portals (supports XML_ID and human labels).
     *
     * @return array<string, mixed>
     */
    public function buildSuggestions(array $snapshot): array
    {
        $props = $snapshot['catalog']['properties'] ?? [];

        $costTypeProp = $this->findMatchingProperty($props, ['INLEX_COST_TYPE', 'TYPE_OF_COST'], ['type of cost', 'cost type']);
        $optionProp = $this->findMatchingProperty($props, ['INLEX_OPTION', 'OPTION'], ['option', 'quote option']);
        $paymentsProp = $this->findMatchingProperty($props, ['INLEX_PAYMENTS', 'PAYMENTS'], ['payments', 'payment schedule', 'payments old']);

        $spas = $snapshot['spas'] ?? [];
        $bucketSuggestions = [];
        $bucketRules = [
            'prof_opt1' => ['professional', 'option 1'],
            'gov_opt1' => ['government', 'option 1'],
            'prof_opt2' => ['professional', 'option 2'],
            'gov_opt2' => ['government', 'option 2'],
        ];

        foreach ($bucketRules as $bucketKey => $keywords) {
            foreach ($spas as $spa) {
                $title = strtolower((string) ($spa['title'] ?? ''));
                if (collect($keywords)->every(fn (string $kw) => str_contains($title, $kw))) {
                    $bucketSuggestions[$bucketKey] = $spa['entityTypeId'];
                    break;
                }
            }
        }

        $entitySuggestions = [];
        foreach ((array) ($snapshot['entity_fields'] ?? []) as $etId => $fields) {
            $suggested = [
                'subtotal' => [],
                'option_total' => [],
                'spa_link' => [],
                'option_meta' => [],
                'sync_status' => null,
            ];

            foreach ($fields as $f) {
                $code = (string) ($f['code'] ?? '');
                $xml = strtoupper((string) ($f['xmlId'] ?? ''));
                $label = strtolower((string) ($f['label'] ?? ''));

                if ($xml === 'INLEX_SYNC_STATUS' || str_contains($label, 'utm_check') || str_contains($label, 'fee sync status')) {
                    $suggested['sync_status'] ??= $code;
                }

                if (str_contains($label, 'sub_total_p_option1') || $xml === 'INLEX_SUBTOTAL_PROF_OPT1' || (str_contains($label, 'subtotal') && str_contains($label, 'prof') && str_contains($label, '1'))) {
                    $suggested['subtotal']['prof_opt1'] ??= $code;
                }
                if (str_contains($label, 'sub_total_g_option1') || $xml === 'INLEX_SUBTOTAL_GOV_OPT1' || (str_contains($label, 'subtotal') && str_contains($label, 'gov') && str_contains($label, '1'))) {
                    $suggested['subtotal']['gov_opt1'] ??= $code;
                }
                if (str_contains($label, 'sub_total_p_option2') || $xml === 'INLEX_SUBTOTAL_PROF_OPT2' || (str_contains($label, 'subtotal') && str_contains($label, 'prof') && str_contains($label, '2'))) {
                    $suggested['subtotal']['prof_opt2'] ??= $code;
                }
                if (str_contains($label, 'sub_total_g_option2') || $xml === 'INLEX_SUBTOTAL_GOV_OPT2' || (str_contains($label, 'subtotal') && str_contains($label, 'gov') && str_contains($label, '2'))) {
                    $suggested['subtotal']['gov_opt2'] ??= $code;
                }

                if ($xml === 'INLEX_TOTAL_OPT1' || str_contains($label, 'option 1 - total') || str_contains($label, 'option 1 total')) {
                    $suggested['option_total']['option_1'] ??= $code;
                }
                if ($xml === 'INLEX_TOTAL_OPT2' || str_contains($label, 'option 2 - total') || str_contains($label, 'option 2 total')) {
                    $suggested['option_total']['option_2'] ??= $code;
                }

                if (($f['type'] ?? '') === 'crm') {
                    foreach ($bucketSuggestions as $bKey => $spaTypeId) {
                        $settings = $f['settings'] ?? [];
                        if (($settings["DYNAMIC_{$spaTypeId}"] ?? 'N') === 'Y') {
                            $suggested['spa_link'][$bKey] ??= $code;
                        }
                    }
                }

                foreach (TenantCatalogConfig::defaultOptionMetaSchema() as $metaDef) {
                    $mKey = $metaDef['key'];
                    $mLabel = strtolower($metaDef['label']);
                    foreach ([1 => 'option_1', 2 => 'option_2'] as $num => $optKey) {
                        $normOpt = "option {$num}";
                        $normXml = strtoupper("INLEX_{$optKey}_{$mKey}");
                        if ($xml === $normXml || (str_contains($label, $normOpt) && str_contains($label, $mLabel))) {
                            $suggested['option_meta']["{$optKey}.{$mKey}"] ??= $code;
                        }
                    }
                }
            }

            $entitySuggestions[$etId] = $suggested;
        }

        return [
            'catalog_properties' => [
                'cost_type_prop_code' => $costTypeProp['prop_code'] ?? null,
                'option_prop_code' => $optionProp['prop_code'] ?? null,
                'payments_prop_code' => $paymentsProp['prop_code'] ?? null,
            ],
            'spa_buckets' => $bucketSuggestions,
            'entities' => $entitySuggestions,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $properties
     */
    private function findMatchingProperty(array $properties, array $codes, array $labels): ?array
    {
        foreach ($properties as $prop) {
            if (in_array(strtoupper((string) ($prop['xmlId'] ?? '')), $codes, true)
                || in_array(strtoupper((string) ($prop['code'] ?? '')), $codes, true)) {
                return $prop;
            }
        }

        foreach ($properties as $prop) {
            $name = strtolower(trim((string) ($prop['name'] ?? '')));
            foreach ($labels as $candidate) {
                if ($name === $candidate || str_contains($name, $candidate)) {
                    return $prop;
                }
            }
        }

        return null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function inspectClassicFields(Tenant $tenant, string $method, array &$warnings): array
    {
        $res = $this->safeCall($tenant, $method, [], $warnings, $method);
        if (! is_array($res)) {
            return [];
        }

        $out = [];
        foreach ($res as $code => $def) {
            if (! is_array($def) || ! Str::startsWith((string) $code, 'UF_CRM')) {
                continue;
            }
            $out[] = [
                'code' => (string) $code,
                'label' => $this->adapter->extractLabel($def, (string) $code),
                'type' => (string) ($def['type'] ?? 'string'),
                'multiple' => (bool) ($def['isMultiple'] ?? false),
                'xmlId' => (string) ($def['xmlId'] ?? $def['XML_ID'] ?? ''),
                'settings' => (array) ($def['settings'] ?? []),
                'items' => (array) ($def['items'] ?? []),
            ];
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function inspectItemFields(Tenant $tenant, int $entityTypeId, ?int $spaInternalId, array &$warnings): array
    {
        $res = $this->safeCall($tenant, 'crm.item.fields', [
            'entityTypeId' => $entityTypeId,
            'useOriginalUfNames' => 'Y',
        ], $warnings, "crm.item.fields({$entityTypeId})");

        $fieldsMap = (array) ($res['fields'] ?? []);

        // Enrich xmlId from userfieldconfig.list when available
        $ufXmlMap = [];
        if ($spaInternalId !== null && $spaInternalId > 0) {
            $ufRes = $this->safeCall($tenant, 'userfieldconfig.list', [
                'moduleId' => 'crm',
                'filter' => ['entityId' => "CRM_{$spaInternalId}"],
            ], $warnings, "userfieldconfig.list(CRM_{$spaInternalId})");

            foreach ((array) ($ufRes['fields'] ?? []) as $uf) {
                $fn = (string) ($uf['fieldName'] ?? '');
                if ($fn !== '') {
                    $ufXmlMap[$fn] = $uf;
                }
            }
        }

        $out = [];
        foreach ($fieldsMap as $code => $def) {
            if (! is_array($def)) {
                continue;
            }
            $codeStr = (string) $code;
            $isCustom = Str::startsWith(strtoupper($codeStr), 'UF_CRM') || Str::startsWith($codeStr, 'ufCrm');
            if (! $isCustom && ! in_array($codeStr, ['title', 'opportunity', 'currencyId', 'xmlId'], true)) {
                continue;
            }

            $ufMeta = $ufXmlMap[$codeStr] ?? [];
            $out[] = [
                'code' => $codeStr,
                'label' => $this->adapter->extractLabel($def, $codeStr),
                'type' => (string) ($def['type'] ?? 'string'),
                'multiple' => (bool) ($def['isMultiple'] ?? false),
                'xmlId' => (string) ($ufMeta['xmlId'] ?? $def['xmlId'] ?? ''),
                'settings' => (array) ($ufMeta['settings'] ?? $def['settings'] ?? []),
                'items' => (array) ($ufMeta['enum'] ?? $def['items'] ?? []),
            ];
        }

        return $out;
    }

    private function safeCall(Tenant $tenant, string $method, array $params, array &$warnings, string $label): mixed
    {
        try {
            $res = $this->client->call($tenant, $method, $params);

            return $res['result'] ?? [];
        } catch (BitrixApiException $e) {
            $warnings[] = "{$label}: {$e->getMessage()}";

            return null;
        }
    }
}
