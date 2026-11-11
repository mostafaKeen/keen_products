import { FlashBanner } from '@/components/flash-banner';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';
import { Activity, ArrowUpRight, Building2, Clock, CreditCard, RefreshCw, Sparkles } from 'lucide-react';

interface DashboardProps {
    kpis: {
        total_portals: number;
        active_portals: number;
        uninstalled_portals: number;
        trialing: number;
        paid_active: number;
        expiring_soon: number;
        monthly_syncs: number;
        mrr_cents: number;
        recent_revenue_cents: number;
        pending_upgrades: number;
    };
    sync_series: Array<{
        date: string;
        label: string;
        success: number;
        failed: number;
        skipped: number;
    }>;
    upgrade_requests: Array<{
        id: number;
        tenant_id: number;
        billing_cycle: string;
        upgrade_requested_at: string | null;
        tenant?: { id: number; domain: string; member_id: string };
        plan?: { id: number; name: string };
        upgrade_requested_plan?: { id: number; name: string; price_monthly_cents: number };
    }>;
    recent_tenants: Array<{
        id: number;
        domain: string;
        member_id: string;
        install_status: string;
        schema_status: string;
        fee_sync_mode: string;
        plan_name: string | null;
        subscription_status: string | null;
        monthly_syncs: number;
        last_sync_at: string | null;
    }>;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Super Admin Overview', href: '/admin/dashboard' }];

const formatMoney = (cents: number) => new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(cents / 100);

export default function AdminDashboard({ kpis, sync_series, upgrade_requests, recent_tenants }: DashboardProps) {
    const maxDayTotal = Math.max(1, ...sync_series.map((d) => d.success + d.failed + d.skipped));

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Super Admin Overview" />
            <FlashBanner />

            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">Marketplace Control Center</h1>
                        <p className="text-muted-foreground text-sm">
                            Monitor Bitrix24 tenant portals, subscription health, revenue, and background sync volume.
                        </p>
                    </div>
                    <div className="flex items-center gap-2">
                        <Button variant="outline" asChild>
                            <Link href="/admin/plans">Manage Plans</Link>
                        </Button>
                        <Button asChild>
                            <Link href="/admin/tenants">Browse Portals</Link>
                        </Button>
                    </div>
                </div>

                {/* KPI Grid */}
                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between pb-2">
                            <CardTitle className="text-sm font-medium">Total Portals</CardTitle>
                            <Building2 className="text-muted-foreground h-4 w-4" />
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-bold">{kpis.total_portals}</div>
                            <p className="text-muted-foreground mt-1 text-xs">
                                {kpis.active_portals} active · {kpis.uninstalled_portals} uninstalled
                            </p>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between pb-2">
                            <CardTitle className="text-sm font-medium">Active Paid / MRR</CardTitle>
                            <CreditCard className="text-muted-foreground h-4 w-4" />
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-bold">{formatMoney(kpis.mrr_cents)}</div>
                            <p className="text-muted-foreground mt-1 text-xs">
                                {kpis.paid_active} paid · 30d collected {formatMoney(kpis.recent_revenue_cents)}
                            </p>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between pb-2">
                            <CardTitle className="text-sm font-medium">Trials &amp; Expiring</CardTitle>
                            <Clock className="text-muted-foreground h-4 w-4" />
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-bold">{kpis.trialing}</div>
                            <p className="text-muted-foreground mt-1 text-xs">
                                {kpis.expiring_soon} expiring / grace within 7d · {kpis.pending_upgrades} upgrade reqs
                            </p>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between pb-2">
                            <CardTitle className="text-sm font-medium">Monthly Syncs</CardTitle>
                            <RefreshCw className="text-muted-foreground h-4 w-4" />
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-bold">{kpis.monthly_syncs.toLocaleString()}</div>
                            <p className="text-muted-foreground mt-1 text-xs">Current billing month execution count</p>
                        </CardContent>
                    </Card>
                </div>

                {/* 14-day Sync Activity + Upgrade Requests */}
                <div className="grid gap-4 lg:grid-cols-3">
                    <Card className="lg:col-span-2">
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-base">
                                <Activity className="h-4 w-4" />
                                14-Day Sync Volume
                            </CardTitle>
                            <CardDescription>Daily entity &amp; SPA synchronization outcomes across all portals</CardDescription>
                        </CardHeader>
                        <CardContent>
                            <div className="flex h-48 items-end gap-2 pt-4">
                                {sync_series.map((day) => {
                                    const total = day.success + day.failed + day.skipped;
                                    const hPct = Math.round((total / maxDayTotal) * 100);
                                    const sPct = total > 0 ? Math.round((day.success / total) * 100) : 0;
                                    const fPct = total > 0 ? Math.round((day.failed / total) * 100) : 0;
                                    return (
                                        <div key={day.date} className="group flex flex-1 flex-col items-center gap-1.5">
                                            <div className="text-muted-foreground text-[10px] opacity-0 transition group-hover:opacity-100">
                                                {total}
                                            </div>
                                            <div className="bg-muted flex h-36 w-full flex-col justify-end overflow-hidden rounded-t">
                                                {total > 0 && (
                                                    <div style={{ height: `${Math.max(8, hPct)}%` }} className="flex w-full flex-col justify-end">
                                                        {day.failed > 0 && (
                                                            <div
                                                                style={{ height: `${Math.max(10, fPct)}%` }}
                                                                className="w-full bg-rose-500"
                                                                title={`Failed/Blocked: ${day.failed}`}
                                                            />
                                                        )}
                                                        {day.success > 0 && (
                                                            <div
                                                                style={{ height: `${Math.max(15, sPct)}%` }}
                                                                className="w-full bg-emerald-500"
                                                                title={`Success: ${day.success}`}
                                                            />
                                                        )}
                                                        {day.skipped > 0 && (
                                                            <div
                                                                className="w-full flex-1 bg-slate-400"
                                                                title={`Skipped (Hash Match): ${day.skipped}`}
                                                            />
                                                        )}
                                                    </div>
                                                )}
                                            </div>
                                            <span className="text-muted-foreground text-[10px]">{day.label.split(' ')[1]}</span>
                                        </div>
                                    );
                                })}
                            </div>
                            <div className="text-muted-foreground mt-4 flex items-center gap-4 text-xs">
                                <span className="flex items-center gap-1.5">
                                    <span className="inline-block h-2.5 w-2.5 rounded-full bg-emerald-500" /> Success
                                </span>
                                <span className="flex items-center gap-1.5">
                                    <span className="inline-block h-2.5 w-2.5 rounded-full bg-rose-500" /> Failed / Blocked
                                </span>
                                <span className="flex items-center gap-1.5">
                                    <span className="inline-block h-2.5 w-2.5 rounded-full bg-slate-400" /> Skipped (Hash Match)
                                </span>
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-base">
                                <Sparkles className="h-4 w-4 text-amber-500" />
                                Upgrade Requests
                            </CardTitle>
                            <CardDescription>Portals waiting for plan activation or invoice confirmation</CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            {upgrade_requests.length === 0 ? (
                                <p className="text-muted-foreground py-6 text-center text-sm">No pending upgrade requests right now.</p>
                            ) : (
                                upgrade_requests.map((req) => (
                                    <div key={req.id} className="flex items-center justify-between gap-2 rounded-lg border p-3">
                                        <div className="min-w-0">
                                            <p className="truncate text-sm font-medium">{req.tenant?.domain}</p>
                                            <p className="text-muted-foreground text-xs">
                                                {req.plan?.name} →{' '}
                                                <span className="text-foreground font-medium">{req.upgrade_requested_plan?.name}</span> (
                                                {req.billing_cycle})
                                            </p>
                                        </div>
                                        <Button size="sm" variant="secondary" asChild>
                                            <Link href={`/admin/tenants/${req.tenant_id}`}>
                                                Review <ArrowUpRight className="ml-1 h-3 w-3" />
                                            </Link>
                                        </Button>
                                    </div>
                                ))
                            )}
                        </CardContent>
                    </Card>
                </div>

                {/* Recent Portals Table */}
                <Card>
                    <CardHeader className="flex flex-row items-center justify-between">
                        <div>
                            <CardTitle className="text-base">Recent Bitrix24 Portals</CardTitle>
                            <CardDescription>Latest installed and active tenants</CardDescription>
                        </div>
                        <Button variant="ghost" size="sm" asChild>
                            <Link href="/admin/tenants">View All</Link>
                        </Button>
                    </CardHeader>
                    <CardContent>
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="text-muted-foreground border-b text-left text-xs uppercase">
                                        <th className="py-2 pr-4">Portal Domain</th>
                                        <th className="py-2 pr-4">Install</th>
                                        <th className="py-2 pr-4">Schema / Mode</th>
                                        <th className="py-2 pr-4">Plan &amp; Status</th>
                                        <th className="py-2 pr-4 text-right">Month Syncs</th>
                                        <th className="py-2 text-right">Action</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {recent_tenants.length === 0 ? (
                                        <tr>
                                            <td colSpan={6} className="text-muted-foreground py-8 text-center">
                                                No Bitrix24 portals installed yet. Point a Bitrix24 portal install URL to <code>/bitrix/install</code>{' '}
                                                to register the first tenant.
                                            </td>
                                        </tr>
                                    ) : (
                                        recent_tenants.map((t) => (
                                            <tr key={t.id}>
                                                <td className="py-2.5 pr-4">
                                                    <div className="font-medium">{t.domain}</div>
                                                    <div className="text-muted-foreground font-mono text-xs">{t.member_id}</div>
                                                </td>
                                                <td className="py-2.5 pr-4">
                                                    <Badge variant={t.install_status === 'active' ? 'default' : 'secondary'}>
                                                        {t.install_status}
                                                    </Badge>
                                                </td>
                                                <td className="py-2.5 pr-4">
                                                    <span className="text-xs">
                                                        {t.schema_status} · {t.fee_sync_mode}
                                                    </span>
                                                </td>
                                                <td className="py-2.5 pr-4">
                                                    <div className="text-xs font-medium">{t.plan_name ?? '—'}</div>
                                                    <div className="text-muted-foreground text-xs">{t.subscription_status ?? 'none'}</div>
                                                </td>
                                                <td className="py-2.5 pr-4 text-right font-mono">{t.monthly_syncs}</td>
                                                <td className="py-2.5 text-right">
                                                    <Button size="sm" variant="outline" asChild>
                                                        <Link href={`/admin/tenants/${t.id}`}>Inspect</Link>
                                                    </Button>
                                                </td>
                                            </tr>
                                        ))
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
