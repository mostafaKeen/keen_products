import { FlashBanner } from '@/components/flash-banner';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import { Activity, CheckCircle2, CreditCard, RefreshCw, ShieldAlert, Users, UserCheck, Play, Pause, Plus, Trash2, Search } from 'lucide-react';
import { FormEvent, useState } from 'react';

interface Plan {
    id: number;
    code: string;
    name: string;
    price_monthly_cents: number;
    price_yearly_cents: number;
    currency: string;
    billing_type?: string;
    price_per_user_cents?: number | null;
}

interface BitrixUser {
    id: number;
    name: string;
    email: string;
    work_position?: string;
    active?: boolean;
}

interface TenantShowProps {
    tenant: {
        id: number;
        member_id: string;
        domain: string;
        client_endpoint: string;
        app_status: string;
        install_status: string;
        license_family: string | null;
        license_type: string | null;
        language_id: string;
        installer_name: string | null;
        installer_b24_user_id: number | null;
        allowed_b24_user_ids: number[] | null;
        cached_b24_users?: BitrixUser[];
        fee_sync_mode: string;
        schema_status: string;
        installed_at: string | null;
        uninstalled_at: string | null;
        token_expires_at: string | null;
        last_api_call_at: string | null;
        last_sync_at: string | null;
        has_tokens: boolean;
        catalog_config: {
            iblock_id: number | null;
            catalog_name: string | null;
            cost_type_prop_code: string | null;
            option_prop_code: string | null;
            payments_prop_code: string | null;
        } | null;
        spa_buckets: Array<{
            id: number;
            bucket_key: string;
            title: string;
            spa_entity_type_id: number | null;
            field_map_json: Record<string, string> | null;
        }>;
        entity_configs: Array<{
            id: number;
            logical_key: string;
            title: string;
            entity_type_id: number;
            owner_type_code: string;
            is_enabled: boolean;
            auto_sync_enabled: boolean;
            field_mappings: Array<{
                id: number;
                role: string;
                role_qualifier: string;
                b24_field_code: string;
            }>;
        }>;
        enum_mappings_count: number;
        payments: Array<{
            id: number;
            amount_cents: number;
            currency: string;
            status: string;
            provider: string;
            invoice_number: string | null;
            reference: string | null;
            paid_at: string | null;
        }>;
    };
    subscription: {
        id: number;
        plan_id: number;
        seats_count: number;
        calculated_price_monthly_cents?: number;
        status: string;
        billing_cycle: string;
        billing_provider: string;
        trial_ends_at: string | null;
        current_period_end: string | null;
        grace_ends_at: string | null;
        custom_limits_json: { max_monthly_syncs?: number; max_options?: number } | null;
        admin_notes: string | null;
        upgrade_requested_plan: { id: number; name: string } | null;
        plan: Plan | null;
        usage: { sync_count: number; api_calls_count: number; webhook_count: number };
        max_monthly_syncs: number | null;
        max_options: number;
    };
    usage_history: Array<{ year_month: string; sync_count: number; api_calls_count: number; webhook_count: number }>;
    sync_logs: Array<{
        id: number;
        entity_type_id: number | null;
        entity_id: number | null;
        trigger_source: string;
        status: string;
        duration_ms: number;
        api_calls_count: number;
        errors_json: string[] | null;
        created_at: string;
    }>;
    plans: Plan[];
}

export default function AdminTenantShow({ tenant, subscription, usage_history, sync_logs, plans }: TenantShowProps) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Overview', href: '/admin/dashboard' },
        { title: 'Portals', href: '/admin/tenants' },
        { title: tenant.domain, href: `/admin/tenants/${tenant.id}` },
    ];

    const subForm = useForm({
        plan_id: subscription.plan_id,
        seats_count: subscription.seats_count || 1,
        status: subscription.status,
        billing_cycle: subscription.billing_cycle,
        trial_ends_at: subscription.trial_ends_at ? subscription.trial_ends_at.slice(0, 16) : '',
        current_period_end: subscription.current_period_end ? subscription.current_period_end.slice(0, 16) : '',
        max_monthly_syncs_override: subscription.custom_limits_json?.max_monthly_syncs ?? ('' as string | number),
        max_options_override: subscription.custom_limits_json?.max_options ?? ('' as string | number),
        admin_notes: subscription.admin_notes ?? '',
        clear_upgrade_request: false as boolean,
    });

    const allowedUsersForm = useForm({
        restrict_users: tenant.allowed_b24_user_ids !== null,
        allowed_b24_user_ids: (tenant.allowed_b24_user_ids ?? []) as number[],
        seats_count: subscription.seats_count || 1,
    });

    const [userSearch, setUserSearch] = useState('');
    const [manualIdInput, setManualIdInput] = useState('');

    const activateSubscription = () => {
        router.post(`/admin/tenants/${tenant.id}/subscription/activate`);
    };

    const deactivateSubscription = () => {
        router.post(`/admin/tenants/${tenant.id}/subscription/deactivate`);
    };

    const fetchBitrixUsers = () => {
        router.post(`/admin/tenants/${tenant.id}/fetch-bitrix-users`);
    };

    const saveAllowedUsers = (e: FormEvent) => {
        e.preventDefault();
        allowedUsersForm.put(`/admin/tenants/${tenant.id}/allowed-users`);
    };

    const isUserSelected = (id: number) => {
        return allowedUsersForm.data.allowed_b24_user_ids.includes(id);
    };

    const toggleUser = (id: number) => {
        const current = [...allowedUsersForm.data.allowed_b24_user_ids];
        const idx = current.indexOf(id);
        if (idx >= 0) {
            current.splice(idx, 1);
        } else {
            current.push(id);
        }
        allowedUsersForm.setData({
            ...allowedUsersForm.data,
            allowed_b24_user_ids: current,
            seats_count: Math.max(allowedUsersForm.data.seats_count, current.length || 1),
        });
    };

    const addManualUserId = () => {
        const parsed = parseInt(manualIdInput.trim(), 10);
        if (!isNaN(parsed) && parsed > 0 && !allowedUsersForm.data.allowed_b24_user_ids.includes(parsed)) {
            const next = [...allowedUsersForm.data.allowed_b24_user_ids, parsed];
            allowedUsersForm.setData({
                ...allowedUsersForm.data,
                allowed_b24_user_ids: next,
                seats_count: Math.max(allowedUsersForm.data.seats_count, next.length || 1),
            });
            setManualIdInput('');
        }
    };

    const selectAllUsers = (usersList: BitrixUser[]) => {
        const allIds = Array.from(new Set([...allowedUsersForm.data.allowed_b24_user_ids, ...usersList.map((u) => u.id)]));
        allowedUsersForm.setData({
            ...allowedUsersForm.data,
            allowed_b24_user_ids: allIds,
            seats_count: Math.max(allowedUsersForm.data.seats_count, allIds.length || 1),
        });
    };

    const clearAllUsers = () => {
        allowedUsersForm.setData({
            ...allowedUsersForm.data,
            allowed_b24_user_ids: [],
        });
    };

    const payForm = useForm({
        plan_id: subscription.upgrade_requested_plan?.id ?? subscription.plan_id,
        amount_cents: subscription.plan?.price_monthly_cents ?? 4900,
        currency: subscription.plan?.currency ?? 'USD',
        billing_cycle: 'monthly',
        provider: 'manual',
        reference: '',
        invoice_number: '',
        notes: '',
    });

    const saveSubscription = (e: FormEvent) => {
        e.preventDefault();
        subForm.transform((d) => ({
            ...d,
            seats_count: Number(d.seats_count || 1),
            trial_ends_at: d.trial_ends_at || null,
            current_period_end: d.current_period_end || null,
            max_monthly_syncs_override: d.max_monthly_syncs_override === '' ? null : Number(d.max_monthly_syncs_override),
            max_options_override: d.max_options_override === '' ? null : Number(d.max_options_override),
        }));
        subForm.put(`/admin/tenants/${tenant.id}/subscription`);
    };

    const submitPayment = (e: FormEvent) => {
        e.preventDefault();
        payForm.post(`/admin/tenants/${tenant.id}/payments`, {
            onSuccess: () => payForm.reset('reference', 'invoice_number', 'notes'),
        });
    };

    const togglePortalSuspend = () => {
        const nextStatus = tenant.install_status === 'suspended' ? 'active' : 'suspended';
        router.patch(`/admin/tenants/${tenant.id}/status`, { install_status: nextStatus });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Portal: ${tenant.domain}`} />
            <FlashBanner />

            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <div className="flex items-center gap-2.5">
                            <h1 className="text-2xl font-semibold tracking-tight">{tenant.domain}</h1>
                            <Badge variant={tenant.install_status === 'active' ? 'default' : 'destructive'}>{tenant.install_status}</Badge>
                            <Badge variant={subscription.status === 'active' ? 'default' : subscription.status === 'suspended' ? 'destructive' : 'secondary'}>
                                Sub: {subscription.status}
                            </Badge>
                            {tenant.license_type && <Badge variant="outline">{tenant.license_type}</Badge>}
                        </div>
                        <p className="text-muted-foreground mt-1 font-mono text-xs">
                            member_id: {tenant.member_id} · endpoint: {tenant.client_endpoint}
                        </p>
                    </div>

                    <div className="flex items-center gap-2">
                        {subscription.status === 'active' ? (
                            <Button variant="destructive" onClick={deactivateSubscription}>
                                <Pause className="mr-1.5 h-4 w-4" /> Deactivate Subscription
                            </Button>
                        ) : (
                            <Button variant="default" className="bg-emerald-600 hover:bg-emerald-700 text-white" onClick={activateSubscription}>
                                <Play className="mr-1.5 h-4 w-4" /> Activate Subscription
                            </Button>
                        )}
                        <Button variant="outline" onClick={() => router.post(`/admin/tenants/${tenant.id}/probe`)}>
                            <RefreshCw className="mr-1.5 h-4 w-4" /> Live Probe (app.info)
                        </Button>
                        <Button variant={tenant.install_status === 'suspended' ? 'default' : 'destructive'} onClick={togglePortalSuspend}>
                            <ShieldAlert className="mr-1.5 h-4 w-4" />
                            {tenant.install_status === 'suspended' ? 'Reactivate Portal' : 'Suspend Portal'}
                        </Button>
                    </div>
                </div>

                {subscription.upgrade_requested_plan && (
                    <Card className="border-amber-300 bg-amber-50/70 dark:border-amber-700 dark:bg-amber-950/30">
                        <CardContent className="flex flex-wrap items-center justify-between gap-3 py-4">
                            <div className="text-sm">
                                <strong>Pending Upgrade Request:</strong> This portal requested{' '}
                                <span className="font-semibold">{subscription.upgrade_requested_plan.name}</span>. Record payment below or change plan
                                to fulfill.
                            </div>
                            <Button
                                size="sm"
                                variant="secondary"
                                onClick={() => {
                                    subForm.setData({
                                        ...subForm.data,
                                        plan_id: subscription.upgrade_requested_plan!.id,
                                        status: 'active',
                                        clear_upgrade_request: true,
                                    });
                                }}
                            >
                                Pre-fill Requested Plan
                            </Button>
                        </CardContent>
                    </Card>
                )}

                {/* Top 2 columns: Subscription Management + Payment Recorder */}
                <div className="grid gap-6 lg:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-base">
                                <CheckCircle2 className="h-4 w-4" />
                                Subscription &amp; Quota Controls
                            </CardTitle>
                            <CardDescription>
                                Month syncs: <strong>{subscription.usage.sync_count}</strong> /{' '}
                                <strong>{subscription.max_monthly_syncs ?? 'Unlimited'}</strong> · API calls:{' '}
                                <strong>{subscription.usage.api_calls_count}</strong>
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <form onSubmit={saveSubscription} className="space-y-4">
                                <div className="grid grid-cols-4 gap-3">
                                    <div>
                                        <Label>Plan</Label>
                                        <select
                                            value={subForm.data.plan_id}
                                            onChange={(e) => subForm.setData('plan_id', Number(e.target.value))}
                                            className="border-input bg-background mt-1 h-9 w-full rounded-md border px-2 text-sm"
                                        >
                                            {plans.map((p) => (
                                                <option key={p.id} value={p.id}>
                                                    {p.name}
                                                </option>
                                            ))}
                                        </select>
                                    </div>
                                    <div>
                                        <Label>Seats ($5/ea)</Label>
                                        <Input
                                            type="number"
                                            min={1}
                                            value={subForm.data.seats_count}
                                            onChange={(e) => subForm.setData('seats_count', Math.max(1, Number(e.target.value)))}
                                        />
                                    </div>
                                    <div>
                                        <Label>Status</Label>
                                        <select
                                            value={subForm.data.status}
                                            onChange={(e) => subForm.setData('status', e.target.value)}
                                            className="border-input bg-background mt-1 h-9 w-full rounded-md border px-2 text-sm"
                                        >
                                            <option value="trialing">Trialing</option>
                                            <option value="active">Active</option>
                                            <option value="grace_period">Grace Period</option>
                                            <option value="past_due">Past Due</option>
                                            <option value="expired">Expired</option>
                                            <option value="suspended">Suspended</option>
                                            <option value="canceled">Canceled</option>
                                        </select>
                                    </div>
                                    <div>
                                        <Label>Cycle</Label>
                                        <select
                                            value={subForm.data.billing_cycle}
                                            onChange={(e) => subForm.setData('billing_cycle', e.target.value)}
                                            className="border-input bg-background mt-1 h-9 w-full rounded-md border px-2 text-sm"
                                        >
                                            <option value="monthly">Monthly</option>
                                            <option value="yearly">Yearly</option>
                                            <option value="manual">Manual</option>
                                        </select>
                                    </div>
                                </div>

                                <div className="grid grid-cols-2 gap-3">
                                    <div>
                                        <Label>Trial Ends At</Label>
                                        <Input
                                            type="datetime-local"
                                            value={subForm.data.trial_ends_at}
                                            onChange={(e) => subForm.setData('trial_ends_at', e.target.value)}
                                        />
                                    </div>
                                    <div>
                                        <Label>Current Period End</Label>
                                        <Input
                                            type="datetime-local"
                                            value={subForm.data.current_period_end}
                                            onChange={(e) => subForm.setData('current_period_end', e.target.value)}
                                        />
                                    </div>
                                </div>

                                <div className="grid grid-cols-2 gap-3">
                                    <div>
                                        <Label>Override Max Syncs/Mo</Label>
                                        <Input
                                            type="number"
                                            placeholder="Use plan default"
                                            value={subForm.data.max_monthly_syncs_override}
                                            onChange={(e) => subForm.setData('max_monthly_syncs_override', e.target.value)}
                                        />
                                    </div>
                                    <div>
                                        <Label>Override Max Options</Label>
                                        <Input
                                            type="number"
                                            placeholder="Use plan default"
                                            value={subForm.data.max_options_override}
                                            onChange={(e) => subForm.setData('max_options_override', e.target.value)}
                                        />
                                    </div>
                                </div>

                                <div>
                                    <Label>Admin Notes</Label>
                                    <Input
                                        value={subForm.data.admin_notes}
                                        onChange={(e) => subForm.setData('admin_notes', e.target.value)}
                                        placeholder="Contract, VIP terms, invoice PO..."
                                    />
                                </div>

                                <Button type="submit" disabled={subForm.processing}>
                                    Save Subscription Settings
                                </Button>
                            </form>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-base">
                                <CreditCard className="h-4 w-4" />
                                Record Payment &amp; Activate Period
                            </CardTitle>
                            <CardDescription>Logs a paid invoice in the billing ledger and advances subscription period immediately.</CardDescription>
                        </CardHeader>
                        <CardContent>
                            <form onSubmit={submitPayment} className="space-y-4">
                                <div className="grid grid-cols-3 gap-3">
                                    <div>
                                        <Label>Plan</Label>
                                        <select
                                            value={payForm.data.plan_id}
                                            onChange={(e) => payForm.setData('plan_id', Number(e.target.value))}
                                            className="border-input bg-background mt-1 h-9 w-full rounded-md border px-2 text-sm"
                                        >
                                            {plans.map((p) => (
                                                <option key={p.id} value={p.id}>
                                                    {p.name}
                                                </option>
                                            ))}
                                        </select>
                                    </div>
                                    <div>
                                        <Label>Amount (cents)</Label>
                                        <Input
                                            type="number"
                                            value={payForm.data.amount_cents}
                                            onChange={(e) => payForm.setData('amount_cents', Number(e.target.value))}
                                        />
                                    </div>
                                    <div>
                                        <Label>Cycle</Label>
                                        <select
                                            value={payForm.data.billing_cycle}
                                            onChange={(e) => payForm.setData('billing_cycle', e.target.value)}
                                            className="border-input bg-background mt-1 h-9 w-full rounded-md border px-2 text-sm"
                                        >
                                            <option value="monthly">Monthly (+1m)</option>
                                            <option value="yearly">Yearly (+1y)</option>
                                            <option value="manual">Manual</option>
                                        </select>
                                    </div>
                                </div>

                                <div className="grid grid-cols-3 gap-3">
                                    <div>
                                        <Label>Provider</Label>
                                        <select
                                            value={payForm.data.provider}
                                            onChange={(e) => payForm.setData('provider', e.target.value)}
                                            className="border-input bg-background mt-1 h-9 w-full rounded-md border px-2 text-sm"
                                        >
                                            <option value="manual">Manual / Invoice</option>
                                            <option value="wire">Wire Transfer</option>
                                            <option value="stripe">Stripe</option>
                                            <option value="paddle">Paddle</option>
                                        </select>
                                    </div>
                                    <div>
                                        <Label>Invoice #</Label>
                                        <Input
                                            value={payForm.data.invoice_number}
                                            placeholder="Auto-generated"
                                            onChange={(e) => payForm.setData('invoice_number', e.target.value)}
                                        />
                                    </div>
                                    <div>
                                        <Label>External Ref</Label>
                                        <Input
                                            value={payForm.data.reference}
                                            placeholder="TXN / Wire ref"
                                            onChange={(e) => payForm.setData('reference', e.target.value)}
                                        />
                                    </div>
                                </div>

                                <div>
                                    <Label>Payment Notes</Label>
                                    <Input value={payForm.data.notes} onChange={(e) => payForm.setData('notes', e.target.value)} />
                                </div>

                                <Button type="submit" disabled={payForm.processing}>
                                    Record Payment &amp; Activate
                                </Button>
                            </form>
                        </CardContent>
                    </Card>
                </div>

                {/* Bitrix24 User Access & Seat Allocation ($5/user) */}
                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2 text-base">
                            <Users className="h-4 w-4" />
                            Bitrix24 User Access &amp; Seat Allocation ($5/user)
                        </CardTitle>
                        <CardDescription>
                            Control which Bitrix24 portal users are licensed to use this application. When restriction is enabled, unselected users cannot access the app, settings, or CRM widgets.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <form onSubmit={saveAllowedUsers} className="space-y-4">
                            <div className="flex flex-wrap items-center justify-between gap-4 rounded-lg border p-4 bg-slate-50 dark:bg-slate-900">
                                <div className="space-y-0.5">
                                    <Label className="text-sm font-semibold">Enforce User-Level Access Restriction</Label>
                                    <p className="text-muted-foreground text-xs">
                                        When enabled, <strong>ONLY selected Bitrix24 users</strong> can access the application and CRM tab widgets. Other users are completely blocked.
                                    </p>
                                </div>
                                <div className="flex items-center gap-3">
                                    <input
                                        type="checkbox"
                                        id="restrict_users"
                                        checked={allowedUsersForm.data.restrict_users}
                                        onChange={(e) => allowedUsersForm.setData('restrict_users', e.target.checked)}
                                        className="h-5 w-5 rounded border-gray-300 text-primary focus:ring-primary cursor-pointer"
                                    />
                                    <Label htmlFor="restrict_users" className="cursor-pointer font-medium text-sm">
                                        {allowedUsersForm.data.restrict_users ? 'Restricted Mode (Selected Only)' : 'Unrestricted Mode (All Users)'}
                                    </Label>
                                </div>
                            </div>

                            <div className="flex flex-wrap items-center justify-between gap-3 border-b pb-3">
                                <div className="flex items-center gap-2">
                                    <Badge variant={allowedUsersForm.data.restrict_users ? 'default' : 'secondary'} className="text-xs">
                                        {allowedUsersForm.data.allowed_b24_user_ids.length} User(s) Assigned
                                    </Badge>
                                    <Badge variant="outline" className="text-xs">
                                        {allowedUsersForm.data.seats_count} Seats Licensed
                                    </Badge>
                                    <span className="text-xs font-semibold text-emerald-600 dark:text-emerald-400">
                                        ${(allowedUsersForm.data.seats_count * 5).toFixed(2)} USD / mo ($5/user)
                                    </span>
                                </div>
                                <div className="flex items-center gap-2">
                                    <div className="flex items-center gap-1.5">
                                        <Label className="text-xs whitespace-nowrap">Seats Count:</Label>
                                        <Input
                                            type="number"
                                            min={Math.max(1, allowedUsersForm.data.allowed_b24_user_ids.length)}
                                            value={allowedUsersForm.data.seats_count}
                                            onChange={(e) => allowedUsersForm.setData('seats_count', Math.max(1, Number(e.target.value)))}
                                            className="h-8 w-20 text-xs"
                                        />
                                    </div>
                                    <Button type="button" variant="outline" size="sm" onClick={fetchBitrixUsers}>
                                        <RefreshCw className="mr-1.5 h-3.5 w-3.5" /> Fetch Portal Users (user.get)
                                    </Button>
                                </div>
                            </div>

                            <div className="flex flex-wrap items-center justify-between gap-3 pt-1">
                                <div className="relative flex-1 min-w-[220px]">
                                    <Search className="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" />
                                    <Input
                                        type="text"
                                        placeholder="Filter by name, email, or Bitrix ID..."
                                        value={userSearch}
                                        onChange={(e) => setUserSearch(e.target.value)}
                                        className="pl-8 h-9 text-xs"
                                    />
                                </div>
                                <div className="flex items-center gap-2">
                                    <Input
                                        type="number"
                                        placeholder="Add User ID..."
                                        value={manualIdInput}
                                        onChange={(e) => setManualIdInput(e.target.value)}
                                        className="h-9 w-28 text-xs"
                                    />
                                    <Button type="button" size="sm" variant="outline" onClick={addManualUserId}>
                                        <Plus className="mr-1 h-3.5 w-3.5" /> Add ID
                                    </Button>
                                    <Button type="button" size="sm" variant="ghost" onClick={() => selectAllUsers(tenant.cached_b24_users ?? [])}>
                                        Select All
                                    </Button>
                                    <Button type="button" size="sm" variant="ghost" onClick={clearAllUsers}>
                                        Clear
                                    </Button>
                                </div>
                            </div>

                            {/* User List */}
                            <div className="max-h-72 overflow-y-auto rounded-md border divide-y text-xs">
                                {(() => {
                                    const cached: BitrixUser[] = tenant.cached_b24_users ?? [];
                                    const manualList = allowedUsersForm.data.allowed_b24_user_ids
                                        .filter((id) => !cached.some((u) => u.id === id))
                                        .map((id) => ({
                                            id,
                                            name: `Bitrix User #${id}`,
                                            email: 'Manually added seat',
                                            work_position: 'Custom User Seat',
                                            active: true,
                                        }));
                                    const combined = [...cached, ...manualList];
                                    const filtered = combined.filter((u) => {
                                        if (!userSearch) return true;
                                        const q = userSearch.toLowerCase();
                                        return (
                                            u.name.toLowerCase().includes(q) ||
                                            u.email.toLowerCase().includes(q) ||
                                            String(u.id).includes(q) ||
                                            (u.work_position && u.work_position.toLowerCase().includes(q))
                                        );
                                    });

                                    if (filtered.length === 0) {
                                        return (
                                            <div className="p-6 text-center text-muted-foreground">
                                                {combined.length === 0
                                                    ? 'No Bitrix24 users loaded yet. Click "Fetch Portal Users" above or enter a user ID manually.'
                                                    : 'No Bitrix24 users match your filter.'}
                                            </div>
                                        );
                                    }

                                    return filtered.map((u) => {
                                        const checked = isUserSelected(u.id);
                                        const isInstaller = tenant.installer_b24_user_id === u.id;
                                        return (
                                            <div
                                                key={u.id}
                                                onClick={() => toggleUser(u.id)}
                                                className={`flex items-center justify-between p-2.5 hover:bg-slate-100 dark:hover:bg-slate-800 cursor-pointer transition-colors ${
                                                    checked ? 'bg-emerald-50/70 dark:bg-emerald-950/20' : ''
                                                }`}
                                            >
                                                <div className="flex items-center gap-3">
                                                    <input
                                                        type="checkbox"
                                                        checked={checked}
                                                        onChange={() => {}}
                                                        className="h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary"
                                                    />
                                                    <div>
                                                        <div className="font-medium flex items-center gap-1.5">
                                                            <span>{u.name}</span>
                                                            {isInstaller && (
                                                                <Badge variant="outline" className="text-[10px] px-1 py-0 border-blue-400 text-blue-600">
                                                                    Installer
                                                                </Badge>
                                                            )}
                                                            {checked && (
                                                                <Badge variant="default" className="text-[10px] px-1 py-0 bg-emerald-600">
                                                                    Allowed
                                                                </Badge>
                                                            )}
                                                        </div>
                                                        <div className="text-muted-foreground text-[11px] flex gap-2">
                                                            {u.email && <span>{u.email}</span>}
                                                            {u.work_position && <span>· {u.work_position}</span>}
                                                        </div>
                                                    </div>
                                                </div>
                                                <div className="flex items-center gap-2">
                                                    <span className="font-mono text-muted-foreground text-[11px]">ID #{u.id}</span>
                                                </div>
                                            </div>
                                        );
                                    });
                                })()}
                            </div>

                            <div className="flex flex-wrap items-center justify-between gap-3 pt-2">
                                <span className="text-xs text-muted-foreground">
                                    {allowedUsersForm.data.restrict_users
                                        ? `${allowedUsersForm.data.allowed_b24_user_ids.length} of ${allowedUsersForm.data.seats_count} licensed seats assigned. Other users will be blocked from app and widgets.`
                                        : 'Restriction disabled: all users in this Bitrix24 portal can access the application.'}
                                </span>
                                <Button type="submit" disabled={allowedUsersForm.processing}>
                                    <UserCheck className="mr-1.5 h-4 w-4" /> Save User Access Permissions
                                </Button>
                            </div>
                        </form>
                    </CardContent>
                </Card>

                {/* Portal Dynamic Schema Inspector */}
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Dynamic Portal Schema &amp; Custom Field Mappings</CardTitle>
                        <CardDescription>
                            Mode: <strong>{tenant.fee_sync_mode}</strong> · Schema status: <strong>{tenant.schema_status}</strong> · Enum crosswalk
                            entries: <strong>{tenant.enum_mappings_count}</strong> · OAuth stored: <strong>{tenant.has_tokens ? 'Yes' : 'No'}</strong>
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="grid gap-4 md:grid-cols-2">
                        <div className="space-y-2 rounded-lg border p-3 text-xs">
                            <div className="font-semibold uppercase">Catalog &amp; Fee SPAs</div>
                            {tenant.catalog_config ? (
                                <div className="space-y-1">
                                    <div>
                                        iblock_id: <code>{tenant.catalog_config.iblock_id}</code>
                                    </div>
                                    <div>
                                        cost_type_prop: <code>{tenant.catalog_config.cost_type_prop_code ?? '—'}</code>
                                    </div>
                                    <div>
                                        option_prop: <code>{tenant.catalog_config.option_prop_code ?? '—'}</code>
                                    </div>
                                    <div>
                                        payments_prop: <code>{tenant.catalog_config.payments_prop_code ?? '—'}</code>
                                    </div>
                                </div>
                            ) : (
                                <p className="text-muted-foreground">Not provisioned yet.</p>
                            )}

                            <div className="pt-2 font-semibold uppercase">Fee SPA Buckets ({tenant.spa_buckets.length})</div>
                            {tenant.spa_buckets.map((b) => (
                                <div key={b.id} className="rounded bg-slate-50 p-2 dark:bg-slate-900">
                                    <strong>{b.bucket_key}</strong> → {b.title} (entityTypeId: <code>{b.spa_entity_type_id ?? '—'}</code>)
                                </div>
                            ))}
                        </div>

                        <div className="space-y-2 rounded-lg border p-3 text-xs">
                            <div className="font-semibold uppercase">Enabled CRM Entities ({tenant.entity_configs.length})</div>
                            {tenant.entity_configs.map((ec) => (
                                <div key={ec.id} className="rounded bg-slate-50 p-2 dark:bg-slate-900">
                                    <div className="flex justify-between font-medium">
                                        <span>
                                            {ec.title} (type {ec.entity_type_id} / {ec.owner_type_code})
                                        </span>
                                        <span>{ec.field_mappings.length} fields mapped</span>
                                    </div>
                                    <div className="text-muted-foreground mt-1 flex flex-wrap gap-1">
                                        {ec.field_mappings.map((m) => (
                                            <span key={m.id} className="rounded border px-1.5 py-0.5 font-mono text-[10px]">
                                                {m.role}:{m.role_qualifier}={m.b24_field_code}
                                            </span>
                                        ))}
                                    </div>
                                </div>
                            ))}
                        </div>
                    </CardContent>
                </Card>

                {/* Sync Logs & Payment Ledger */}
                <div className="grid gap-6 lg:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-base">
                                <Activity className="h-4 w-4" />
                                Recent Sync Logs
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="max-h-72 space-y-2 overflow-y-auto text-xs">
                                {sync_logs.length === 0 ? (
                                    <p className="text-muted-foreground py-4 text-center">No sync logs recorded yet.</p>
                                ) : (
                                    sync_logs.map((log) => (
                                        <div key={log.id} className="flex items-start justify-between rounded border p-2">
                                            <div>
                                                <div className="font-medium">
                                                    {log.trigger_source} · type {log.entity_type_id} #{log.entity_id}
                                                </div>
                                                {log.errors_json && log.errors_json.length > 0 && (
                                                    <div className="text-rose-600">{log.errors_json.join('; ')}</div>
                                                )}
                                                <div className="text-muted-foreground">
                                                    {log.duration_ms}ms · {log.api_calls_count} Bitrix API calls
                                                </div>
                                            </div>
                                            <Badge variant={log.status === 'success' ? 'default' : 'secondary'}>{log.status}</Badge>
                                        </div>
                                    ))
                                )}
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Monthly Usage &amp; Payments</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4 text-xs">
                            <div>
                                <div className="mb-1.5 font-semibold uppercase">Usage History</div>
                                {usage_history.map((u) => (
                                    <div key={u.year_month} className="flex justify-between border-b py-1">
                                        <span className="font-mono">{u.year_month}</span>
                                        <span>
                                            {u.sync_count} syncs · {u.api_calls_count} API calls · {u.webhook_count} webhooks
                                        </span>
                                    </div>
                                ))}
                            </div>
                            <div>
                                <div className="mb-1.5 font-semibold uppercase">Invoices / Payments</div>
                                {tenant.payments.length === 0 ? (
                                    <p className="text-muted-foreground">No payments logged yet.</p>
                                ) : (
                                    tenant.payments.map((pay) => (
                                        <div key={pay.id} className="flex justify-between border-b py-1.5">
                                            <span>
                                                {pay.invoice_number} ({pay.provider})
                                            </span>
                                            <span className="font-semibold">
                                                {(pay.amount_cents / 100).toFixed(2)} {pay.currency}
                                            </span>
                                        </div>
                                    ))
                                )}
                            </div>
                        </CardContent>
                    </Card>
                </div>
            </div>
        </AppLayout>
    );
}
