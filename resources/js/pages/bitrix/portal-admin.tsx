import { Head } from '@inertiajs/react';
import { Activity, AlertCircle, CheckCircle2, CreditCard, Link2, Loader2, Play, RefreshCw, Shield, Sliders } from 'lucide-react';
import { useState } from 'react';

import { SchemaMapper, type PortalTenantSchema } from '@/components/bitrix/schema-mapper';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { bitrixFetch } from '@/lib/bitrix-api';

interface PlanItem {
    id: number;
    code: string;
    name: string;
    description: string | null;
    price_monthly_cents: number;
    price_yearly_cents: number;
    currency: string;
    max_monthly_syncs: number | null;
    max_options: number;
    features_json: Record<string, boolean> | null;
}

interface SyncLogItem {
    id: number;
    entity_type_id: number | null;
    entity_id: number | null;
    trigger_source: string;
    status: string;
    duration_ms: number | null;
    api_calls_count: number;
    actions_count: number;
    errors_json: string[] | null;
    created_at: string;
}

interface SubscriptionEval {
    allowed: boolean;
    reason: string | null;
    banner: string | null;
    status: string;
    plan: PlanItem | null;
    subscription: {
        id: number;
        status: string;
        billing_cycle: 'monthly' | 'yearly';
        trial_ends_at: string | null;
        current_period_end: string | null;
        grace_ends_at: string | null;
        upgrade_requested_plan_id: number | null;
        upgrade_requested_at: string | null;
    };
    usage: {
        sync_count: number;
        api_calls_count: number;
        webhook_count: number;
    };
    max_monthly_syncs: number | null;
    remaining_syncs: number | null;
    max_options: number;
    features: Record<string, boolean>;
}

interface Props {
    error: string | null;
    jwt: string | null;
    is_admin: boolean;
    viewer_name?: string | null;
    tenant:
        | (PortalTenantSchema & {
              id: number;
              member_id: string;
              domain: string;
              app_status: string;
              install_status: string;
              license_type?: string | null;
              installed_at?: string | null;
              last_sync_at?: string | null;
          })
        | null;
    subscription?: SubscriptionEval;
    plans?: PlanItem[];
    recent_logs?: SyncLogItem[];
}

export default function BitrixPortalAdmin({
    error,
    jwt,
    is_admin,
    viewer_name,
    tenant: initialTenant,
    subscription: initialSub,
    plans = [],
    recent_logs: initialLogs = [],
}: Props) {
    const [tab, setTab] = useState<'overview' | 'schema' | 'logs' | 'billing'>('overview');
    const [tenant, setTenant] = useState(initialTenant);
    const [subEval, setSubEval] = useState(initialSub);
    const [logs, setLogs] = useState<SyncLogItem[]>(initialLogs);
    const [billingCycle, setBillingCycle] = useState<'monthly' | 'yearly'>(initialSub?.subscription.billing_cycle ?? 'monthly');
    const [backfillEntityId, setBackfillEntityId] = useState<number>(initialTenant?.entity_configs?.[0]?.entity_type_id ?? 2);
    const [backfillLimit, setBackfillLimit] = useState('50');

    const [busyAction, setBusyAction] = useState<string | null>(null);
    const [notice, setNotice] = useState<{ type: 'success' | 'error'; text: string } | null>(null);

    if (error || !tenant) {
        return (
            <div className="min-h-screen bg-slate-50 p-6 dark:bg-slate-950">
                <Head title="Application Console" />
                <Alert variant="destructive" className="mx-auto max-w-2xl">
                    <AlertCircle className="h-4 w-4" />
                    <AlertTitle>Unable to Open Portal Console</AlertTitle>
                    <AlertDescription>{error || 'Portal session could not be verified.'}</AlertDescription>
                </Alert>
            </div>
        );
    }

    // Standard non-admin Bitrix24 users see a helpful, permission-safe status screen.
    if (!is_admin) {
        return (
            <div className="min-h-screen bg-slate-50 p-6 dark:bg-slate-950">
                <Head title={`Fee Breakdown — ${tenant.domain}`} />
                <div className="mx-auto max-w-2xl space-y-4">
                    <Card>
                        <CardHeader>
                            <div className="flex items-center justify-between">
                                <CardTitle>Fee Breakdown Matrix</CardTitle>
                                <Badge variant="secondary">{subEval?.plan?.name ?? 'Trial'}</Badge>
                            </div>
                            <CardDescription>
                                Connected Portal: <strong>{tenant.domain}</strong>
                                {viewer_name ? ` · Signed in as ${viewer_name}` : ''}
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-3 text-sm">
                            <p className="text-muted-foreground">
                                You can manage Fee Breakdown lines directly inside any enabled CRM Deal, Lead, Quote, or Smart Process tab.
                                Configuration changes and subscription upgrades require a Bitrix24 Portal Administrator.
                            </p>
                            {subEval?.banner && (
                                <Alert>
                                    <AlertCircle className="h-4 w-4" />
                                    <AlertDescription>{subEval.banner}</AlertDescription>
                                </Alert>
                            )}
                        </CardContent>
                    </Card>
                </div>
            </div>
        );
    }

    const handleSyncBindings = async () => {
        if (!jwt) return;
        setBusyAction('bindings');
        setNotice(null);
        try {
            const res = await bitrixFetch<{
                success: boolean;
                bindings: { placements?: string[]; events?: string[]; warnings?: string[] };
            }>('/api/bitrix/portal/sync-bindings', jwt, {});
            setNotice({
                type: 'success',
                text: `Synced ${res.bindings.placements?.length ?? 0} placements and ${res.bindings.events?.length ?? 0} webhook events cleanly.`,
            });
        } catch (e) {
            setNotice({ type: 'error', text: (e as Error).message });
        } finally {
            setBusyAction(null);
        }
    };

    const handleRefreshLogs = async () => {
        if (!jwt) return;
        setBusyAction('logs');
        try {
            const res = await bitrixFetch<{ success: boolean; logs: SyncLogItem[] }>('/api/bitrix/portal/logs', jwt, undefined, 'GET');
            setLogs(res.logs ?? []);
        } catch (e) {
            setNotice({ type: 'error', text: (e as Error).message });
        } finally {
            setBusyAction(null);
        }
    };

    const handleRunBackfill = async () => {
        if (!jwt) return;
        setBusyAction('backfill');
        setNotice(null);
        try {
            const res = await bitrixFetch<{ success: boolean; message: string }>('/api/bitrix/portal/backfill', jwt, {
                entity_type_id: Number(backfillEntityId),
                limit: Math.min(250, Math.max(1, parseInt(backfillLimit, 10) || 50)),
            });
            setNotice({ type: 'success', text: res.message });
        } catch (e) {
            setNotice({ type: 'error', text: (e as Error).message });
        } finally {
            setBusyAction(null);
        }
    };

    const handleRequestUpgrade = async (planId: number) => {
        if (!jwt) return;
        setBusyAction(`upgrade-${planId}`);
        setNotice(null);
        try {
            const res = await bitrixFetch<{
                success: boolean;
                message: string;
                subscription: { upgrade_requested_plan_id: number | null; upgrade_requested_at: string | null };
            }>('/api/bitrix/portal/request-upgrade', jwt, {
                plan_id: planId,
                billing_cycle: billingCycle,
            });
            setSubEval((prev) =>
                prev
                    ? {
                          ...prev,
                          subscription: {
                              ...prev.subscription,
                              upgrade_requested_plan_id: res.subscription.upgrade_requested_plan_id,
                              upgrade_requested_at: res.subscription.upgrade_requested_at,
                          },
                      }
                    : prev,
            );
            setNotice({ type: 'success', text: res.message });
        } catch (e) {
            setNotice({ type: 'error', text: (e as Error).message });
        } finally {
            setBusyAction(null);
        }
    };

    return (
        <div className="min-h-screen bg-slate-50 p-6 text-slate-900 dark:bg-slate-950 dark:text-slate-100">
            <Head title={`Portal Settings — ${tenant.domain}`} />

            <div className="mx-auto max-w-6xl space-y-5">
                {/* Header */}
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <div className="flex items-center gap-2">
                            <h1 className="text-2xl font-bold tracking-tight">Fee Breakdown Console</h1>
                            <Badge variant="outline" className="font-mono text-xs">
                                {tenant.domain}
                            </Badge>
                        </div>
                        <p className="text-muted-foreground text-xs">
                            Member ID: <span className="font-mono">{tenant.member_id}</span> · Signed in as {viewer_name || 'Administrator'}
                        </p>
                    </div>

                    <div className="flex flex-wrap gap-2">
                        {[
                            { id: 'overview', label: 'Overview', icon: Shield },
                            { id: 'schema', label: 'Schema & Fields', icon: Sliders },
                            { id: 'logs', label: 'Backfill & Logs', icon: Activity },
                            { id: 'billing', label: 'Subscription & Plans', icon: CreditCard },
                        ].map((item) => {
                            const Icon = item.icon;
                            return (
                                <Button
                                    key={item.id}
                                    variant={tab === item.id ? 'default' : 'outline'}
                                    size="sm"
                                    onClick={() => setTab(item.id as typeof tab)}
                                >
                                    <Icon className="mr-1.5 h-3.5 w-3.5" />
                                    {item.label}
                                </Button>
                            );
                        })}
                    </div>
                </div>

                {subEval?.banner && (
                    <Alert variant={subEval.allowed ? 'default' : 'destructive'}>
                        <AlertCircle className="h-4 w-4" />
                        <AlertDescription>{subEval.banner}</AlertDescription>
                    </Alert>
                )}

                {notice && (
                    <Alert variant={notice.type === 'error' ? 'destructive' : 'default'}>
                        {notice.type === 'error' ? <AlertCircle className="h-4 w-4" /> : <CheckCircle2 className="h-4 w-4 text-emerald-600" />}
                        <AlertDescription>{notice.text}</AlertDescription>
                    </Alert>
                )}

                {/* Overview Tab */}
                {tab === 'overview' && (
                    <div className="space-y-4">
                        <div className="grid gap-4 md:grid-cols-4">
                            <Card>
                                <CardHeader className="pb-2">
                                    <CardDescription>Plan Tier</CardDescription>
                                    <CardTitle className="text-lg">{subEval?.plan?.name ?? 'Free Trial'}</CardTitle>
                                </CardHeader>
                                <CardContent className="text-muted-foreground text-xs">
                                    Status: <strong className="text-foreground uppercase">{subEval?.status}</strong>
                                </CardContent>
                            </Card>
                            <Card>
                                <CardHeader className="pb-2">
                                    <CardDescription>Monthly Syncs</CardDescription>
                                    <CardTitle className="font-mono text-lg">
                                        {subEval?.usage.sync_count ?? 0}
                                        {subEval?.max_monthly_syncs ? ` / ${subEval.max_monthly_syncs}` : ' / ∞'}
                                    </CardTitle>
                                </CardHeader>
                                <CardContent className="text-muted-foreground text-xs">
                                    API calls this month: {subEval?.usage.api_calls_count ?? 0}
                                </CardContent>
                            </Card>
                            <Card>
                                <CardHeader className="pb-2">
                                    <CardDescription>Schema Mode</CardDescription>
                                    <CardTitle className="text-lg capitalize">{tenant.fee_sync_mode.replace('_', ' ')}</CardTitle>
                                </CardHeader>
                                <CardContent className="text-muted-foreground text-xs">
                                    Catalog Iblock: #{tenant.catalog_config?.iblock_id ?? 'Not mapped'}
                                </CardContent>
                            </Card>
                            <Card>
                                <CardHeader className="pb-2">
                                    <CardDescription>CRM Bindings</CardDescription>
                                    <CardTitle className="text-lg">{tenant.entity_configs?.length ?? 0} Entities</CardTitle>
                                </CardHeader>
                                <CardContent className="text-muted-foreground text-xs">SPA buckets: {tenant.spa_buckets?.length ?? 0}</CardContent>
                            </Card>
                        </div>

                        <Card>
                            <CardHeader>
                                <CardTitle className="text-base">Bitrix24 Tab Placements & Webhooks</CardTitle>
                                <CardDescription>
                                    Re-bind CRM Detail Tab placements (`CRM_*_DETAIL_TAB`) and background sync events if tabs disappeared or new SPAs
                                    were added.
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="flex flex-wrap items-center justify-between gap-3">
                                <div className="flex flex-wrap gap-2">
                                    {(tenant.entity_configs ?? []).map((ec) => (
                                        <Badge key={ec.entity_type_id} variant={ec.is_enabled ? 'default' : 'secondary'}>
                                            {ec.title} ({ec.placement_code ?? ec.entity_type_id})
                                        </Badge>
                                    ))}
                                </div>
                                <Button onClick={handleSyncBindings} disabled={busyAction !== null || !jwt}>
                                    {busyAction === 'bindings' ? (
                                        <Loader2 className="mr-1.5 h-4 w-4 animate-spin" />
                                    ) : (
                                        <Link2 className="mr-1.5 h-4 w-4" />
                                    )}
                                    Repair Placements & Event Bindings
                                </Button>
                            </CardContent>
                        </Card>
                    </div>
                )}

                {/* Schema Tab */}
                {tab === 'schema' && jwt && (
                    <SchemaMapper
                        jwt={jwt}
                        tenant={tenant}
                        maxOptions={subEval?.max_options ?? 2}
                        features={subEval?.features ?? {}}
                        initialTab={tenant.schema_status === 'mapped' ? 'custom' : 'auto'}
                        onUpdated={(patch) => setTenant((prev) => (prev ? { ...prev, ...patch } : prev))}
                    />
                )}

                {/* Logs & Backfill Tab */}
                {tab === 'logs' && (
                    <div className="space-y-4">
                        <Card>
                            <CardHeader>
                                <CardTitle className="text-base">Queued Historical Backfill</CardTitle>
                                <CardDescription>
                                    Recalculate fee subtotals, entity fields, and linked SPA items for existing CRM records.
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="flex flex-wrap items-end gap-3">
                                <div className="space-y-1">
                                    <Label className="text-xs">CRM Entity</Label>
                                    <select
                                        value={backfillEntityId}
                                        onChange={(e) => setBackfillEntityId(Number(e.target.value))}
                                        className="border-input bg-background h-9 rounded-md border px-3 text-sm"
                                    >
                                        {(tenant.entity_configs?.length
                                            ? tenant.entity_configs
                                            : [
                                                  { entity_type_id: 2, title: 'Deals' },
                                                  { entity_type_id: 1, title: 'Leads' },
                                                  { entity_type_id: 7, title: 'Quotes' },
                                              ]
                                        ).map((ec) => (
                                            <option key={ec.entity_type_id} value={ec.entity_type_id}>
                                                {ec.title} (#{ec.entity_type_id})
                                            </option>
                                        ))}
                                    </select>
                                </div>
                                <div className="space-y-1">
                                    <Label className="text-xs">Record Batch Limit (1–250)</Label>
                                    <Input
                                        type="number"
                                        min="1"
                                        max="250"
                                        value={backfillLimit}
                                        onChange={(e) => setBackfillLimit(e.target.value)}
                                        className="h-9 w-32"
                                    />
                                </div>
                                <Button onClick={handleRunBackfill} disabled={busyAction !== null || !subEval?.features.allow_backfill}>
                                    {busyAction === 'backfill' ? (
                                        <Loader2 className="mr-1.5 h-4 w-4 animate-spin" />
                                    ) : (
                                        <Play className="mr-1.5 h-4 w-4" />
                                    )}
                                    Queue Backfill Job
                                </Button>
                                {!subEval?.features.allow_backfill && (
                                    <span className="text-xs text-amber-600">Requires Starter plan or higher.</span>
                                )}
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader className="flex flex-row items-center justify-between pb-3">
                                <div>
                                    <CardTitle className="text-base">Recent Sync Activity</CardTitle>
                                    <CardDescription>Latest 50 entity synchronizations for this portal.</CardDescription>
                                </div>
                                <Button variant="outline" size="sm" onClick={handleRefreshLogs} disabled={busyAction !== null}>
                                    <RefreshCw className="mr-1.5 h-3.5 w-3.5" />
                                    Refresh
                                </Button>
                            </CardHeader>
                            <CardContent className="p-0">
                                <div className="overflow-x-auto">
                                    <table className="w-full border-t text-xs">
                                        <thead className="bg-muted/50 text-left">
                                            <tr>
                                                <th className="px-3 py-2">Time</th>
                                                <th className="px-3 py-2">Record</th>
                                                <th className="px-3 py-2">Trigger</th>
                                                <th className="px-3 py-2">Status</th>
                                                <th className="px-3 py-2 text-right">Actions / API</th>
                                                <th className="px-3 py-2 text-right">Duration</th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y">
                                            {logs.length === 0 ? (
                                                <tr>
                                                    <td colSpan={6} className="text-muted-foreground py-6 text-center">
                                                        No sync activity logged yet.
                                                    </td>
                                                </tr>
                                            ) : (
                                                logs.map((log) => (
                                                    <tr key={log.id}>
                                                        <td className="text-muted-foreground px-3 py-2">
                                                            {new Date(log.created_at).toLocaleString()}
                                                        </td>
                                                        <td className="px-3 py-2 font-mono">
                                                            type={log.entity_type_id ?? '-'} #{log.entity_id ?? '-'}
                                                        </td>
                                                        <td className="px-3 py-2">{log.trigger_source}</td>
                                                        <td className="px-3 py-2">
                                                            <Badge
                                                                variant={
                                                                    log.status === 'success'
                                                                        ? 'default'
                                                                        : log.status === 'skipped'
                                                                          ? 'secondary'
                                                                          : 'destructive'
                                                                }
                                                            >
                                                                {log.status}
                                                            </Badge>
                                                        </td>
                                                        <td className="px-3 py-2 text-right font-mono">
                                                            {log.actions_count} / {log.api_calls_count}
                                                        </td>
                                                        <td className="px-3 py-2 text-right font-mono">{log.duration_ms ?? 0}ms</td>
                                                    </tr>
                                                ))
                                            )}
                                        </tbody>
                                    </table>
                                </div>
                            </CardContent>
                        </Card>
                    </div>
                )}

                {/* Subscription & Upgrade Requests Tab */}
                {tab === 'billing' && (
                    <div className="space-y-4">
                        <Card>
                            <CardHeader className="flex flex-row items-center justify-between">
                                <div>
                                    <CardTitle className="text-base">Choose Your Portal Plan</CardTitle>
                                    <CardDescription>
                                        Requesting a plan notifies our SaaS billing team to activate your subscription or send an invoice.
                                    </CardDescription>
                                </div>
                                <div className="flex items-center gap-1 rounded-md border p-1">
                                    <Button
                                        size="sm"
                                        variant={billingCycle === 'monthly' ? 'default' : 'ghost'}
                                        onClick={() => setBillingCycle('monthly')}
                                    >
                                        Monthly
                                    </Button>
                                    <Button
                                        size="sm"
                                        variant={billingCycle === 'yearly' ? 'default' : 'ghost'}
                                        onClick={() => setBillingCycle('yearly')}
                                    >
                                        Yearly
                                    </Button>
                                </div>
                            </CardHeader>
                            <CardContent className="grid gap-4 md:grid-cols-4">
                                {plans.map((p) => {
                                    const isCurrent = subEval?.plan?.id === p.id;
                                    const isRequested = subEval?.subscription.upgrade_requested_plan_id === p.id;
                                    const cents = billingCycle === 'yearly' ? p.price_yearly_cents : p.price_monthly_cents;
                                    return (
                                        <Card key={p.id} className={isCurrent ? 'border-indigo-600' : ''}>
                                            <CardHeader className="pb-2">
                                                <div className="flex items-center justify-between">
                                                    <CardTitle className="text-base">{p.name}</CardTitle>
                                                    {isCurrent && <Badge>Current</Badge>}
                                                </div>
                                                <div className="text-2xl font-bold">
                                                    {cents === 0 ? 'Free' : `$${(cents / 100).toFixed(0)}/${billingCycle === 'yearly' ? 'yr' : 'mo'}`}
                                                </div>
                                                <CardDescription className="text-xs">{p.description}</CardDescription>
                                            </CardHeader>
                                            <CardContent className="space-y-3 text-xs">
                                                <ul className="text-muted-foreground space-y-1">
                                                    <li>
                                                        • Syncs/mo: <strong className="text-foreground">{p.max_monthly_syncs ?? 'Unlimited'}</strong>
                                                    </li>
                                                    <li>
                                                        • Max Options: <strong className="text-foreground">{p.max_options}</strong>
                                                    </li>
                                                </ul>
                                                <Button
                                                    className="w-full"
                                                    variant={isCurrent ? 'outline' : 'default'}
                                                    disabled={busyAction !== null || isCurrent}
                                                    onClick={() => handleRequestUpgrade(p.id)}
                                                >
                                                    {busyAction === `upgrade-${p.id}` && <Loader2 className="mr-1.5 h-3.5 w-3.5 animate-spin" />}
                                                    {isCurrent ? 'Active Plan' : isRequested ? 'Upgrade Requested' : 'Request Plan'}
                                                </Button>
                                            </CardContent>
                                        </Card>
                                    );
                                })}
                            </CardContent>
                        </Card>
                    </div>
                )}
            </div>
        </div>
    );
}
