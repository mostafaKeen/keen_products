import { Head } from '@inertiajs/react';
import {
    AlertCircle,
    ArrowDown,
    ArrowUp,
    CheckCircle2,
    Database,
    Edit3,
    Layers,
    Loader2,
    Mail,
    PackagePlus,
    Plus,
    RefreshCw,
    Save,
    Search,
    ShieldAlert,
    Trash2,
} from 'lucide-react';
import { useCallback, useEffect, useMemo, useState } from 'react';

import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { bitrixFetch, loadBx24Script } from '@/lib/bitrix-api';

interface ChoiceItem {
    logical_key: string;
    label: string;
    catalog_enum_id?: string | null;
}

interface MetaFieldItem {
    key: string;
    label: string;
    type: string;
}

interface ExtraAttrDef {
    key: string;
    label: string;
    prop_code?: string;
    property_type?: string;
    enum_options?: Array<{ key: string; label: string; id?: string }>;
}

interface CurrencyItem {
    code: string;
    name: string;
    base: boolean;
    format: string;
}

interface WidgetRow {
    rowId: number | null;
    productId: number | null;
    productName: string;
    price: number;
    quantity: number;
    taxRate: number;
    taxIncluded: boolean;
    sort: number;
    costTypeKey: string;
    optionKey: string;
    paymentKey: string;
    extraAttributes: Record<string, string>;
}

interface CatalogProduct {
    id: number;
    name: string;
    price: number;
    currency: string;
    price_id?: number | null;
    vatIncluded?: boolean;
    costTypeKey: string | null;
    optionKey: string | null;
    paymentKey: string | null;
    extraAttributes: Record<string, string>;
}

interface WidgetContext {
    entity: {
        entity_type_id: number;
        entity_id: number;
        owner_type_code: string;
        title: string;
        currency_id: string;
        opportunity: number;
        is_configured: boolean;
    };
    currencies: CurrencyItem[];
    schema: {
        fee_sync_mode: string;
        options: ChoiceItem[];
        cost_types: ChoiceItem[];
        payments: ChoiceItem[];
        option_meta_schema: MetaFieldItem[];
        extra_attributes: ExtraAttrDef[];
        iblock_id: number | null;
    };
    rows: WidgetRow[];
    option_meta: Record<string, string>;
    last_synced_at: string | null;
    subscription: {
        allowed: boolean;
        reason: string | null;
        banner: string | null;
        status: string;
        plan_name: string | null;
        sync_count: number;
        max_monthly_syncs: number | null;
        max_options: number;
    };
}

interface Props {
    error: string | null;
    jwt: string | null;
    bx24_script_url?: string;
    placement?: string;
    can_manage_catalog?: boolean;
    context: WidgetContext | null;
}

function calcLine(row: WidgetRow): { net: number; tax: number; gross: number } {
    const base = (Number(row.price) || 0) * (Number(row.quantity) || 0);
    const rate = Number(row.taxRate) || 0;
    if (rate <= 0) {
        return { net: base, tax: 0, gross: base };
    }
    if (row.taxIncluded) {
        const net = base / (1 + rate / 100);
        return { net, tax: base - net, gross: base };
    }
    const tax = base * (rate / 100);
    return { net: base, tax, gross: base + tax };
}

function formatMoney(amount: number, currency: string): string {
    return `${new Intl.NumberFormat(undefined, {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(amount)} ${currency}`;
}

export default function BitrixWidget({ error: initialError, jwt, bx24_script_url, can_manage_catalog = false, context: initialContext }: Props) {
    const [context, setContext] = useState<WidgetContext | null>(initialContext);
    const [rows, setRows] = useState<WidgetRow[]>(() => initialContext?.rows ?? []);
    const [currencyId, setCurrencyId] = useState<string>(() => initialContext?.entity.currency_id ?? 'USD');
    const [optionMeta, setOptionMeta] = useState<Record<string, string>>(() => initialContext?.option_meta ?? {});

    const [busyAction, setBusyAction] = useState<'reload' | 'sync' | 'clear' | null>(null);
    const [feedback, setFeedback] = useState<{ type: 'success' | 'error'; text: string } | null>(
        initialError ? { type: 'error', text: initialError } : null,
    );

    // Clear confirmation dialog (avoids window.confirm inside sandboxed Bitrix iframe)
    const [clearOpen, setClearOpen] = useState(false);

    // Catalog Search modal state
    const [catalogOpen, setCatalogOpen] = useState(false);
    const [catalogQuery, setCatalogQuery] = useState('');
    const [catalogLoading, setCatalogLoading] = useState(false);
    const [catalogProducts, setCatalogProducts] = useState<CatalogProduct[]>([]);

    // Create / Edit Catalog Product modal state
    const [productModalOpen, setProductModalOpen] = useState(false);
    const [productSaving, setProductSaving] = useState(false);
    const [editingRowIndex, setEditingRowIndex] = useState<number | null>(null);
    const [productForm, setProductForm] = useState<{
        id: number | null;
        name: string;
        price: string;
        currency: string;
        costTypeKey: string;
        optionKey: string;
        paymentKey: string;
        extraAttributes: Record<string, string>;
    }>({
        id: null,
        name: '',
        price: '0',
        currency: initialContext?.entity.currency_id ?? 'USD',
        costTypeKey: initialContext?.schema.cost_types[0]?.logical_key ?? 'professional',
        optionKey: initialContext?.schema.options[0]?.logical_key ?? 'option_1',
        paymentKey: initialContext?.schema.payments[0]?.logical_key ?? '',
        extraAttributes: {},
    });

    const fitBitrixFrame = useCallback(() => {
        if (typeof window !== 'undefined' && window.BX24) {
            window.setTimeout(() => window.BX24?.fitWindow(), 80);
        }
    }, []);

    useEffect(() => {
        loadBx24Script(bx24_script_url).then((loaded) => {
            if (loaded && window.BX24) {
                window.BX24.init(() => {
                    window.BX24?.fitWindow();
                });
            }
        });
    }, [bx24_script_url]);

    useEffect(() => {
        fitBitrixFrame();
    }, [rows.length, context?.entity.entity_id, feedback, fitBitrixFrame]);

    const applyContext = useCallback((next: WidgetContext) => {
        setContext(next);
        setRows(next.rows ?? []);
        setCurrencyId(next.entity.currency_id || 'USD');
        setOptionMeta(next.option_meta ?? {});
    }, []);

    const summary = useMemo(() => {
        const optionList = context?.schema.options ?? [];
        const costList = context?.schema.cost_types ?? [];

        const optionTotals: Record<string, number> = {};
        const bucketTotals: Record<string, number> = {};
        let totalNet = 0;
        let totalTax = 0;
        let totalGross = 0;

        for (const opt of optionList) {
            optionTotals[opt.logical_key] = 0;
            for (const ct of costList) {
                bucketTotals[`${opt.logical_key}::${ct.logical_key}`] = 0;
            }
        }

        for (const r of rows) {
            const { net, tax, gross } = calcLine(r);
            const lineBase = (Number(r.price) || 0) * (Number(r.quantity) || 0);
            totalNet += net;
            totalTax += tax;
            totalGross += gross;
            optionTotals[r.optionKey] = (optionTotals[r.optionKey] ?? 0) + lineBase;
            const bKey = `${r.optionKey}::${r.costTypeKey}`;
            bucketTotals[bKey] = (bucketTotals[bKey] ?? 0) + lineBase;
        }

        return { optionTotals, bucketTotals, totalNet, totalTax, totalGross };
    }, [rows, context]);

    const handleReload = async () => {
        if (!jwt) return;
        setBusyAction('reload');
        setFeedback(null);
        try {
            const res = await bitrixFetch<{ success: boolean; context: WidgetContext }>('/api/bitrix/widget/load', jwt, {});
            applyContext(res.context);
            setFeedback({ type: 'success', text: 'Reloaded latest CRM product rows & schema.' });
        } catch (e) {
            setFeedback({ type: 'error', text: (e as Error).message });
        } finally {
            setBusyAction(null);
        }
    };

    const handleSync = async () => {
        if (!jwt) return;
        setBusyAction('sync');
        setFeedback(null);
        try {
            const payloadRows = rows.map((r, idx) => ({
                ...r,
                sort: (idx + 1) * 10,
                price: Number(r.price) || 0,
                quantity: Math.max(0.0001, Number(r.quantity) || 1),
                taxRate: Number(r.taxRate) || 0,
            }));

            const res = await bitrixFetch<{
                success: boolean;
                result: { actions: Array<{ action: string }>; errors: string[] };
                context: WidgetContext;
            }>('/api/bitrix/widget/sync', jwt, {
                currencyId,
                rows: payloadRows,
                optionMeta,
            });

            applyContext(res.context);
            if (res.result.errors && res.result.errors.length > 0) {
                setFeedback({ type: 'error', text: res.result.errors.join(' | ') });
            } else {
                setFeedback({
                    type: 'success',
                    text: `Saved & synced ${payloadRows.length} fee line(s) atomically to Bitrix24.`,
                });
            }
        } catch (e) {
            setFeedback({ type: 'error', text: (e as Error).message });
        } finally {
            setBusyAction(null);
        }
    };

    const handleClear = async () => {
        if (!jwt) return;
        setClearOpen(false);
        setBusyAction('clear');
        setFeedback(null);
        try {
            const res = await bitrixFetch<{
                success: boolean;
                result: { errors: string[] };
                context: WidgetContext;
            }>('/api/bitrix/widget/clear', jwt, {
                currencyId,
                optionMeta,
            });
            applyContext(res.context);
            setFeedback({
                type: 'success',
                text: 'Cleared fee rows, zeroed totals, and cleaned up linked SPA items.',
            });
        } catch (e) {
            setFeedback({ type: 'error', text: (e as Error).message });
        } finally {
            setBusyAction(null);
        }
    };

    const addEmptyRow = () => {
        const defaultCost = context?.schema.cost_types[0]?.logical_key ?? 'professional';
        const defaultOpt = context?.schema.options[0]?.logical_key ?? 'option_1';
        setRows((prev) => [
            ...prev,
            {
                rowId: null,
                productId: null,
                productName: '',
                price: 0,
                quantity: 1,
                taxRate: 0,
                taxIncluded: false,
                sort: (prev.length + 1) * 10,
                costTypeKey: defaultCost,
                optionKey: defaultOpt,
                paymentKey: '',
                extraAttributes: {},
            },
        ]);
    };

    const updateRow = (index: number, patch: Partial<WidgetRow>) => {
        setRows((prev) => prev.map((r, i) => (i === index ? { ...r, ...patch } : r)));
    };

    const moveRow = (index: number, delta: -1 | 1) => {
        const target = index + delta;
        if (target < 0 || target >= rows.length) return;
        setRows((prev) => {
            const copy = [...prev];
            const [item] = copy.splice(index, 1);
            copy.splice(target, 0, item);
            return copy.map((r, idx) => ({ ...r, sort: (idx + 1) * 10 }));
        });
    };

    const removeRow = (index: number) => {
        setRows((prev) => prev.filter((_, i) => i !== index));
    };

    const searchCatalog = async (q: string) => {
        if (!jwt) return;
        setCatalogLoading(true);
        try {
            const res = await bitrixFetch<{ success: boolean; products: CatalogProduct[] }>('/api/bitrix/widget/catalog-search', jwt, { q });
            setCatalogProducts(res.products ?? []);
        } catch (e) {
            setFeedback({ type: 'error', text: (e as Error).message });
        } finally {
            setCatalogLoading(false);
        }
    };

    const openCatalogModal = () => {
        setCatalogOpen(true);
        searchCatalog(catalogQuery);
    };

    const addCatalogProductAsRow = (prod: CatalogProduct) => {
        const defaultCost = context?.schema.cost_types[0]?.logical_key ?? 'professional';
        const defaultOpt = context?.schema.options[0]?.logical_key ?? 'option_1';
        setRows((prev) => [
            ...prev,
            {
                rowId: null,
                productId: prod.id,
                productName: prod.name,
                price: Number(prod.price) || 0,
                quantity: 1,
                taxRate: 0,
                taxIncluded: Boolean(prod.vatIncluded),
                sort: (prev.length + 1) * 10,
                costTypeKey: prod.costTypeKey || defaultCost,
                optionKey: prod.optionKey || defaultOpt,
                paymentKey: prod.paymentKey || '',
                extraAttributes: prod.extraAttributes ?? {},
            },
        ]);
        setCatalogOpen(false);
    };

    const openProductModal = (prod?: CatalogProduct, rowIdx: number | null = null) => {
        const defaultCost = context?.schema.cost_types[0]?.logical_key ?? 'professional';
        const defaultOpt = context?.schema.options[0]?.logical_key ?? 'option_1';
        setEditingRowIndex(rowIdx);

        if (prod) {
            setProductForm({
                id: prod.id,
                name: prod.name,
                price: String(prod.price ?? 0),
                currency: prod.currency || currencyId,
                costTypeKey: prod.costTypeKey || defaultCost,
                optionKey: prod.optionKey || defaultOpt,
                paymentKey: prod.paymentKey || '',
                extraAttributes: prod.extraAttributes ?? {},
            });
        } else if (rowIdx !== null && rows[rowIdx]) {
            const r = rows[rowIdx];
            setProductForm({
                id: r.productId,
                name: r.productName,
                price: String(r.price),
                currency: currencyId,
                costTypeKey: r.costTypeKey || defaultCost,
                optionKey: r.optionKey || defaultOpt,
                paymentKey: r.paymentKey || '',
                extraAttributes: r.extraAttributes ?? {},
            });
        } else {
            setProductForm({
                id: null,
                name: '',
                price: '0',
                currency: currencyId,
                costTypeKey: defaultCost,
                optionKey: defaultOpt,
                paymentKey: '',
                extraAttributes: {},
            });
        }
        setProductModalOpen(true);
    };

    const saveCatalogProduct = async () => {
        if (!jwt || !productForm.name.trim()) return;
        setProductSaving(true);
        try {
            const res = await bitrixFetch<{ success: boolean; product: CatalogProduct }>('/api/bitrix/widget/catalog-product', jwt, {
                id: productForm.id,
                name: productForm.name.trim(),
                price: Number(productForm.price) || 0,
                currency: productForm.currency || currencyId,
                costTypeKey: productForm.costTypeKey || null,
                optionKey: productForm.optionKey || null,
                paymentKey: productForm.paymentKey || null,
                extraAttributes: productForm.extraAttributes,
            });

            const saved = res.product;
            if (editingRowIndex !== null) {
                updateRow(editingRowIndex, {
                    productId: saved.id,
                    productName: saved.name,
                    price: saved.price,
                    costTypeKey: saved.costTypeKey || productForm.costTypeKey,
                    optionKey: saved.optionKey || productForm.optionKey,
                    paymentKey: saved.paymentKey || '',
                    extraAttributes: saved.extraAttributes ?? {},
                });
            } else {
                addCatalogProductAsRow(saved);
            }

            if (catalogOpen) {
                searchCatalog(catalogQuery);
            }
            setProductModalOpen(false);
            setFeedback({
                type: 'success',
                text: `Catalog product "${saved.name}" (#${saved.id}) saved cleanly.`,
            });
        } catch (e) {
            setFeedback({ type: 'error', text: (e as Error).message });
        } finally {
            setProductSaving(false);
        }
    };

    if (!context) {
        const isAccessDenied =
            (feedback?.text ?? '').toLowerCase().includes('access denied') ||
            (feedback?.text ?? '').toLowerCase().includes('not authorized') ||
            (feedback?.text ?? '').toLowerCase().includes('license');

        return (
            <div className="flex min-h-[480px] w-full items-center justify-center bg-slate-50/80 p-6 dark:bg-slate-950">
                <Head title="Access Restricted — Quoting Widget" />
                <Card className="mx-auto w-full max-w-lg border-rose-200 bg-white shadow-lg dark:border-rose-900/60 dark:bg-slate-900">
                    <CardHeader className="text-center pb-3">
                        <div className="mx-auto mb-3 flex h-16 w-16 items-center justify-center rounded-full bg-rose-100 text-rose-600 dark:bg-rose-950/70 dark:text-rose-400">
                            <ShieldAlert className="h-8 w-8" />
                        </div>
                        <CardTitle className="text-xl font-bold tracking-tight text-rose-700 dark:text-rose-400">
                            {isAccessDenied ? 'Access Restricted' : 'Widget Unavailable'}
                        </CardTitle>
                        <CardDescription className="mt-2 text-sm font-medium text-slate-800 dark:text-slate-200">
                            {feedback?.text || 'Your Bitrix24 user account is not authorized to access this widget.'}
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-4 pt-1 text-center">
                        <div className="rounded-lg border border-slate-200 bg-slate-50 p-4 text-xs text-slate-600 dark:border-slate-800 dark:bg-slate-950 dark:text-slate-400">
                            <strong className="block text-slate-900 dark:text-slate-100 font-semibold mb-1">
                                Need access to this quoting tool?
                            </strong>
                            <p>
                                Please contact your Bitrix24 portal administrator. They can grant your user account a licensed seat from the <strong>Application Settings &gt; User Access &amp; Seat Allocation</strong> menu.
                            </p>
                            <p className="mt-2.5 pt-2 border-t border-slate-200/80 text-[11px] text-slate-500 dark:border-slate-800 dark:text-slate-400">
                                Need technical support or have billing questions? Contact us at{' '}
                                <a href="mailto:apps@keenenter.com" className="font-semibold text-indigo-600 hover:underline dark:text-indigo-400">
                                    apps@keenenter.com
                                </a>
                            </p>
                        </div>
                    </CardContent>
                </Card>
            </div>
        );
    }

    const { entity, schema, subscription } = context;
    const isBlocked = !subscription.allowed;

    return (
        <div className="min-h-screen bg-slate-50/70 p-4 text-slate-900 dark:bg-slate-950 dark:text-slate-100">
            <Head title={`KEEN Products — ${entity.title}`} />

            <div className="mx-auto max-w-[1400px] space-y-4">
                {/* Top Header Bar */}
                <Card className="border-slate-200/80 shadow-xs dark:border-slate-800">
                    <CardContent className="flex flex-wrap items-center justify-between gap-3 py-4">
                        <div className="space-y-1">
                            <div className="flex flex-wrap items-center gap-2">
                                <Badge variant="outline" className="font-mono text-xs uppercase">
                                    {entity.owner_type_code} #{entity.entity_id}
                                </Badge>
                                <h1 className="text-lg font-semibold tracking-tight">{entity.title}</h1>
                                <Badge variant="secondary" className="text-xs">
                                    {schema.fee_sync_mode.replace('_', ' ')}
                                </Badge>
                            </div>
                            <p className="text-muted-foreground text-xs">
                                Plan: <span className="text-foreground font-medium">{subscription.plan_name ?? 'Trial'}</span>
                                {' · '}
                                Monthly Syncs:{' '}
                                <span className="font-mono">
                                    {subscription.sync_count}
                                    {subscription.max_monthly_syncs ? ` / ${subscription.max_monthly_syncs}` : ' / ∞'}
                                </span>
                                {context.last_synced_at && (
                                    <>
                                        {' · '}Last synced {new Date(context.last_synced_at).toLocaleString()}
                                    </>
                                )}
                            </p>
                        </div>

                        <div className="flex flex-wrap items-center gap-2">
                            <div className="flex items-center gap-1.5">
                                <Label htmlFor="currency-select" className="text-muted-foreground text-xs">
                                    Currency
                                </Label>
                                <select
                                    id="currency-select"
                                    value={currencyId}
                                    onChange={(e) => setCurrencyId(e.target.value)}
                                    disabled={isBlocked}
                                    className="border-input bg-background focus:ring-ring h-9 rounded-md border px-2.5 text-sm shadow-xs focus:ring-2 focus:outline-hidden"
                                >
                                    {context.currencies.map((c) => (
                                        <option key={c.code} value={c.code}>
                                            {c.code} — {c.name}
                                        </option>
                                    ))}
                                </select>
                            </div>

                            <Button variant="outline" size="sm" onClick={handleReload} disabled={busyAction !== null}>
                                {busyAction === 'reload' ? (
                                    <Loader2 className="mr-1.5 h-3.5 w-3.5 animate-spin" />
                                ) : (
                                    <RefreshCw className="mr-1.5 h-3.5 w-3.5" />
                                )}
                                Reload
                            </Button>

                            <Button variant="outline" size="sm" onClick={openCatalogModal} disabled={isBlocked}>
                                <Search className="mr-1.5 h-3.5 w-3.5" />
                                Catalog
                            </Button>

                            {can_manage_catalog && (
                                <Button variant="outline" size="sm" onClick={() => openProductModal()} disabled={isBlocked}>
                                    <PackagePlus className="mr-1.5 h-3.5 w-3.5" />
                                    New Product
                                </Button>
                            )}

                            <Button variant="outline" size="sm" onClick={addEmptyRow} disabled={isBlocked}>
                                <Plus className="mr-1.5 h-3.5 w-3.5" />
                                Add Row
                            </Button>

                            <Button
                                variant="outline"
                                size="sm"
                                className="text-destructive hover:text-destructive"
                                onClick={() => setClearOpen(true)}
                                disabled={isBlocked || busyAction !== null || rows.length === 0}
                            >
                                <Trash2 className="mr-1.5 h-3.5 w-3.5" />
                                Clear All
                            </Button>

                            <Button size="sm" onClick={handleSync} disabled={isBlocked || busyAction !== null}>
                                {busyAction === 'sync' ? (
                                    <Loader2 className="mr-1.5 h-3.5 w-3.5 animate-spin" />
                                ) : (
                                    <Save className="mr-1.5 h-3.5 w-3.5" />
                                )}
                                Save & Sync
                            </Button>
                        </div>
                    </CardContent>
                </Card>

                {/* Notices / Alerts */}
                {!entity.is_configured && (
                    <Alert>
                        <AlertCircle className="h-4 w-4" />
                        <AlertTitle>Portal Schema Not Mapped</AlertTitle>
                        <AlertDescription>
                            This CRM entity or the Commercial Catalog is not yet provisioned. Ask your Bitrix24 administrator to open Applications →
                            Fee Breakdown Settings to complete provisioning or custom field mapping.
                        </AlertDescription>
                    </Alert>
                )}

                {subscription.banner && (
                    <Alert variant={isBlocked ? 'destructive' : 'default'}>
                        <AlertCircle className="h-4 w-4" />
                        <AlertTitle>{isBlocked ? 'Sync Disabled by Subscription' : 'Subscription Notice'}</AlertTitle>
                        <AlertDescription className="flex flex-wrap items-center justify-between gap-2">
                            <span>{subscription.banner}</span>
                            <a href="mailto:apps@keenenter.com" className="font-semibold underline">
                                Contact Support (apps@keenenter.com)
                            </a>
                        </AlertDescription>
                    </Alert>
                )}

                {feedback && (
                    <Alert variant={feedback.type === 'error' ? 'destructive' : 'default'}>
                        {feedback.type === 'error' ? <AlertCircle className="h-4 w-4" /> : <CheckCircle2 className="h-4 w-4 text-emerald-600" />}
                        <AlertDescription>{feedback.text}</AlertDescription>
                    </Alert>
                )}

                {/* Dynamic Option & Cost Type Subtotal Summary Cards */}
                <div className="grid gap-3 md:grid-cols-2 lg:grid-cols-4">
                    {schema.options.map((opt) => (
                        <Card key={opt.logical_key} className="border-slate-200/80 dark:border-slate-800">
                            <CardHeader className="pb-2">
                                <CardDescription className="text-xs font-medium tracking-wide uppercase">{opt.label}</CardDescription>
                                <CardTitle className="text-xl font-bold tabular-nums">
                                    {formatMoney(summary.optionTotals[opt.logical_key] ?? 0, currencyId)}
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-1 text-xs">
                                {schema.cost_types.map((ct) => (
                                    <div key={ct.logical_key} className="text-muted-foreground flex items-center justify-between">
                                        <span>{ct.label}</span>
                                        <span className="text-foreground font-mono">
                                            {formatMoney(summary.bucketTotals[`${opt.logical_key}::${ct.logical_key}`] ?? 0, currencyId)}
                                        </span>
                                    </div>
                                ))}
                            </CardContent>
                        </Card>
                    ))}

                    <Card className="border-indigo-200/80 bg-indigo-50/40 dark:border-indigo-900/50 dark:bg-indigo-950/20">
                        <CardHeader className="pb-2">
                            <CardDescription className="text-xs font-medium tracking-wide text-indigo-700 uppercase dark:text-indigo-300">
                                Combined Opportunity
                            </CardDescription>
                            <CardTitle className="text-xl font-bold tabular-nums">{formatMoney(summary.totalGross, currencyId)}</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-1 text-xs">
                            <div className="text-muted-foreground flex items-center justify-between">
                                <span>Subtotal (Excl. Tax)</span>
                                <span className="text-foreground font-mono">{formatMoney(summary.totalNet, currencyId)}</span>
                            </div>
                            <div className="text-muted-foreground flex items-center justify-between">
                                <span>Total Tax</span>
                                <span className="text-foreground font-mono">{formatMoney(summary.totalTax, currencyId)}</span>
                            </div>
                            <div className="text-muted-foreground flex items-center justify-between">
                                <span>Line Items</span>
                                <span className="text-foreground font-mono">{rows.length}</span>
                            </div>
                        </CardContent>
                    </Card>
                </div>

                {/* Fee Line Items Grid */}
                <Card className="border-slate-200/80 dark:border-slate-800">
                    <CardHeader className="pb-3">
                        <div className="flex items-center justify-between">
                            <div className="flex items-center gap-2">
                                <Layers className="text-muted-foreground h-4 w-4" />
                                <CardTitle className="text-base">Fee Line Items</CardTitle>
                            </div>
                            <span className="text-muted-foreground text-xs">
                                Changes update CRM product rows, catalog attributes, entity money totals, and linked SPAs together.
                            </span>
                        </div>
                    </CardHeader>
                    <CardContent className="p-0">
                        <div className="overflow-x-auto">
                            <table className="w-full border-t text-sm">
                                <thead className="text-muted-foreground bg-slate-100/80 text-xs uppercase dark:bg-slate-900">
                                    <tr>
                                        <th className="px-3 py-2.5 text-left font-medium">#</th>
                                        <th className="min-w-[220px] px-3 py-2.5 text-left font-medium">Product / Service</th>
                                        <th className="min-w-[140px] px-3 py-2.5 text-left font-medium">Cost Type</th>
                                        <th className="min-w-[140px] px-3 py-2.5 text-left font-medium">Option</th>
                                        <th className="min-w-[150px] px-3 py-2.5 text-left font-medium">Payment</th>
                                        {schema.extra_attributes.map((attr) => (
                                            <th key={attr.key} className="min-w-[130px] px-3 py-2.5 text-left font-medium">
                                                {attr.label}
                                            </th>
                                        ))}
                                        <th className="w-28 px-3 py-2.5 text-right font-medium">Price</th>
                                        <th className="w-20 px-3 py-2.5 text-right font-medium">Qty</th>
                                        <th className="w-20 px-3 py-2.5 text-right font-medium">Tax %</th>
                                        <th className="px-3 py-2.5 text-center font-medium">Incl.</th>
                                        <th className="px-3 py-2.5 text-right font-medium">Total</th>
                                        <th className="px-3 py-2.5 text-right font-medium">Actions</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-200 dark:divide-slate-800">
                                    {rows.length === 0 ? (
                                        <tr>
                                            <td
                                                colSpan={11 + schema.extra_attributes.length}
                                                className="text-muted-foreground px-4 py-10 text-center text-sm"
                                            >
                                                No fee lines yet. Click <strong>Catalog</strong> to pick an existing product or{' '}
                                                <strong>Add Row</strong> to create a custom line.
                                            </td>
                                        </tr>
                                    ) : (
                                        rows.map((row, idx) => {
                                            const line = calcLine(row);
                                            return (
                                                <tr key={idx} className="hover:bg-slate-50/80 dark:hover:bg-slate-900/40">
                                                    <td className="px-3 py-2 align-middle">
                                                        <div className="flex items-center gap-1">
                                                            <span className="text-muted-foreground w-5 font-mono text-xs">{idx + 1}</span>
                                                            <div className="flex flex-col">
                                                                <button
                                                                    type="button"
                                                                    onClick={() => moveRow(idx, -1)}
                                                                    disabled={idx === 0 || isBlocked}
                                                                    className="text-muted-foreground hover:text-foreground disabled:opacity-30"
                                                                    title="Move up"
                                                                >
                                                                    <ArrowUp className="h-3 w-3" />
                                                                </button>
                                                                <button
                                                                    type="button"
                                                                    onClick={() => moveRow(idx, 1)}
                                                                    disabled={idx === rows.length - 1 || isBlocked}
                                                                    className="text-muted-foreground hover:text-foreground disabled:opacity-30"
                                                                    title="Move down"
                                                                >
                                                                    <ArrowDown className="h-3 w-3" />
                                                                </button>
                                                            </div>
                                                        </div>
                                                    </td>
                                                    <td className="px-3 py-2">
                                                        <div className="flex items-center gap-1.5">
                                                            <Input
                                                                value={row.productName}
                                                                onChange={(e) => updateRow(idx, { productName: e.target.value })}
                                                                placeholder="Service or fee description..."
                                                                disabled={isBlocked}
                                                                className="h-8 text-sm"
                                                            />
                                                            {row.productId ? (
                                                                <Badge variant="outline" className="shrink-0 font-mono text-[10px]">
                                                                    #{row.productId}
                                                                </Badge>
                                                            ) : null}
                                                        </div>
                                                    </td>
                                                    <td className="px-3 py-2">
                                                        <select
                                                            value={row.costTypeKey}
                                                            onChange={(e) => updateRow(idx, { costTypeKey: e.target.value })}
                                                            disabled={isBlocked}
                                                            className="border-input bg-background h-8 w-full rounded-md border px-2 text-xs"
                                                        >
                                                            {schema.cost_types.map((ct) => (
                                                                <option key={ct.logical_key} value={ct.logical_key}>
                                                                    {ct.label}
                                                                </option>
                                                            ))}
                                                        </select>
                                                    </td>
                                                    <td className="px-3 py-2">
                                                        <select
                                                            value={row.optionKey}
                                                            onChange={(e) => updateRow(idx, { optionKey: e.target.value })}
                                                            disabled={isBlocked}
                                                            className="border-input bg-background h-8 w-full rounded-md border px-2 text-xs"
                                                        >
                                                            {schema.options.map((opt) => (
                                                                <option key={opt.logical_key} value={opt.logical_key}>
                                                                    {opt.label}
                                                                </option>
                                                            ))}
                                                        </select>
                                                    </td>
                                                    <td className="px-3 py-2">
                                                        <select
                                                            value={row.paymentKey}
                                                            onChange={(e) => updateRow(idx, { paymentKey: e.target.value })}
                                                            disabled={isBlocked}
                                                            className="border-input bg-background h-8 w-full rounded-md border px-2 text-xs"
                                                        >
                                                            <option value="">— None —</option>
                                                            {schema.payments.map((p) => (
                                                                <option key={p.logical_key} value={p.logical_key}>
                                                                    {p.label}
                                                                </option>
                                                            ))}
                                                        </select>
                                                    </td>
                                                    {schema.extra_attributes.map((attr) => (
                                                        <td key={attr.key} className="px-3 py-2">
                                                            {attr.property_type === 'L' && attr.enum_options?.length ? (
                                                                <select
                                                                    value={row.extraAttributes?.[attr.key] ?? ''}
                                                                    onChange={(e) =>
                                                                        updateRow(idx, {
                                                                            extraAttributes: {
                                                                                ...(row.extraAttributes ?? {}),
                                                                                [attr.key]: e.target.value,
                                                                            },
                                                                        })
                                                                    }
                                                                    disabled={isBlocked}
                                                                    className="border-input bg-background h-8 w-full rounded-md border px-2 text-xs"
                                                                >
                                                                    <option value="">—</option>
                                                                    {attr.enum_options.map((opt) => (
                                                                        <option key={opt.key} value={opt.id ?? opt.key}>
                                                                            {opt.label}
                                                                        </option>
                                                                    ))}
                                                                </select>
                                                            ) : (
                                                                <Input
                                                                    value={row.extraAttributes?.[attr.key] ?? ''}
                                                                    onChange={(e) =>
                                                                        updateRow(idx, {
                                                                            extraAttributes: {
                                                                                ...(row.extraAttributes ?? {}),
                                                                                [attr.key]: e.target.value,
                                                                            },
                                                                        })
                                                                    }
                                                                    disabled={isBlocked}
                                                                    className="h-8 text-xs"
                                                                />
                                                            )}
                                                        </td>
                                                    ))}
                                                    <td className="px-3 py-2">
                                                        <Input
                                                            type="number"
                                                            step="0.01"
                                                            min="0"
                                                            value={row.price}
                                                            onChange={(e) => updateRow(idx, { price: parseFloat(e.target.value) || 0 })}
                                                            disabled={isBlocked}
                                                            className="h-8 text-right font-mono text-xs"
                                                        />
                                                    </td>
                                                    <td className="px-3 py-2">
                                                        <Input
                                                            type="number"
                                                            step="0.01"
                                                            min="0.01"
                                                            value={row.quantity}
                                                            onChange={(e) => updateRow(idx, { quantity: parseFloat(e.target.value) || 1 })}
                                                            disabled={isBlocked}
                                                            className="h-8 text-right font-mono text-xs"
                                                        />
                                                    </td>
                                                    <td className="px-3 py-2">
                                                        <Input
                                                            type="number"
                                                            step="0.1"
                                                            min="0"
                                                            max="100"
                                                            value={row.taxRate}
                                                            onChange={(e) => updateRow(idx, { taxRate: parseFloat(e.target.value) || 0 })}
                                                            disabled={isBlocked}
                                                            className="h-8 text-right font-mono text-xs"
                                                        />
                                                    </td>
                                                    <td className="px-3 py-2 text-center">
                                                        <Checkbox
                                                            checked={row.taxIncluded}
                                                            onCheckedChange={(checked) => updateRow(idx, { taxIncluded: Boolean(checked) })}
                                                            disabled={isBlocked}
                                                        />
                                                    </td>
                                                    <td className="px-3 py-2 text-right font-mono text-xs font-semibold tabular-nums">
                                                        {formatMoney(line.gross, currencyId)}
                                                    </td>
                                                    <td className="px-3 py-2 text-right">
                                                        <div className="flex items-center justify-end gap-1">
                                                            {can_manage_catalog && (
                                                                <Button
                                                                    type="button"
                                                                    variant="ghost"
                                                                    size="icon"
                                                                    className="h-7 w-7"
                                                                    title="Save / Edit in Catalog"
                                                                    onClick={() => openProductModal(undefined, idx)}
                                                                    disabled={isBlocked}
                                                                >
                                                                    <Edit3 className="h-3.5 w-3.5" />
                                                                </Button>
                                                            )}
                                                            <Button
                                                                type="button"
                                                                variant="ghost"
                                                                size="icon"
                                                                className="text-destructive hover:text-destructive h-7 w-7"
                                                                title="Remove row"
                                                                onClick={() => removeRow(idx)}
                                                                disabled={isBlocked}
                                                            >
                                                                <Trash2 className="h-3.5 w-3.5" />
                                                            </Button>
                                                        </div>
                                                    </td>
                                                </tr>
                                            );
                                        })
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </CardContent>
                </Card>

                {/* Option Details / Metadata Panels */}
                {schema.option_meta_schema.length > 0 && (
                    <div className="grid gap-4 md:grid-cols-2">
                        {schema.options.map((opt) => (
                            <Card key={opt.logical_key} className="border-slate-200/80 dark:border-slate-800">
                                <CardHeader className="pb-3">
                                    <CardTitle className="text-sm font-semibold">{opt.label} — Details</CardTitle>
                                    <CardDescription className="text-xs">
                                        Synced directly to custom fields on this {entity.owner_type_code} record.
                                    </CardDescription>
                                </CardHeader>
                                <CardContent className="space-y-3">
                                    {schema.option_meta_schema.map((meta) => {
                                        const qual = `${opt.logical_key}.${meta.key}`;
                                        const val = optionMeta[qual] ?? '';
                                        return (
                                            <div key={qual} className="space-y-1">
                                                <Label className="text-xs">{meta.label}</Label>
                                                {meta.type === 'textarea' ? (
                                                    <textarea
                                                        rows={2}
                                                        value={val}
                                                        onChange={(e) =>
                                                            setOptionMeta((prev) => ({
                                                                ...prev,
                                                                [qual]: e.target.value,
                                                            }))
                                                        }
                                                        disabled={isBlocked}
                                                        className="border-input bg-background focus:ring-ring w-full rounded-md border px-3 py-2 text-sm shadow-xs focus:ring-2 focus:outline-hidden"
                                                    />
                                                ) : (
                                                    <Input
                                                        type={meta.type === 'date' ? 'date' : 'text'}
                                                        value={val}
                                                        onChange={(e) =>
                                                            setOptionMeta((prev) => ({
                                                                ...prev,
                                                                [qual]: e.target.value,
                                                            }))
                                                        }
                                                        disabled={isBlocked}
                                                        className="h-8 text-sm"
                                                    />
                                                )}
                                            </div>
                                        );
                                    })}
                                </CardContent>
                            </Card>
                        ))}
                    </div>
                )}
            </div>

            {/* Catalog Search Modal */}
            <Dialog open={catalogOpen} onOpenChange={setCatalogOpen}>
                <DialogContent className="max-w-2xl">
                    <DialogHeader>
                        <DialogTitle className="flex items-center gap-2">
                            <Database className="h-4 w-4" />
                            Commercial Catalog Search
                        </DialogTitle>
                        <DialogDescription>
                            Pick an existing product from Bitrix24 Commercial Catalog (iblock #{schema.iblock_id ?? '—'}).
                        </DialogDescription>
                    </DialogHeader>

                    <div className="flex items-center gap-2">
                        <Input
                            placeholder="Search products by name..."
                            value={catalogQuery}
                            onChange={(e) => setCatalogQuery(e.target.value)}
                            onKeyDown={(e) => {
                                if (e.key === 'Enter') searchCatalog(catalogQuery);
                            }}
                        />
                        <Button variant="secondary" onClick={() => searchCatalog(catalogQuery)} disabled={catalogLoading}>
                            {catalogLoading ? <Loader2 className="h-4 w-4 animate-spin" /> : 'Search'}
                        </Button>
                        {can_manage_catalog && (
                            <Button variant="outline" onClick={() => openProductModal()}>
                                <Plus className="mr-1 h-4 w-4" />
                                New
                            </Button>
                        )}
                    </div>

                    <div className="max-h-80 divide-y overflow-y-auto rounded-md border">
                        {catalogLoading ? (
                            <div className="text-muted-foreground flex items-center justify-center py-8 text-sm">
                                <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                                Querying Bitrix24 catalog...
                            </div>
                        ) : catalogProducts.length === 0 ? (
                            <div className="text-muted-foreground py-8 text-center text-sm">No matching products found.</div>
                        ) : (
                            catalogProducts.map((p) => (
                                <div key={p.id} className="flex items-center justify-between gap-3 px-3 py-2.5 text-sm">
                                    <div className="min-w-0 flex-1 space-y-1">
                                        <div className="flex items-center gap-2">
                                            <span className="truncate font-medium">{p.name}</span>
                                            <Badge variant="outline" className="font-mono text-[10px]">
                                                #{p.id}
                                            </Badge>
                                        </div>
                                        <div className="text-muted-foreground flex flex-wrap items-center gap-1.5 text-xs">
                                            <span className="text-foreground font-mono">{formatMoney(p.price, p.currency)}</span>
                                            {p.costTypeKey && <Badge variant="secondary">{p.costTypeKey}</Badge>}
                                            {p.optionKey && <Badge variant="secondary">{p.optionKey}</Badge>}
                                            {p.paymentKey && <Badge variant="outline">{p.paymentKey}</Badge>}
                                        </div>
                                    </div>
                                    <div className="flex items-center gap-1.5">
                                        {can_manage_catalog && (
                                            <Button variant="ghost" size="sm" onClick={() => openProductModal(p, null)}>
                                                Edit
                                            </Button>
                                        )}
                                        <Button size="sm" onClick={() => addCatalogProductAsRow(p)}>
                                            Add
                                        </Button>
                                    </div>
                                </div>
                            ))
                        )}
                    </div>
                </DialogContent>
            </Dialog>

            {/* Create / Edit Catalog Product Modal */}
            <Dialog open={productModalOpen} onOpenChange={setProductModalOpen}>
                <DialogContent className="max-w-lg">
                    <DialogHeader>
                        <DialogTitle>{productForm.id ? `Edit Catalog Product #${productForm.id}` : 'Create Catalog Product'}</DialogTitle>
                        <DialogDescription>
                            Saves directly to the Bitrix24 Commercial Catalog with its base price & fee classifications.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="space-y-3 py-2">
                        <div className="space-y-1">
                            <Label>Product Name</Label>
                            <Input
                                value={productForm.name}
                                onChange={(e) => setProductForm((p) => ({ ...p, name: e.target.value }))}
                                placeholder="e.g., Company Formation — Government Fee"
                            />
                        </div>
                        <div className="grid grid-cols-2 gap-3">
                            <div className="space-y-1">
                                <Label>Base Price</Label>
                                <Input
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    value={productForm.price}
                                    onChange={(e) => setProductForm((p) => ({ ...p, price: e.target.value }))}
                                />
                            </div>
                            <div className="space-y-1">
                                <Label>Currency</Label>
                                <select
                                    value={productForm.currency}
                                    onChange={(e) => setProductForm((p) => ({ ...p, currency: e.target.value }))}
                                    className="border-input bg-background h-9 w-full rounded-md border px-2.5 text-sm"
                                >
                                    {context.currencies.map((c) => (
                                        <option key={c.code} value={c.code}>
                                            {c.code}
                                        </option>
                                    ))}
                                </select>
                            </div>
                        </div>

                        <div className="grid grid-cols-3 gap-2">
                            <div className="space-y-1">
                                <Label className="text-xs">Cost Type</Label>
                                <select
                                    value={productForm.costTypeKey}
                                    onChange={(e) => setProductForm((p) => ({ ...p, costTypeKey: e.target.value }))}
                                    className="border-input bg-background h-9 w-full rounded-md border px-2 text-xs"
                                >
                                    {schema.cost_types.map((ct) => (
                                        <option key={ct.logical_key} value={ct.logical_key}>
                                            {ct.label}
                                        </option>
                                    ))}
                                </select>
                            </div>
                            <div className="space-y-1">
                                <Label className="text-xs">Option</Label>
                                <select
                                    value={productForm.optionKey}
                                    onChange={(e) => setProductForm((p) => ({ ...p, optionKey: e.target.value }))}
                                    className="border-input bg-background h-9 w-full rounded-md border px-2 text-xs"
                                >
                                    {schema.options.map((o) => (
                                        <option key={o.logical_key} value={o.logical_key}>
                                            {o.label}
                                        </option>
                                    ))}
                                </select>
                            </div>
                            <div className="space-y-1">
                                <Label className="text-xs">Payment</Label>
                                <select
                                    value={productForm.paymentKey}
                                    onChange={(e) => setProductForm((p) => ({ ...p, paymentKey: e.target.value }))}
                                    className="border-input bg-background h-9 w-full rounded-md border px-2 text-xs"
                                >
                                    <option value="">— None —</option>
                                    {schema.payments.map((pay) => (
                                        <option key={pay.logical_key} value={pay.logical_key}>
                                            {pay.label}
                                        </option>
                                    ))}
                                </select>
                            </div>
                        </div>

                        {schema.extra_attributes.map((attr) => (
                            <div key={attr.key} className="space-y-1">
                                <Label className="text-xs">{attr.label}</Label>
                                <Input
                                    value={productForm.extraAttributes[attr.key] ?? ''}
                                    onChange={(e) =>
                                        setProductForm((p) => ({
                                            ...p,
                                            extraAttributes: {
                                                ...p.extraAttributes,
                                                [attr.key]: e.target.value,
                                            },
                                        }))
                                    }
                                />
                            </div>
                        ))}
                    </div>

                    <DialogFooter>
                        <Button variant="outline" onClick={() => setProductModalOpen(false)}>
                            Cancel
                        </Button>
                        <Button onClick={saveCatalogProduct} disabled={productSaving || !productForm.name.trim()}>
                            {productSaving && <Loader2 className="mr-1.5 h-3.5 w-3.5 animate-spin" />}
                            Save Catalog Product
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* Clear All Confirmation Modal */}
            <Dialog open={clearOpen} onOpenChange={setClearOpen}>
                <DialogContent className="max-w-md">
                    <DialogHeader>
                        <DialogTitle>Clear All Fee Rows?</DialogTitle>
                        <DialogDescription>
                            This will remove all product rows on this {entity.owner_type_code}, reset all fee subtotals to 0.00, and delete the
                            synchronized SPA breakdown items linked to this record.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setClearOpen(false)}>
                            Cancel
                        </Button>
                        <Button variant="destructive" onClick={handleClear}>
                            Yes, Clear Everything
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* Widget Support Line */}
            <div className="pt-2 text-center text-xs text-muted-foreground">
                <span>KEEN Products &bull; Need help or feature assistance? Contact us at </span>
                <a href="mailto:apps@keenenter.com" className="font-medium text-indigo-600 hover:underline dark:text-indigo-400">
                    apps@keenenter.com
                </a>
            </div>
        </div>
    );
}
