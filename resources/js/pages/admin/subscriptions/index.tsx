import { FlashBanner } from '@/components/flash-banner';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';

interface SubscriptionsProps {
    subscriptions: {
        data: Array<{
            id: number;
            tenant_id: number;
            seats_count?: number;
            status: string;
            billing_cycle: string;
            trial_ends_at: string | null;
            current_period_end: string | null;
            tenant?: { id: number; domain: string; member_id: string; install_status: string };
            plan?: { id: number; name: string; price_monthly_cents: number; currency: string; billing_type?: string; price_per_user_cents?: number };
            upgrade_requested_plan?: { id: number; name: string } | null;
        }>;
    };
    recent_payments: Array<{
        id: number;
        amount_cents: number;
        currency: string;
        provider: string;
        invoice_number: string | null;
        paid_at: string | null;
        tenant?: { id: number; domain: string };
        plan?: { id: number; name: string };
    }>;
    filter_status: string;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Overview', href: '/admin/dashboard' },
    { title: 'Subscriptions & Ledger', href: '/admin/subscriptions' },
];

const STATUS_FILTERS = [
    ['', 'All'],
    ['upgrade_requests', 'Upgrade Requests'],
    ['trialing', 'Trialing'],
    ['active', 'Active'],
    ['grace_period', 'Grace Period'],
    ['expired', 'Expired'],
    ['past_due', 'Past Due'],
    ['suspended', 'Suspended'],
];

export default function AdminSubscriptionsIndex({ subscriptions, recent_payments, filter_status }: SubscriptionsProps) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Subscriptions & Billing Ledger" />
            <FlashBanner />

            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <div>
                    <h1 className="text-2xl font-semibold tracking-tight">Subscriptions &amp; Payment Ledger</h1>
                    <p className="text-muted-foreground text-sm">Manage active tenant trials, renewals, upgrade requests, and recorded invoices.</p>
                </div>

                <div className="flex flex-wrap gap-2">
                    {STATUS_FILTERS.map(([val, label]) => (
                        <Button
                            key={val || 'all'}
                            size="sm"
                            variant={filter_status === val ? 'default' : 'outline'}
                            onClick={() => router.get('/admin/subscriptions', val ? { status: val } : {})}
                        >
                            {label}
                        </Button>
                    ))}
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Tenant Subscriptions</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="text-muted-foreground border-b text-left text-xs uppercase">
                                        <th className="py-2 pr-4">Portal</th>
                                        <th className="py-2 pr-4">Plan</th>
                                        <th className="py-2 pr-4">Status</th>
                                        <th className="py-2 pr-4">Period / Trial End</th>
                                        <th className="py-2 pr-4">Upgrade Request</th>
                                        <th className="py-2 text-right">Action</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {subscriptions.data.length === 0 ? (
                                        <tr>
                                            <td colSpan={6} className="text-muted-foreground py-8 text-center">
                                                No subscriptions match this filter.
                                            </td>
                                        </tr>
                                    ) : (
                                        subscriptions.data.map((s) => (
                                            <tr key={s.id}>
                                                <td className="py-2.5 pr-4 font-medium">{s.tenant?.domain}</td>
                                                <td className="py-2.5 pr-4">
                                                    <div>{s.plan?.name} ({s.billing_cycle})</div>
                                                    {s.seats_count && s.seats_count > 1 && (
                                                        <div className="text-muted-foreground text-xs">{s.seats_count} seats ($5/user)</div>
                                                    )}
                                                </td>
                                                <td className="py-2.5 pr-4">
                                                    <Badge variant={s.status === 'active' ? 'default' : s.status === 'suspended' ? 'destructive' : 'secondary'}>{s.status}</Badge>
                                                </td>
                                                <td className="py-2.5 pr-4 text-xs">
                                                    {(s.status === 'trialing' ? s.trial_ends_at : s.current_period_end)?.slice(0, 10) ?? '—'}
                                                </td>
                                                <td className="py-2.5 pr-4 text-xs">
                                                    {s.upgrade_requested_plan ? (
                                                        <Badge variant="outline">{s.upgrade_requested_plan.name}</Badge>
                                                    ) : (
                                                        '—'
                                                    )}
                                                </td>
                                                <td className="py-2.5 text-right space-x-1.5 whitespace-nowrap">
                                                    {s.status === 'active' ? (
                                                        <Button
                                                            size="sm"
                                                            variant="destructive"
                                                            onClick={() => router.post(`/admin/tenants/${s.tenant_id}/subscription/deactivate`)}
                                                        >
                                                            Deactivate
                                                        </Button>
                                                    ) : (
                                                        <Button
                                                            size="sm"
                                                            variant="default"
                                                            className="bg-emerald-600 hover:bg-emerald-700 text-white"
                                                            onClick={() => router.post(`/admin/tenants/${s.tenant_id}/subscription/activate`)}
                                                        >
                                                            Activate
                                                        </Button>
                                                    )}
                                                    <Button size="sm" variant="outline" asChild>
                                                        <Link href={`/admin/tenants/${s.tenant_id}`}>Manage</Link>
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

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Recent Recorded Payments</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="text-muted-foreground border-b text-left text-xs uppercase">
                                        <th className="py-2 pr-4">Invoice</th>
                                        <th className="py-2 pr-4">Portal</th>
                                        <th className="py-2 pr-4">Plan</th>
                                        <th className="py-2 pr-4">Provider</th>
                                        <th className="py-2 text-right">Amount</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {recent_payments.length === 0 ? (
                                        <tr>
                                            <td colSpan={5} className="text-muted-foreground py-6 text-center">
                                                No payments recorded yet.
                                            </td>
                                        </tr>
                                    ) : (
                                        recent_payments.map((p) => (
                                            <tr key={p.id}>
                                                <td className="py-2 pr-4 font-mono text-xs">{p.invoice_number}</td>
                                                <td className="py-2 pr-4">{p.tenant?.domain}</td>
                                                <td className="py-2 pr-4">{p.plan?.name}</td>
                                                <td className="py-2 pr-4 text-xs uppercase">{p.provider}</td>
                                                <td className="py-2 text-right font-semibold">
                                                    {(p.amount_cents / 100).toFixed(2)} {p.currency}
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
