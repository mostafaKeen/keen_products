import { AlertCircle, CheckCircle2, Database, Layers, Loader2, Plus, RefreshCw, Save, Sparkles, Trash2, Wand2 } from 'lucide-react';
import { useMemo, useState } from 'react';

import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { bitrixFetch } from '@/lib/bitrix-api';

export interface ConfigChoiceItem {
    key: string;
    label: string;
    sort?: number;
    is_enabled?: boolean;
}

export interface OptionMetaFieldDef {
    key: string;
    label: string;
    type: string;
}

export interface ExtraAttrConfig {
    key: string;
    label: string;
    prop_code?: string;
    property_type?: string;
    sync_to_spa?: boolean;
    enum_options?: Array<{ key: string; label: string; id?: string }>;
}

export interface CatalogConfigData {
    iblock_id?: number | null;
    catalog_name?: string | null;
    cost_type_prop_code?: string | null;
    cost_type_prop_id?: number | null;
    option_prop_code?: string | null;
    option_prop_id?: number | null;
    payments_prop_code?: string | null;
    payments_prop_id?: number | null;
    extra_attributes_json?: ExtraAttrConfig[] | null;
    options_config_json?: ConfigChoiceItem[] | null;
    cost_types_config_json?: ConfigChoiceItem[] | null;
    option_meta_schema_json?: OptionMetaFieldDef[] | null;
}

export interface SpaBucketData {
    bucket_key: string;
    title: string;
    cost_type_key: string | null;
    option_key: string | null;
    spa_id?: number | null;
    spa_entity_type_id: number | null;
    is_active?: boolean;
    field_map_json?: Record<string, string>;
    field_map?: Record<string, string>;
}

export interface FieldMappingItem {
    role: string;
    role_qualifier: string;
    b24_field_code: string;
    b24_field_type?: string | null;
    b24_settings_json?: Record<string, unknown> | null;
}

export interface EntityConfigData {
    logical_key: string;
    title: string;
    entity_type_id: number;
    owner_type_code?: string;
    placement_code?: string;
    is_enabled: boolean;
    auto_sync_enabled: boolean;
    field_mappings?: FieldMappingItem[];
    fieldMappings?: FieldMappingItem[];
}

export interface EnumMappingData {
    mapping_group: string;
    attr_key?: string;
    logical_key: string;
    logical_label?: string;
    catalog_prop_code?: string | null;
    catalog_enum_id?: string | null;
    catalog_enum_xml_id?: string | null;
    spa_entity_type_id?: number;
    spa_field_code?: string | null;
    spa_enum_id?: string | null;
}

export interface SchemaSnapshot {
    scanned_at?: string;
    catalog?: {
        iblock_id: number | null;
        name: string;
        catalogs?: Array<Record<string, unknown>>;
        properties: Array<{
            id: number;
            prop_code: string;
            name: string;
            code: string;
            xmlId: string;
            propertyType: string;
            enums: Array<{ id: string; value: string; xmlId: string; sort: number }>;
        }>;
    };
    spas?: Array<{
        id: number;
        entityTypeId: number;
        title: string;
        ownerTypeCode: string;
        placementCode: string;
    }>;
    entity_fields?: Record<
        string,
        Array<{
            code: string;
            label: string;
            type: string;
            xmlId: string;
            settings?: Record<string, unknown>;
            items?: Array<{ ID?: string; VALUE?: string; id?: string; value?: string }>;
        }>
    >;
    suggestions?: {
        catalog_properties?: Record<string, string | null>;
        spa_buckets?: Record<string, number>;
        entities?: Record<
            string,
            {
                subtotal?: Record<string, string>;
                option_total?: Record<string, string>;
                spa_link?: Record<string, string>;
                option_meta?: Record<string, string>;
                sync_status?: string | null;
            }
        >;
    };
    warnings?: string[];
}

export interface PortalTenantSchema {
    fee_sync_mode: string;
    schema_status: string;
    catalog_config?: CatalogConfigData | null;
    spa_buckets?: SpaBucketData[];
    entity_configs?: EntityConfigData[];
    enum_mappings?: EnumMappingData[];
    schema_snapshot?: SchemaSnapshot | null;
}

interface SchemaMapperProps {
    jwt: string;
    tenant: PortalTenantSchema;
    maxOptions: number;
    features: Record<string, boolean>;
    defaults?: {
        options_config: ConfigChoiceItem[];
        cost_types_config: ConfigChoiceItem[];
        option_meta_schema: OptionMetaFieldDef[];
    };
    initialTab?: 'auto' | 'custom';
    onUpdated?: (tenantPatch: Partial<PortalTenantSchema>, result: Record<string, unknown>) => void;
}

const DEFAULT_OPTIONS: ConfigChoiceItem[] = [
    { key: 'option_1', label: 'Option 1', sort: 100, is_enabled: true },
    { key: 'option_2', label: 'Option 2', sort: 200, is_enabled: true },
];

const DEFAULT_COST_TYPES: ConfigChoiceItem[] = [
    { key: 'professional', label: 'Professional', sort: 100, is_enabled: true },
    { key: 'government', label: 'Government', sort: 200, is_enabled: true },
];

const DEFAULT_META_SCHEMA: OptionMetaFieldDef[] = [
    { key: 'scope_of_work', label: 'Scope of Work', type: 'textarea' },
    { key: 'notes', label: 'Notes', type: 'textarea' },
    { key: 'completion_time', label: 'Completion Time', type: 'text' },
];

const DEFAULT_PAYMENTS = [
    { key: '100_upfront', label: '100% Upfront' },
    { key: '50_50', label: '50% Upfront / 50% Upon Completion' },
    { key: 'upon_completion', label: 'Upon Completion' },
];

function makeBucketKey(costTypeKey: string, optionKey: string): string {
    const shortCost =
        costTypeKey === 'professional'
            ? 'prof'
            : costTypeKey === 'government'
              ? 'gov'
              : costTypeKey
                    .toLowerCase()
                    .replace(/[^a-z0-9]/g, '')
                    .slice(0, 8);
    const shortOpt = optionKey
        .toLowerCase()
        .replace('option_', 'opt')
        .replace(/[^a-z0-9_]/g, '');
    return `${shortCost}_${shortOpt}`;
}

export function SchemaMapper({ jwt, tenant, maxOptions, features, defaults, initialTab = 'auto', onUpdated }: SchemaMapperProps) {
    const [tab, setTab] = useState<'auto' | 'custom'>(initialTab);
    const [feeSyncMode, setFeeSyncMode] = useState<string>(tenant.fee_sync_mode || 'dedicated_spas');
    const [snapshot, setSnapshot] = useState<SchemaSnapshot | null>(tenant.schema_snapshot ?? null);

    // Dynamic Matrix Configuration
    const [optionsConfig, setOptionsConfig] = useState<ConfigChoiceItem[]>(
        () => tenant.catalog_config?.options_config_json ?? defaults?.options_config ?? DEFAULT_OPTIONS,
    );
    const [costTypesConfig, setCostTypesConfig] = useState<ConfigChoiceItem[]>(
        () => tenant.catalog_config?.cost_types_config_json ?? defaults?.cost_types_config ?? DEFAULT_COST_TYPES,
    );
    const [optionMetaSchema, setOptionMetaSchema] = useState<OptionMetaFieldDef[]>(
        () => tenant.catalog_config?.option_meta_schema_json ?? defaults?.option_meta_schema ?? DEFAULT_META_SCHEMA,
    );
    const [extraAttributes, setExtraAttributes] = useState<ExtraAttrConfig[]>(() => tenant.catalog_config?.extra_attributes_json ?? []);

    // Target entities for Auto-Provisioning & Mapping
    const [targetEntityIds, setTargetEntityIds] = useState<number[]>(() => {
        const existing = (tenant.entity_configs ?? []).filter((e) => e.is_enabled).map((e) => e.entity_type_id);
        return existing.length > 0 ? existing : [1, 2, 7];
    });
    const [customEntityInput, setCustomEntityInput] = useState('');

    // Path B: Custom Mapping State
    const [catalogMap, setCatalogMap] = useState<{
        iblock_id: string;
        catalog_name: string;
        cost_type_prop_code: string;
        option_prop_code: string;
        payments_prop_code: string;
    }>(() => ({
        iblock_id: tenant.catalog_config?.iblock_id ? String(tenant.catalog_config.iblock_id) : '',
        catalog_name: tenant.catalog_config?.catalog_name ?? 'CRM Catalog',
        cost_type_prop_code: tenant.catalog_config?.cost_type_prop_code ?? '',
        option_prop_code: tenant.catalog_config?.option_prop_code ?? '',
        payments_prop_code: tenant.catalog_config?.payments_prop_code ?? '',
    }));

    const [spaBucketsMap, setSpaBucketsMap] = useState<
        Record<
            string,
            {
                bucket_key: string;
                title: string;
                cost_type_key: string | null;
                option_key: string | null;
                spa_id: number | null;
                spa_entity_type_id: string;
                field_map: Record<string, string>;
            }
        >
    >(() => {
        const init: Record<
            string,
            {
                bucket_key: string;
                title: string;
                cost_type_key: string | null;
                option_key: string | null;
                spa_id: number | null;
                spa_entity_type_id: string;
                field_map: Record<string, string>;
            }
        > = {};
        for (const b of tenant.spa_buckets ?? []) {
            init[b.bucket_key] = {
                bucket_key: b.bucket_key,
                title: b.title,
                cost_type_key: b.cost_type_key,
                option_key: b.option_key,
                spa_id: b.spa_id ?? null,
                spa_entity_type_id: b.spa_entity_type_id ? String(b.spa_entity_type_id) : '',
                field_map: b.field_map_json ?? b.field_map ?? {},
            };
        }
        return init;
    });

    // entityFieldValues[entityTypeId][`${role}::${qualifier}`] = b24_field_code
    const [entityFieldValues, setEntityFieldValues] = useState<Record<number, Record<string, string>>>(() => {
        const init: Record<number, Record<string, string>> = {};
        for (const ec of tenant.entity_configs ?? []) {
            const maps: Record<string, string> = {};
            const list = ec.field_mappings ?? ec.fieldMappings ?? [];
            for (const m of list) {
                maps[`${m.role}::${m.role_qualifier}`] = m.b24_field_code;
            }
            init[ec.entity_type_id] = maps;
        }
        return init;
    });

    // enumCrosswalk[`${group}::${logicalKey}`] = { catalog_enum_id, spa_enum_id, logical_label }
    const [enumCrosswalk, setEnumCrosswalk] = useState<Record<string, { catalog_enum_id: string; spa_enum_id: string; logical_label: string }>>(
        () => {
            const init: Record<string, { catalog_enum_id: string; spa_enum_id: string; logical_label: string }> = {};
            for (const em of tenant.enum_mappings ?? []) {
                const k = `${em.mapping_group}::${em.logical_key}`;
                const prev = init[k] ?? {
                    catalog_enum_id: '',
                    spa_enum_id: '',
                    logical_label: em.logical_label ?? em.logical_key,
                };
                if (em.catalog_enum_id) prev.catalog_enum_id = String(em.catalog_enum_id);
                if (em.spa_enum_id) prev.spa_enum_id = String(em.spa_enum_id);
                init[k] = prev;
            }
            return init;
        },
    );

    const [busy, setBusy] = useState<'scan' | 'provision' | 'save' | null>(null);
    const [statusMsg, setStatusMsg] = useState<{
        type: 'success' | 'error';
        text: string;
        logs?: string[];
        warnings?: string[];
        spaRestricted?: boolean;
    } | null>(null);

    const enabledOptions = useMemo(() => optionsConfig.filter((o) => o.is_enabled !== false), [optionsConfig]);
    const enabledCostTypes = useMemo(() => costTypesConfig.filter((c) => c.is_enabled !== false), [costTypesConfig]);

    const expectedBuckets = useMemo(() => {
        if (feeSyncMode === 'subtotals_only') return [];
        if (feeSyncMode === 'consolidated_spas') {
            return [
                {
                    bucket_key: 'consolidated_all',
                    title: 'Fee Breakdown Items',
                    cost_type_key: null as string | null,
                    option_key: null as string | null,
                },
            ];
        }
        const list: Array<{
            bucket_key: string;
            title: string;
            cost_type_key: string;
            option_key: string;
        }> = [];
        for (const opt of enabledOptions) {
            for (const ct of enabledCostTypes) {
                list.push({
                    bucket_key: makeBucketKey(ct.key, opt.key),
                    title: `${ct.label} - ${opt.label}`,
                    cost_type_key: ct.key,
                    option_key: opt.key,
                });
            }
        }
        return list;
    }, [feeSyncMode, enabledOptions, enabledCostTypes]);

    const toggleEntityTarget = (etId: number) => {
        setTargetEntityIds((prev) => (prev.includes(etId) ? prev.filter((id) => id !== etId) : [...prev, etId]));
    };

    const addOptionSlot = () => {
        if (optionsConfig.length >= maxOptions) return;
        const n = optionsConfig.length + 1;
        setOptionsConfig((prev) => [
            ...prev,
            {
                key: `option_${n}`,
                label: `Option ${n}`,
                sort: n * 100,
                is_enabled: true,
            },
        ]);
    };

    const addExtraAttr = () => {
        const idx = extraAttributes.length + 1;
        setExtraAttributes((prev) => [
            ...prev,
            {
                key: `custom_attr_${idx}`,
                label: `Custom Attribute ${idx}`,
                property_type: 'S',
                sync_to_spa: true,
            },
        ]);
    };

    const handleScanSchema = async () => {
        setBusy('scan');
        setStatusMsg(null);
        try {
            const res = await bitrixFetch<{ success: boolean; snapshot: SchemaSnapshot }>('/api/bitrix/portal/scan-schema', jwt, {
                entity_type_ids: targetEntityIds,
            });
            setSnapshot(res.snapshot);
            if (!catalogMap.iblock_id && res.snapshot.catalog?.iblock_id) {
                setCatalogMap((prev) => ({
                    ...prev,
                    iblock_id: String(res.snapshot.catalog?.iblock_id ?? ''),
                    catalog_name: res.snapshot.catalog?.name || prev.catalog_name,
                }));
            }
            setStatusMsg({
                type: 'success',
                text: `Scanned portal: found ${res.snapshot.catalog?.properties.length ?? 0} catalog properties and ${res.snapshot.spas?.length ?? 0} Smart Processes.`,
                warnings: res.snapshot.warnings,
            });
        } catch (e) {
            setStatusMsg({ type: 'error', text: (e as Error).message });
        } finally {
            setBusy(null);
        }
    };

    const applySuggestions = () => {
        if (!snapshot?.suggestions) return;
        const sug = snapshot.suggestions;

        setCatalogMap((prev) => ({
            ...prev,
            iblock_id: prev.iblock_id || String(snapshot.catalog?.iblock_id ?? ''),
            cost_type_prop_code: sug.catalog_properties?.cost_type_prop_code || prev.cost_type_prop_code,
            option_prop_code: sug.catalog_properties?.option_prop_code || prev.option_prop_code,
            payments_prop_code: sug.catalog_properties?.payments_prop_code || prev.payments_prop_code,
        }));

        if (sug.spa_buckets) {
            setSpaBucketsMap((prev) => {
                const next = { ...prev };
                for (const b of expectedBuckets) {
                    const suggestedTypeId = sug.spa_buckets?.[b.bucket_key];
                    if (suggestedTypeId) {
                        const spaInfo = snapshot.spas?.find((s) => s.entityTypeId === suggestedTypeId);
                        next[b.bucket_key] = {
                            ...(next[b.bucket_key] ?? {
                                bucket_key: b.bucket_key,
                                title: spaInfo?.title ?? b.title,
                                cost_type_key: b.cost_type_key,
                                option_key: b.option_key,
                                spa_id: spaInfo?.id ?? null,
                                spa_entity_type_id: String(suggestedTypeId),
                                field_map: {
                                    title: 'title',
                                    xml_id: 'xmlId',
                                    price: 'opportunity',
                                },
                            }),
                            spa_id: spaInfo?.id ?? null,
                            spa_entity_type_id: String(suggestedTypeId),
                        };
                    }
                }
                return next;
            });
        }

        if (sug.entities) {
            setEntityFieldValues((prev) => {
                const next = { ...prev };
                for (const [etIdStr, eSug] of Object.entries(sug.entities ?? {})) {
                    const etId = Number(etIdStr);
                    const cur = { ...(next[etId] ?? {}) };
                    if (eSug.sync_status) cur['sync_status::default'] = eSug.sync_status;
                    for (const [k, v] of Object.entries(eSug.subtotal ?? {})) cur[`subtotal::${k}`] = v;
                    for (const [k, v] of Object.entries(eSug.option_total ?? {})) {
                        cur[`option_total::${k}`] = v;
                    }
                    for (const [k, v] of Object.entries(eSug.spa_link ?? {})) cur[`spa_link::${k}`] = v;
                    for (const [k, v] of Object.entries(eSug.option_meta ?? {})) {
                        cur[`option_meta::${k}`] = v;
                    }
                    next[etId] = cur;
                }
                return next;
            });
        }

        setStatusMsg({
            type: 'success',
            text: 'Applied auto-detected field suggestions. Review the dropdowns below before saving.',
        });
    };

    const hydrateLocalStateFromTenant = (t: Partial<PortalTenantSchema>) => {
        if (t.fee_sync_mode) setFeeSyncMode(t.fee_sync_mode);
        if (t.catalog_config) {
            setCatalogMap({
                iblock_id: t.catalog_config.iblock_id ? String(t.catalog_config.iblock_id) : '',
                catalog_name: t.catalog_config.catalog_name ?? 'CRM Catalog',
                cost_type_prop_code: t.catalog_config.cost_type_prop_code ?? '',
                option_prop_code: t.catalog_config.option_prop_code ?? '',
                payments_prop_code: t.catalog_config.payments_prop_code ?? '',
            });
        }
        if (t.spa_buckets) {
            const nextB: typeof spaBucketsMap = {};
            for (const b of t.spa_buckets) {
                nextB[b.bucket_key] = {
                    bucket_key: b.bucket_key,
                    title: b.title,
                    cost_type_key: b.cost_type_key,
                    option_key: b.option_key,
                    spa_id: b.spa_id ?? null,
                    spa_entity_type_id: b.spa_entity_type_id ? String(b.spa_entity_type_id) : '',
                    field_map: b.field_map_json ?? b.field_map ?? {},
                };
            }
            setSpaBucketsMap(nextB);
        }
    };

    const handleAutoProvision = async () => {
        if (targetEntityIds.length === 0) {
            setStatusMsg({ type: 'error', text: 'Select at least one CRM entity (Lead, Deal, Quote, or SPA).' });
            return;
        }
        setBusy('provision');
        setStatusMsg(null);
        try {
            const res = await bitrixFetch<{
                success: boolean;
                result: { log?: string[]; warnings?: string[]; fee_sync_mode?: string };
                tenant: PortalTenantSchema;
            }>('/api/bitrix/portal/provision-auto', jwt, {
                fee_sync_mode: feeSyncMode,
                entity_type_ids: targetEntityIds,
                options_config: optionsConfig,
                cost_types_config: costTypesConfig,
                option_meta_schema: optionMetaSchema,
                extra_attributes: extraAttributes,
            });
            hydrateLocalStateFromTenant(res.tenant);
            setStatusMsg({
                type: 'success',
                text: 'Auto-provisioning finished cleanly and Bitrix24 tabs & webhooks are active.',
                logs: res.result.log,
                warnings: res.result.warnings,
            });
            onUpdated?.(res.tenant, res.result);
        } catch (err) {
            const e = err as Error & { payload?: { is_spa_restricted?: boolean } };
            setStatusMsg({
                type: 'error',
                text: e.message,
                spaRestricted: Boolean(e.payload?.is_spa_restricted),
            });
        } finally {
            setBusy(null);
        }
    };

    const handleSaveCustomMappings = async () => {
        if (targetEntityIds.length === 0) {
            setStatusMsg({ type: 'error', text: 'Select at least one CRM entity to bind.' });
            return;
        }
        setBusy('save');
        setStatusMsg(null);

        const entitiesPayload = targetEntityIds.map((etId) => {
            const knownSpa = snapshot?.spas?.find((s) => s.entityTypeId === etId);
            const title = etId === 1 ? 'Leads' : etId === 2 ? 'Deals' : etId === 7 ? 'Quotes' : knownSpa?.title || `CRM #${etId}`;
            const rawMaps = entityFieldValues[etId] ?? {};
            const mappings: FieldMappingItem[] = [];
            for (const [compound, code] of Object.entries(rawMaps)) {
                if (!code || !code.trim()) continue;
                const [role, qualifier] = compound.split('::');
                mappings.push({
                    role,
                    role_qualifier: qualifier || 'default',
                    b24_field_code: code.trim(),
                });
            }
            return {
                entity_type_id: etId,
                title,
                is_enabled: true,
                auto_sync_enabled: true,
                mappings,
            };
        });

        const spaBucketsPayload = expectedBuckets.map((eb) => {
            const existing = spaBucketsMap[eb.bucket_key];
            return {
                bucket_key: eb.bucket_key,
                title: existing?.title || eb.title,
                cost_type_key: eb.cost_type_key,
                option_key: eb.option_key,
                spa_id: existing?.spa_id ?? null,
                spa_entity_type_id: existing?.spa_entity_type_id ? Number(existing.spa_entity_type_id) : null,
                is_active: feeSyncMode !== 'subtotals_only',
                field_map: existing?.field_map ?? {
                    title: 'title',
                    xml_id: 'xmlId',
                    price: 'opportunity',
                },
            };
        });

        const enumPayload: EnumMappingData[] = [];
        for (const [compound, val] of Object.entries(enumCrosswalk)) {
            const [group, logicalKey] = compound.split('::');
            if (val.catalog_enum_id) {
                enumPayload.push({
                    mapping_group: group,
                    attr_key: group,
                    logical_key: logicalKey,
                    logical_label: val.logical_label || logicalKey,
                    catalog_prop_code:
                        group === 'cost_type'
                            ? catalogMap.cost_type_prop_code
                            : group === 'option_group'
                              ? catalogMap.option_prop_code
                              : catalogMap.payments_prop_code,
                    catalog_enum_id: val.catalog_enum_id,
                    spa_entity_type_id: 0,
                });
            }
            if (val.spa_enum_id) {
                for (const b of spaBucketsPayload) {
                    if (b.spa_entity_type_id) {
                        enumPayload.push({
                            mapping_group: group,
                            attr_key: group,
                            logical_key: logicalKey,
                            logical_label: val.logical_label || logicalKey,
                            spa_entity_type_id: b.spa_entity_type_id,
                            spa_field_code: b.field_map?.[group] ?? null,
                            spa_enum_id: val.spa_enum_id,
                        });
                    }
                }
            }
        }

        try {
            const res = await bitrixFetch<{
                success: boolean;
                result: { warnings?: string[] };
                tenant: PortalTenantSchema;
            }>('/api/bitrix/portal/save-mappings', jwt, {
                fee_sync_mode: feeSyncMode,
                catalog: {
                    iblock_id: catalogMap.iblock_id ? Number(catalogMap.iblock_id) : null,
                    catalog_name: catalogMap.catalog_name,
                    cost_type_prop_code: catalogMap.cost_type_prop_code || null,
                    option_prop_code: catalogMap.option_prop_code || null,
                    payments_prop_code: catalogMap.payments_prop_code || null,
                },
                options_config: optionsConfig,
                cost_types_config: costTypesConfig,
                option_meta_schema: optionMetaSchema,
                extra_attributes: extraAttributes,
                spa_buckets: spaBucketsPayload,
                entities: entitiesPayload,
                enum_mappings: enumPayload,
            });

            hydrateLocalStateFromTenant(res.tenant);
            setStatusMsg({
                type: 'success',
                text: 'Custom field & enum mappings saved and portal placement/webhook bindings updated.',
                warnings: res.result.warnings,
            });
            onUpdated?.(res.tenant, res.result);
        } catch (err) {
            setStatusMsg({ type: 'error', text: (err as Error).message });
        } finally {
            setBusy(null);
        }
    };

    const allLogicalEnums = useMemo(() => {
        return [
            ...enabledCostTypes.map((c) => ({ group: 'cost_type', key: c.key, label: c.label })),
            ...enabledOptions.map((o) => ({ group: 'option_group', key: o.key, label: o.label })),
            ...DEFAULT_PAYMENTS.map((p) => ({ group: 'payment_schedule', key: p.key, label: p.label })),
        ];
    }, [enabledCostTypes, enabledOptions]);

    return (
        <div className="space-y-4">
            {/* Path Selector */}
            <div className="bg-card flex flex-wrap items-center justify-between gap-2 rounded-lg border p-2">
                <div className="flex flex-wrap gap-2">
                    <Button type="button" variant={tab === 'auto' ? 'default' : 'ghost'} size="sm" onClick={() => setTab('auto')}>
                        <Wand2 className="mr-1.5 h-4 w-4" />
                        Path A: 1-Click Auto-Provision
                    </Button>
                    <Button type="button" variant={tab === 'custom' ? 'default' : 'ghost'} size="sm" onClick={() => setTab('custom')}>
                        <Database className="mr-1.5 h-4 w-4" />
                        Path B: Scan & Map Existing Fields
                    </Button>
                </div>
                <Badge variant={tenant.schema_status === 'mapped' || tenant.schema_status === 'provisioned' ? 'default' : 'secondary'}>
                    Schema: {tenant.schema_status}
                </Badge>
            </div>

            {statusMsg && (
                <Alert variant={statusMsg.type === 'error' ? 'destructive' : 'default'}>
                    {statusMsg.type === 'error' ? <AlertCircle className="h-4 w-4" /> : <CheckCircle2 className="h-4 w-4 text-emerald-600" />}
                    <AlertTitle>{statusMsg.type === 'error' ? 'Action Failed' : 'Completed'}</AlertTitle>
                    <AlertDescription className="space-y-2">
                        <p>{statusMsg.text}</p>
                        {statusMsg.spaRestricted && (
                            <Button type="button" size="sm" variant="outline" onClick={() => setFeeSyncMode('subtotals_only')}>
                                Switch Sync Mode to &ldquo;Subtotals Only&rdquo;
                            </Button>
                        )}
                        {statusMsg.warnings && statusMsg.warnings.length > 0 && (
                            <ul className="list-disc pl-5 text-xs text-amber-700 dark:text-amber-400">
                                {statusMsg.warnings.map((w, i) => (
                                    <li key={i}>{w}</li>
                                ))}
                            </ul>
                        )}
                        {statusMsg.logs && statusMsg.logs.length > 0 && (
                            <ul className="text-muted-foreground max-h-36 list-disc overflow-y-auto pl-5 text-xs">
                                {statusMsg.logs.map((l, i) => (
                                    <li key={i}>{l}</li>
                                ))}
                            </ul>
                        )}
                    </AlertDescription>
                </Alert>
            )}

            {/* Shared Matrix & Sync Mode Settings */}
            <Card>
                <CardHeader className="pb-3">
                    <CardTitle className="text-base">1. Matrix Architecture & Target CRM Entities</CardTitle>
                    <CardDescription>Customize how fee options, cost types, extra catalog attributes, and Bitrix24 entities sync.</CardDescription>
                </CardHeader>
                <CardContent className="space-y-5">
                    <div className="grid gap-4 md:grid-cols-2">
                        <div className="space-y-1.5">
                            <Label>SPA Sync Topology</Label>
                            <select
                                value={feeSyncMode}
                                onChange={(e) => setFeeSyncMode(e.target.value)}
                                className="border-input bg-background h-9 w-full rounded-md border px-3 text-sm"
                            >
                                <option value="dedicated_spas">Dedicated SPAs per Bucket (e.g., Prof Option 1, Gov Option 1...)</option>
                                <option value="consolidated_spas">Consolidated Single SPA (one Fee Items SPA filtered by enums)</option>
                                <option value="subtotals_only">Subtotals Only (Entity Money Fields only — no Smart Processes)</option>
                            </select>
                        </div>

                        <div className="space-y-1.5">
                            <Label>Target CRM Entities</Label>
                            <div className="flex flex-wrap items-center gap-3 pt-1">
                                {[
                                    { id: 1, label: 'Leads' },
                                    { id: 2, label: 'Deals' },
                                    { id: 7, label: 'Quotes' },
                                ].map((ent) => (
                                    <label key={ent.id} className="flex items-center gap-1.5 text-sm">
                                        <Checkbox checked={targetEntityIds.includes(ent.id)} onCheckedChange={() => toggleEntityTarget(ent.id)} />
                                        {ent.label}
                                    </label>
                                ))}
                                {features.allow_custom_spas &&
                                    snapshot?.spas?.map((s) => (
                                        <label key={s.entityTypeId} className="flex items-center gap-1.5 text-sm">
                                            <Checkbox
                                                checked={targetEntityIds.includes(s.entityTypeId)}
                                                onCheckedChange={() => toggleEntityTarget(s.entityTypeId)}
                                            />
                                            {s.title} ({s.entityTypeId})
                                        </label>
                                    ))}
                            </div>
                            {features.allow_custom_spas && (
                                <div className="flex items-center gap-2 pt-1">
                                    <Input
                                        placeholder="Custom SPA entityTypeId (e.g. 128)"
                                        value={customEntityInput}
                                        onChange={(e) => setCustomEntityInput(e.target.value)}
                                        className="h-8 max-w-[230px] text-xs"
                                    />
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="outline"
                                        onClick={() => {
                                            const n = parseInt(customEntityInput, 10);
                                            if (n > 0 && !targetEntityIds.includes(n)) {
                                                setTargetEntityIds((prev) => [...prev, n]);
                                            }
                                            setCustomEntityInput('');
                                        }}
                                    >
                                        Add SPA ID
                                    </Button>
                                </div>
                            )}
                        </div>
                    </div>

                    <div className="grid gap-4 md:grid-cols-2">
                        {/* Quote Options Config */}
                        <div className="space-y-2 rounded-md border p-3">
                            <div className="flex items-center justify-between">
                                <Label className="text-xs font-semibold uppercase">Quote Options (Plan max: {maxOptions})</Label>
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    onClick={addOptionSlot}
                                    disabled={optionsConfig.length >= maxOptions}
                                    className="h-7 text-xs"
                                >
                                    <Plus className="mr-1 h-3 w-3" />
                                    Add Option
                                </Button>
                            </div>
                            <div className="space-y-2">
                                {optionsConfig.map((opt, idx) => (
                                    <div key={idx} className="flex items-center gap-2">
                                        <Input
                                            value={opt.label}
                                            onChange={(e) =>
                                                setOptionsConfig((prev) => prev.map((o, i) => (i === idx ? { ...o, label: e.target.value } : o)))
                                            }
                                            className="h-8 text-xs"
                                        />
                                        <Badge variant="outline" className="font-mono text-[10px]">
                                            {opt.key}
                                        </Badge>
                                        <Checkbox
                                            checked={opt.is_enabled !== false}
                                            onCheckedChange={(chk) =>
                                                setOptionsConfig((prev) => prev.map((o, i) => (i === idx ? { ...o, is_enabled: Boolean(chk) } : o)))
                                            }
                                        />
                                    </div>
                                ))}
                            </div>
                        </div>

                        {/* Cost Types Config */}
                        <div className="space-y-2 rounded-md border p-3">
                            <div className="flex items-center justify-between">
                                <Label className="text-xs font-semibold uppercase">Cost Type Categories</Label>
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    className="h-7 text-xs"
                                    onClick={() => {
                                        const idx = costTypesConfig.length + 1;
                                        setCostTypesConfig((prev) => [
                                            ...prev,
                                            {
                                                key: `cost_${idx}`,
                                                label: `Cost Type ${idx}`,
                                                sort: idx * 100,
                                                is_enabled: true,
                                            },
                                        ]);
                                    }}
                                >
                                    <Plus className="mr-1 h-3 w-3" />
                                    Add Type
                                </Button>
                            </div>
                            <div className="space-y-2">
                                {costTypesConfig.map((ct, idx) => (
                                    <div key={idx} className="flex items-center gap-2">
                                        <Input
                                            value={ct.label}
                                            onChange={(e) =>
                                                setCostTypesConfig((prev) => prev.map((c, i) => (i === idx ? { ...c, label: e.target.value } : c)))
                                            }
                                            className="h-8 text-xs"
                                        />
                                        <Badge variant="outline" className="font-mono text-[10px]">
                                            {ct.key}
                                        </Badge>
                                        <Checkbox
                                            checked={ct.is_enabled !== false}
                                            onCheckedChange={(chk) =>
                                                setCostTypesConfig((prev) => prev.map((c, i) => (i === idx ? { ...c, is_enabled: Boolean(chk) } : c)))
                                            }
                                        />
                                    </div>
                                ))}
                            </div>
                        </div>
                    </div>

                    {/* Extra Catalog Product Attributes */}
                    <div className="space-y-2 rounded-md border p-3">
                        <div className="flex items-center justify-between">
                            <div>
                                <Label className="text-xs font-semibold uppercase">Custom Product Attributes (Optional)</Label>
                                <p className="text-muted-foreground text-xs">
                                    Additional Commercial Catalog properties to edit in the Fee Grid and mirror to SPAs.
                                </p>
                            </div>
                            <Button type="button" size="sm" variant="outline" className="h-7 text-xs" onClick={addExtraAttr}>
                                <Plus className="mr-1 h-3 w-3" />
                                Add Attribute
                            </Button>
                        </div>
                        {extraAttributes.map((attr, idx) => (
                            <div key={idx} className="grid grid-cols-12 items-center gap-2">
                                <Input
                                    placeholder="key (e.g. authority)"
                                    value={attr.key}
                                    onChange={(e) =>
                                        setExtraAttributes((prev) => prev.map((a, i) => (i === idx ? { ...a, key: e.target.value } : a)))
                                    }
                                    className="col-span-3 h-8 font-mono text-xs"
                                />
                                <Input
                                    placeholder="Display Label"
                                    value={attr.label}
                                    onChange={(e) =>
                                        setExtraAttributes((prev) => prev.map((a, i) => (i === idx ? { ...a, label: e.target.value } : a)))
                                    }
                                    className="col-span-4 h-8 text-xs"
                                />
                                <Input
                                    placeholder="propertyXXX (if existing)"
                                    value={attr.prop_code ?? ''}
                                    onChange={(e) =>
                                        setExtraAttributes((prev) => prev.map((a, i) => (i === idx ? { ...a, prop_code: e.target.value } : a)))
                                    }
                                    className="col-span-3 h-8 font-mono text-xs"
                                />
                                <Button
                                    type="button"
                                    size="icon"
                                    variant="ghost"
                                    className="text-destructive col-span-2 h-8"
                                    onClick={() => setExtraAttributes((prev) => prev.filter((_, i) => i !== idx))}
                                >
                                    <Trash2 className="h-3.5 w-3.5" />
                                </Button>
                            </div>
                        ))}
                    </div>

                    {/* Option Details Metadata Fields */}
                    <div className="space-y-2 rounded-md border p-3">
                        <div className="flex items-center justify-between">
                            <div>
                                <Label className="text-xs font-semibold uppercase">Option Details Metadata Fields</Label>
                                <p className="text-muted-foreground text-xs">
                                    Per-option fields synced directly to your CRM entity (e.g., Scope of Work, Notes, Completion Time).
                                </p>
                            </div>
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                className="h-7 text-xs"
                                onClick={() => {
                                    const n = optionMetaSchema.length + 1;
                                    setOptionMetaSchema((prev) => [...prev, { key: `meta_${n}`, label: `Detail Field ${n}`, type: 'text' }]);
                                }}
                            >
                                <Plus className="mr-1 h-3 w-3" />
                                Add Detail Field
                            </Button>
                        </div>
                        {optionMetaSchema.map((mf, idx) => (
                            <div key={idx} className="grid grid-cols-12 items-center gap-2">
                                <Input
                                    placeholder="key (e.g. scope_of_work)"
                                    value={mf.key}
                                    onChange={(e) =>
                                        setOptionMetaSchema((prev) => prev.map((m, i) => (i === idx ? { ...m, key: e.target.value } : m)))
                                    }
                                    className="col-span-4 h-8 font-mono text-xs"
                                />
                                <Input
                                    placeholder="Display Label"
                                    value={mf.label}
                                    onChange={(e) =>
                                        setOptionMetaSchema((prev) => prev.map((m, i) => (i === idx ? { ...m, label: e.target.value } : m)))
                                    }
                                    className="col-span-4 h-8 text-xs"
                                />
                                <select
                                    value={mf.type}
                                    onChange={(e) =>
                                        setOptionMetaSchema((prev) => prev.map((m, i) => (i === idx ? { ...m, type: e.target.value } : m)))
                                    }
                                    className="border-input bg-background col-span-3 h-8 rounded-md border px-2 text-xs"
                                >
                                    <option value="text">Single Line</option>
                                    <option value="textarea">Multi-line</option>
                                    <option value="date">Date</option>
                                </select>
                                <Button
                                    type="button"
                                    size="icon"
                                    variant="ghost"
                                    className="text-destructive col-span-1 h-8"
                                    onClick={() => setOptionMetaSchema((prev) => prev.filter((_, i) => i !== idx))}
                                >
                                    <Trash2 className="h-3.5 w-3.5" />
                                </Button>
                            </div>
                        ))}
                    </div>
                </CardContent>
            </Card>

            {/* Path A Execution */}
            {tab === 'auto' && (
                <Card>
                    <CardHeader className="pb-3">
                        <CardTitle className="flex items-center gap-2 text-base">
                            <Sparkles className="h-4 w-4 text-indigo-600" />
                            2. Automatic Portal Provisioning
                        </CardTitle>
                        <CardDescription>
                            Idempotently creates missing Commercial Catalog properties, Smart Processes (SPAs), custom entity money/link fields, tab
                            placements, and background webhooks in your Bitrix24 portal.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="flex flex-wrap items-center justify-between gap-3">
                        <div className="text-muted-foreground text-xs">
                            Existing fields matching our XML_ID conventions are reused safely without creating duplicates.
                        </div>
                        <Button type="button" onClick={handleAutoProvision} disabled={busy !== null}>
                            {busy === 'provision' ? <Loader2 className="mr-1.5 h-4 w-4 animate-spin" /> : <Wand2 className="mr-1.5 h-4 w-4" />}
                            Run 1-Click Auto-Provisioning
                        </Button>
                    </CardContent>
                </Card>
            )}

            {/* Path B: Custom Mapping Editor */}
            {tab === 'custom' && (
                <div className="space-y-4">
                    <Card>
                        <CardHeader className="pb-3">
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <div>
                                    <CardTitle className="text-base">2. Portal Introspection & Auto-Suggestion</CardTitle>
                                    <CardDescription>
                                        Scan your live Bitrix24 portal to fetch real catalog propertyN codes, UF_CRM_* fields, and enum IDs.
                                    </CardDescription>
                                </div>
                                <div className="flex items-center gap-2">
                                    <Button type="button" variant="outline" size="sm" onClick={handleScanSchema} disabled={busy !== null}>
                                        {busy === 'scan' ? (
                                            <Loader2 className="mr-1.5 h-3.5 w-3.5 animate-spin" />
                                        ) : (
                                            <RefreshCw className="mr-1.5 h-3.5 w-3.5" />
                                        )}
                                        Scan Portal Schema
                                    </Button>
                                    {snapshot?.suggestions && (
                                        <Button type="button" variant="secondary" size="sm" onClick={applySuggestions}>
                                            <Sparkles className="mr-1.5 h-3.5 w-3.5" />
                                            Apply Suggestions
                                        </Button>
                                    )}
                                </div>
                            </div>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            {/* Catalog Properties */}
                            <div className="grid gap-3 md:grid-cols-4">
                                <div className="space-y-1">
                                    <Label className="text-xs">Catalog Iblock ID</Label>
                                    <Input
                                        value={catalogMap.iblock_id}
                                        onChange={(e) => setCatalogMap((p) => ({ ...p, iblock_id: e.target.value }))}
                                        placeholder="e.g. 24"
                                        className="h-8 font-mono text-xs"
                                    />
                                </div>
                                {[
                                    { key: 'cost_type_prop_code', label: 'Cost Type Property' },
                                    { key: 'option_prop_code', label: 'Option Property' },
                                    { key: 'payments_prop_code', label: 'Payments Property' },
                                ].map((f) => {
                                    const currentVal = catalogMap[f.key as keyof typeof catalogMap];
                                    return (
                                        <div key={f.key} className="space-y-1">
                                            <Label className="text-xs">{f.label}</Label>
                                            {snapshot?.catalog?.properties.length ? (
                                                <select
                                                    value={currentVal}
                                                    onChange={(e) => setCatalogMap((p) => ({ ...p, [f.key]: e.target.value }))}
                                                    className="border-input bg-background h-8 w-full rounded-md border px-2 text-xs"
                                                >
                                                    <option value="">— Select Property —</option>
                                                    {snapshot.catalog.properties.map((prop) => (
                                                        <option key={prop.prop_code} value={prop.prop_code}>
                                                            {prop.name} ({prop.prop_code})
                                                        </option>
                                                    ))}
                                                </select>
                                            ) : (
                                                <Input
                                                    value={currentVal}
                                                    onChange={(e) => setCatalogMap((p) => ({ ...p, [f.key]: e.target.value }))}
                                                    placeholder="property123"
                                                    className="h-8 font-mono text-xs"
                                                />
                                            )}
                                        </div>
                                    );
                                })}
                            </div>

                            {/* SPA Buckets Mapping */}
                            {expectedBuckets.length > 0 && (
                                <div className="space-y-2 border-t pt-4">
                                    <Label className="flex items-center gap-1.5 text-xs font-semibold uppercase">
                                        <Layers className="h-3.5 w-3.5" />
                                        Smart Process (SPA) Buckets
                                    </Label>
                                    <div className="grid gap-3 md:grid-cols-2">
                                        {expectedBuckets.map((eb) => {
                                            const val = spaBucketsMap[eb.bucket_key];
                                            return (
                                                <div key={eb.bucket_key} className="space-y-2 rounded-md border p-3 text-xs">
                                                    <div className="flex items-center justify-between">
                                                        <span className="font-semibold">{eb.title}</span>
                                                        <Badge variant="outline" className="font-mono text-[10px]">
                                                            {eb.bucket_key}
                                                        </Badge>
                                                    </div>
                                                    {snapshot?.spas?.length ? (
                                                        <select
                                                            value={val?.spa_entity_type_id ?? ''}
                                                            onChange={(e) => {
                                                                const spa = snapshot.spas?.find((s) => String(s.entityTypeId) === e.target.value);
                                                                setSpaBucketsMap((prev) => ({
                                                                    ...prev,
                                                                    [eb.bucket_key]: {
                                                                        bucket_key: eb.bucket_key,
                                                                        title: spa?.title ?? eb.title,
                                                                        cost_type_key: eb.cost_type_key,
                                                                        option_key: eb.option_key,
                                                                        spa_id: spa?.id ?? null,
                                                                        spa_entity_type_id: e.target.value,
                                                                        field_map: prev[eb.bucket_key]?.field_map ?? {
                                                                            title: 'title',
                                                                            xml_id: 'xmlId',
                                                                            price: 'opportunity',
                                                                        },
                                                                    },
                                                                }));
                                                            }}
                                                            className="border-input bg-background h-8 w-full rounded-md border px-2 text-xs"
                                                        >
                                                            <option value="">— Select Portal SPA —</option>
                                                            {snapshot.spas.map((s) => (
                                                                <option key={s.entityTypeId} value={String(s.entityTypeId)}>
                                                                    {s.title} (entityTypeId={s.entityTypeId})
                                                                </option>
                                                            ))}
                                                        </select>
                                                    ) : (
                                                        <Input
                                                            placeholder="SPA entityTypeId (e.g. 1036)"
                                                            value={val?.spa_entity_type_id ?? ''}
                                                            onChange={(e) =>
                                                                setSpaBucketsMap((prev) => ({
                                                                    ...prev,
                                                                    [eb.bucket_key]: {
                                                                        bucket_key: eb.bucket_key,
                                                                        title: eb.title,
                                                                        cost_type_key: eb.cost_type_key,
                                                                        option_key: eb.option_key,
                                                                        spa_id: prev[eb.bucket_key]?.spa_id ?? null,
                                                                        spa_entity_type_id: e.target.value,
                                                                        field_map: prev[eb.bucket_key]?.field_map ?? {},
                                                                    },
                                                                }))
                                                            }
                                                            className="h-8 font-mono text-xs"
                                                        />
                                                    )}
                                                </div>
                                            );
                                        })}
                                    </div>
                                </div>
                            )}

                            {/* Entity Custom Fields Mapping */}
                            <div className="space-y-3 border-t pt-4">
                                <Label className="text-xs font-semibold uppercase">CRM Entity Custom Fields (`UF_CRM_*` / `ufCrm*`)</Label>
                                {targetEntityIds.map((etId) => {
                                    const availableFields = snapshot?.entity_fields?.[String(etId)] ?? [];
                                    const current = entityFieldValues[etId] ?? {};
                                    const setMap = (compound: string, val: string) =>
                                        setEntityFieldValues((prev) => ({
                                            ...prev,
                                            [etId]: { ...(prev[etId] ?? {}), [compound]: val },
                                        }));

                                    const slots: Array<{ compound: string; label: string }> = [
                                        { compound: 'sync_status::default', label: 'Sync Status Field' },
                                        ...expectedBuckets.map((b) => ({
                                            compound: `spa_link::${b.bucket_key}`,
                                            label: `SPA Link (${b.title})`,
                                        })),
                                        ...enabledOptions.map((o) => ({
                                            compound: `option_total::${o.key}`,
                                            label: `Option Total (${o.label})`,
                                        })),
                                        ...enabledOptions.flatMap((o) =>
                                            enabledCostTypes.map((c) => ({
                                                compound: `subtotal::${makeBucketKey(c.key, o.key)}`,
                                                label: `Subtotal (${c.label} × ${o.label})`,
                                            })),
                                        ),
                                        ...enabledOptions.flatMap((o) =>
                                            optionMetaSchema.map((m) => ({
                                                compound: `option_meta::${o.key}.${m.key}`,
                                                label: `${o.label} — ${m.label}`,
                                            })),
                                        ),
                                    ];

                                    return (
                                        <div key={etId} className="space-y-2 rounded-md border p-3">
                                            <div className="text-xs font-semibold">
                                                Entity Type #{etId} {etId === 1 ? '(Lead)' : etId === 2 ? '(Deal)' : etId === 7 ? '(Quote)' : ''}
                                            </div>
                                            <div className="grid gap-2 md:grid-cols-3">
                                                {slots.map((slot) => (
                                                    <div key={slot.compound} className="space-y-1">
                                                        <Label className="text-muted-foreground text-[11px]">{slot.label}</Label>
                                                        {availableFields.length > 0 ? (
                                                            <select
                                                                value={current[slot.compound] ?? ''}
                                                                onChange={(e) => setMap(slot.compound, e.target.value)}
                                                                className="border-input bg-background h-8 w-full rounded-md border px-2 text-xs"
                                                            >
                                                                <option value="">— Unmapped —</option>
                                                                {availableFields.map((af) => (
                                                                    <option key={af.code} value={af.code}>
                                                                        {af.label} ({af.code})
                                                                    </option>
                                                                ))}
                                                            </select>
                                                        ) : (
                                                            <Input
                                                                value={current[slot.compound] ?? ''}
                                                                onChange={(e) => setMap(slot.compound, e.target.value)}
                                                                placeholder="UF_CRM_..."
                                                                className="h-8 font-mono text-xs"
                                                            />
                                                        )}
                                                    </div>
                                                ))}
                                            </div>
                                        </div>
                                    );
                                })}
                            </div>

                            {/* Enum Crosswalk */}
                            <div className="space-y-2 border-t pt-4">
                                <Label className="text-xs font-semibold uppercase">Enum Value Crosswalk (Portal-Specific Enum IDs)</Label>
                                <div className="overflow-x-auto rounded-md border">
                                    <table className="w-full text-xs">
                                        <thead className="bg-muted/50 text-left">
                                            <tr>
                                                <th className="px-3 py-2">Group</th>
                                                <th className="px-3 py-2">Logical Key / Label</th>
                                                <th className="px-3 py-2">Catalog Enum ID</th>
                                                <th className="px-3 py-2">SPA Enum ID</th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y">
                                            {allLogicalEnums.map((item) => {
                                                const compound = `${item.group}::${item.key}`;
                                                const cur = enumCrosswalk[compound] ?? {
                                                    catalog_enum_id: '',
                                                    spa_enum_id: '',
                                                    logical_label: item.label,
                                                };
                                                const catPropCode =
                                                    item.group === 'cost_type'
                                                        ? catalogMap.cost_type_prop_code
                                                        : item.group === 'option_group'
                                                          ? catalogMap.option_prop_code
                                                          : catalogMap.payments_prop_code;
                                                const catEnums = snapshot?.catalog?.properties.find((p) => p.prop_code === catPropCode)?.enums ?? [];

                                                return (
                                                    <tr key={compound}>
                                                        <td className="text-muted-foreground px-3 py-1.5 font-mono">{item.group}</td>
                                                        <td className="px-3 py-1.5 font-medium">{item.label}</td>
                                                        <td className="px-3 py-1.5">
                                                            {catEnums.length > 0 ? (
                                                                <select
                                                                    value={cur.catalog_enum_id}
                                                                    onChange={(e) =>
                                                                        setEnumCrosswalk((prev) => ({
                                                                            ...prev,
                                                                            [compound]: {
                                                                                ...cur,
                                                                                catalog_enum_id: e.target.value,
                                                                            },
                                                                        }))
                                                                    }
                                                                    className="border-input bg-background h-7 w-full rounded border px-2 text-xs"
                                                                >
                                                                    <option value="">—</option>
                                                                    {catEnums.map((ce) => (
                                                                        <option key={ce.id} value={ce.id}>
                                                                            {ce.value} (#{ce.id})
                                                                        </option>
                                                                    ))}
                                                                </select>
                                                            ) : (
                                                                <Input
                                                                    value={cur.catalog_enum_id}
                                                                    onChange={(e) =>
                                                                        setEnumCrosswalk((prev) => ({
                                                                            ...prev,
                                                                            [compound]: {
                                                                                ...cur,
                                                                                catalog_enum_id: e.target.value,
                                                                            },
                                                                        }))
                                                                    }
                                                                    placeholder="Enum ID"
                                                                    className="h-7 font-mono text-xs"
                                                                />
                                                            )}
                                                        </td>
                                                        <td className="px-3 py-1.5">
                                                            <Input
                                                                value={cur.spa_enum_id}
                                                                onChange={(e) =>
                                                                    setEnumCrosswalk((prev) => ({
                                                                        ...prev,
                                                                        [compound]: { ...cur, spa_enum_id: e.target.value },
                                                                    }))
                                                                }
                                                                placeholder="SPA Enum ID"
                                                                className="h-7 font-mono text-xs"
                                                            />
                                                        </td>
                                                    </tr>
                                                );
                                            })}
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                            <div className="flex justify-end pt-2">
                                <Button type="button" onClick={handleSaveCustomMappings} disabled={busy !== null}>
                                    {busy === 'save' ? <Loader2 className="mr-1.5 h-4 w-4 animate-spin" /> : <Save className="mr-1.5 h-4 w-4" />}
                                    Save Custom Mappings & Bind Tabs
                                </Button>
                            </div>
                        </CardContent>
                    </Card>
                </div>
            )}
        </div>
    );
}
