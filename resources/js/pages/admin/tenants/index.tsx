import { FlashBanner } from '@/components/flash-banner';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { Search } from 'lucide-react';
import { FormEvent, useState } from 'react';

interface TenantListItem {
    id: number;
    member_id: string;
    domain: string;
    install_status: string;
    app_status: string;
    schema_status: string;
    fee_sync_mode: string;
    license_family: string | null;
    license_type: string | null;
    installed_at: string | null;
    last_sync_at: string | null;
    monthly_syncs: number;
    max_monthly_syncs: number | null;
    subscription: {
        id: number;
        status: string;
        billing_cycle: string;
        plan_name: string | null;
        trial_ends_at: string | null;
        current_period_end: string | null;
        upgrade_requested_plan: string | null;
    } | null;
}

interface TenantsIndexProps {
    tenants: {
        data: TenantListItem[];
        current_page: number;
        last_page: number;
        prev_page_url: string | null;
        next_page_url: string | null;
    };
    filters: {
        search: string;
        install_status: string;
        subscription_status: string;
    };
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Overview', href: '/admin/dashboard' },
    { title: 'Portals (Tenants)', href: '/admin/tenants' },
];

export default function AdminTenantsIndex({ tenants, filters }: TenantsIndexProps) {
    const [search, setSearch] = useState(filters.search ?? '');
    const [installStatus, setInstallStatus] = useState(filters.install_status ?? '');
    const [subStatus, setSubStatus] = useState(filters.subscription_status ?? '');

    const applyFilters = (e: FormEvent) => {
        e.preventDefault();
        router.get('/admin/tenants', { search, install_status: installStatus, subscription_status: subStatus }, { preserveState: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Portals (Tenants)" />
            <FlashBanner />

            <div className="flex flex-1 flex-col gap-5 p-4 md:p-6">
                <div>
                    <h1 className="text-2xl font-semibold tracking-tight">Bitrix24 Tenant Portals</h1>
                    <p className="text-muted-foreground text-sm">
                        Every installed Bitrix24 account keyed by immutable <code>member_id</code>.
                    </p>
                </div>

                <Card>
                    <CardHeader>
                        <form onSubmit={applyFilters} className="flex flex-wrap items-center gap-3">
                            <div className="relative min-w-[240px] flex-1">
                                <Search className="text-muted-foreground absolute top-2.5 left-2.5 h-4 w-4" />
                                <Input
                                    value={search}
                                    onChange={(e) => setSearch(e.target.value)}
                                    placeholder="Search domain or member_id..."
                                    className="pl-8"
                                />
                            </div>
                            <select
                                value={installStatus}
                                onChange={(e) => setInstallStatus(e.target.value)}
                                className="border-input bg-background h-9 rounded-md border px-3 text-sm"
                            >
                                <option value="">All install statuses</option>
                                <option value="active">Active</option>
                                <option value="installing">Installing</option>
                                <option value="uninstalled">Uninstalled</option>
                                <option value="suspended">Suspended</option>
                            </select>
                            <select
                                value={subStatus}
                                onChange={(e) => setSubStatus(e.target.value)}
                                className="border-input bg-background h-9 rounded-md border px-3 text-sm"
                            >
                                <option value="">All subscription statuses</option>
                                <option value="trialing">Trialing</option>
                                <option value="active">Active (Paid)</option>
                                <option value="grace_period">Grace Period</option>
                                <option value="expired">Expired</option>
                                <option value="past_due">Past Due</option>
                                <option value="suspended">Suspended</option>
                            </select>
                            <Button type="submit">Filter</Button>
                        </form>
                    </CardHeader>
                    <CardContent>
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="text-muted-foreground border-b text-left text-xs uppercase">
                                        <th className="py-2 pr-4">Portal</th>
                                        <th className="py-2 pr-4">Status</th>
                                        <th className="py-2 pr-4">Schema &amp; Mode</th>
                                        <th className="py-2 pr-4">Subscription</th>
                                        <th className="py-2 pr-4">Usage (Month)</th>
                                        <th className="py-2 text-right">Manage</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {tenants.data.length === 0 ? (
                                        <tr>
                                            <td colSpan={6} className="text-muted-foreground py-8 text-center">
                                                No portals match the current filter.
                                            </td>
                                        </tr>
                                    ) : (
                                        tenants.data.map((t) => (
                                            <tr key={t.id}>
                                                <td className="py-3 pr-4">
                                                    <div className="font-medium">{t.domain}</div>
                                                    <div className="text-muted-foreground font-mono text-xs">{t.member_id}</div>
                                                </td>
                                                <td className="py-3 pr-4">
                                                    <Badge
                                                        variant={
                                                            t.install_status === 'active'
                                                                ? 'default'
                                                                : t.install_status === 'suspended'
                                                                  ? 'destructive'
                                                                  : 'secondary'
                                                        }
                                                    >
                                                        {t.install_status}
                                                    </Badge>
                                                    {t.license_type && <div className="text-muted-foreground mt-1 text-xs">{t.license_type}</div>}
                                                </td>
                                                <td className="py-3 pr-4 text-xs">
                                                    <div>{t.schema_status}</div>
                                                    <div className="text-muted-foreground">{t.fee_sync_mode}</div>
                                                </td>
                                                <td className="py-3 pr-4">
                                                    <div className="font-medium">{t.subscription?.plan_name ?? '—'}</div>
                                                    <div className="text-muted-foreground text-xs">
                                                        {t.subscription?.status ?? 'none'}
                                                        {t.subscription?.upgrade_requested_plan && (
                                                            <span className="ml-1 font-medium text-amber-600">
                                                                (Upgrade → {t.subscription.upgrade_requested_plan})
                                                            </span>
                                                        )}
                                                    </div>
                                                </td>
                                                <td className="py-3 pr-4 font-mono text-xs">
                                                    {t.monthly_syncs} / {t.max_monthly_syncs ?? '∞'}
                                                </td>
                                                <td className="py-3 text-right">
                                                    <Button size="sm" variant="outline" asChild>
                                                        <Link href={`/admin/tenants/${t.id}`}>Open Inspector</Link>
                                                    </Button>
                                                </td>
                                            </tr>
                                        ))
                                    )}
                                </tbody>
                            </table>
                        </div>

                        {tenants.last_page > 1 && (
                            <div className="mt-4 flex items-center justify-between">
                                <span className="text-muted-foreground text-xs">
                                    Page {tenants.current_page} of {tenants.last_page}
                                </span>
                                <div className="flex gap-2">
                                    {tenants.prev_page_url && (
                                        <Button size="sm" variant="outline" asChild>
                                            <Link href={tenants.prev_page_url}>Previous</Link>
                                        </Button>
                                    )}
                                    {tenants.next_page_url && (
                                        <Button size="sm" variant="outline" asChild>
                                            <Link href={tenants.next_page_url}>Next</Link>
                                        </Button>
                                    )}
                                </div>
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
