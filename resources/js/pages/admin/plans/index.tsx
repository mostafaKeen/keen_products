import { FlashBanner } from '@/components/flash-banner';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { FormEvent, useState } from 'react';

interface PlanItem {
    id: number;
    code: string;
    name: string;
    description: string | null;
    billing_type?: string;
    price_per_user_cents?: number | null;
    price_monthly_cents: number;
    price_yearly_cents: number;
    currency: string;
    trial_days: number;
    max_monthly_syncs: number | null;
    max_options: number;
    sort_order: number;
    is_active: boolean;
    is_public: boolean;
    subscriptions_count: number;
    features_json: {
        allow_auto_background_sync?: boolean;
        allow_bidirectional_spa_sync?: boolean;
        allow_custom_spas?: boolean;
        allow_custom_product_attributes?: boolean;
        allow_backfill?: boolean;
    } | null;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Overview', href: '/admin/dashboard' },
    { title: 'Plans & Tiers', href: '/admin/plans' },
];

const formatMoney = (cents: number, currency: string) =>
    new Intl.NumberFormat('en-US', { style: 'currency', currency: currency || 'USD' }).format(cents / 100);

export default function AdminPlansIndex({ plans }: { plans: PlanItem[] }) {
    const [dialogOpen, setDialogOpen] = useState(false);
    const [editingPlan, setEditingPlan] = useState<PlanItem | null>(null);

    const form = useForm({
        code: '',
        name: '',
        description: '',
        billing_type: 'fixed',
        price_per_user_cents: '' as string | number,
        price_monthly_cents: 4900,
        price_yearly_cents: 49000,
        currency: 'USD',
        trial_days: 14,
        max_monthly_syncs: '' as string | number,
        max_options: 2,
        sort_order: 10,
        is_active: true as boolean,
        is_public: true as boolean,
        features_json: {
            allow_auto_background_sync: true as boolean,
            allow_bidirectional_spa_sync: false as boolean,
            allow_custom_spas: true as boolean,
            allow_custom_product_attributes: true as boolean,
            allow_backfill: true as boolean,
        },
    });

    const openCreate = () => {
        setEditingPlan(null);
        form.reset();
        setDialogOpen(true);
    };

    const openEdit = (p: PlanItem) => {
        setEditingPlan(p);
        form.setData({
            code: p.code,
            name: p.name,
            description: p.description ?? '',
            billing_type: p.billing_type ?? 'fixed',
            price_per_user_cents: p.price_per_user_cents ?? '',
            price_monthly_cents: p.price_monthly_cents,
            price_yearly_cents: p.price_yearly_cents,
            currency: p.currency,
            trial_days: p.trial_days,
            max_monthly_syncs: p.max_monthly_syncs ?? '',
            max_options: p.max_options,
            sort_order: p.sort_order,
            is_active: p.is_active,
            is_public: p.is_public,
            features_json: {
                allow_auto_background_sync: Boolean(p.features_json?.allow_auto_background_sync),
                allow_bidirectional_spa_sync: Boolean(p.features_json?.allow_bidirectional_spa_sync),
                allow_custom_spas: Boolean(p.features_json?.allow_custom_spas),
                allow_custom_product_attributes: Boolean(p.features_json?.allow_custom_product_attributes),
                allow_backfill: Boolean(p.features_json?.allow_backfill),
            },
        });
        setDialogOpen(true);
    };

    const submit = (e: FormEvent) => {
        e.preventDefault();
        const payloadTransform = (data: typeof form.data) => ({
            ...data,
            price_per_user_cents: data.price_per_user_cents === '' ? null : Number(data.price_per_user_cents),
            max_monthly_syncs: data.max_monthly_syncs === '' ? null : Number(data.max_monthly_syncs),
        });
        form.transform(payloadTransform);
        if (editingPlan) {
            form.put(`/admin/plans/${editingPlan.id}`, { onSuccess: () => setDialogOpen(false) });
        } else {
            form.post('/admin/plans', { onSuccess: () => setDialogOpen(false) });
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Plans & Tiers" />
            <FlashBanner />

            <div className="flex flex-1 flex-col gap-5 p-4 md:p-6">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">Subscription Plans &amp; Quotas</h1>
                        <p className="text-muted-foreground text-sm">
                            Configure pricing, sync quotas, option limits, and feature entitlements for Marketplace tenants.
                        </p>
                    </div>
                    <Button onClick={openCreate}>
                        <Plus className="mr-1.5 h-4 w-4" /> New Plan
                    </Button>
                </div>

                <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
                    {plans.map((p) => (
                        <Card key={p.id} className="flex flex-col justify-between">
                            <CardHeader>
                                <div className="flex items-center justify-between gap-2">
                                    <Badge variant={p.is_active ? 'default' : 'secondary'}>{p.is_active ? 'Active' : 'Disabled'}</Badge>
                                    <span className="text-muted-foreground text-xs">{p.subscriptions_count} portal(s)</span>
                                </div>
                                <CardTitle className="mt-2">{p.name}</CardTitle>
                                <CardDescription>{p.description}</CardDescription>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                <div>
                                    <div className="text-2xl font-bold">
                                        {p.billing_type === 'per_user' || (p.price_per_user_cents && p.price_per_user_cents > 0) ? (
                                            <>
                                                {formatMoney(p.price_per_user_cents ?? 500, p.currency)}
                                                <span className="text-muted-foreground text-xs font-normal">/user/mo</span>
                                            </>
                                        ) : (
                                            <>
                                                {formatMoney(p.price_monthly_cents, p.currency)}
                                                <span className="text-muted-foreground text-xs font-normal">/mo</span>
                                            </>
                                        )}
                                    </div>
                                    <div className="text-muted-foreground text-xs">
                                        {formatMoney(p.price_yearly_cents, p.currency)}/yr · {p.trial_days}d trial
                                    </div>
                                </div>
                                <div className="space-y-1 border-t pt-3 text-xs">
                                    <div>
                                        Monthly Syncs: <strong>{p.max_monthly_syncs ?? 'Unlimited'}</strong>
                                    </div>
                                    <div>
                                        Max Options: <strong>{p.max_options}</strong>
                                    </div>
                                    <div>
                                        Bidirectional SPA: <strong>{p.features_json?.allow_bidirectional_spa_sync ? 'Yes' : 'No'}</strong>
                                    </div>
                                    <div>
                                        Custom SPAs &amp; Attrs:{' '}
                                        <strong>
                                            {p.features_json?.allow_custom_spas ? 'Yes' : 'No'} /{' '}
                                            {p.features_json?.allow_custom_product_attributes ? 'Yes' : 'No'}
                                        </strong>
                                    </div>
                                </div>
                                <Button variant="outline" size="sm" className="w-full" onClick={() => openEdit(p)}>
                                    Edit Tier
                                </Button>
                            </CardContent>
                        </Card>
                    ))}
                </div>

                <Dialog open={dialogOpen} onOpenChange={setDialogOpen}>
                    <DialogContent className="max-w-lg">
                        <DialogHeader>
                            <DialogTitle>{editingPlan ? `Edit Plan: ${editingPlan.name}` : 'Create Plan'}</DialogTitle>
                            <DialogDescription>Set pricing in cents, monthly quota, and feature gates.</DialogDescription>
                        </DialogHeader>
                        <form onSubmit={submit} className="space-y-4">
                            <div className="grid grid-cols-2 gap-3">
                                <div>
                                    <Label>Code</Label>
                                    <Input value={form.data.code} onChange={(e) => form.setData('code', e.target.value)} placeholder="pro" required />
                                </div>
                                <div>
                                    <Label>Name</Label>
                                    <Input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} placeholder="Pro" required />
                                </div>
                            </div>

                            <div>
                                <Label>Description</Label>
                                <Input value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} />
                            </div>

                            <div className="grid grid-cols-2 gap-3">
                                <div>
                                    <Label>Billing Type</Label>
                                    <select
                                        value={form.data.billing_type}
                                        onChange={(e) => form.setData('billing_type', e.target.value)}
                                        className="border-input bg-background mt-1 h-9 w-full rounded-md border px-2 text-sm"
                                    >
                                        <option value="fixed">Fixed Tier Price</option>
                                        <option value="per_user">Per User ($5/user)</option>
                                    </select>
                                </div>
                                <div>
                                    <Label>Price Per User (cents)</Label>
                                    <Input
                                        type="number"
                                        placeholder="500 ($5.00)"
                                        value={form.data.price_per_user_cents}
                                        onChange={(e) => form.setData('price_per_user_cents', e.target.value)}
                                    />
                                </div>
                            </div>

                            <div className="grid grid-cols-3 gap-3">
                                <div>
                                    <Label>Monthly (cents)</Label>
                                    <Input
                                        type="number"
                                        value={form.data.price_monthly_cents}
                                        onChange={(e) => form.setData('price_monthly_cents', Number(e.target.value))}
                                    />
                                </div>
                                <div>
                                    <Label>Yearly (cents)</Label>
                                    <Input
                                        type="number"
                                        value={form.data.price_yearly_cents}
                                        onChange={(e) => form.setData('price_yearly_cents', Number(e.target.value))}
                                    />
                                </div>
                                <div>
                                    <Label>Currency</Label>
                                    <Input
                                        value={form.data.currency}
                                        maxLength={3}
                                        onChange={(e) => form.setData('currency', e.target.value.toUpperCase())}
                                    />
                                </div>
                            </div>

                            <div className="grid grid-cols-3 gap-3">
                                <div>
                                    <Label>Trial Days</Label>
                                    <Input
                                        type="number"
                                        value={form.data.trial_days}
                                        onChange={(e) => form.setData('trial_days', Number(e.target.value))}
                                    />
                                </div>
                                <div>
                                    <Label>Max Syncs (empty = ∞)</Label>
                                    <Input
                                        type="number"
                                        value={form.data.max_monthly_syncs}
                                        onChange={(e) => form.setData('max_monthly_syncs', e.target.value)}
                                    />
                                </div>
                                <div>
                                    <Label>Max Options</Label>
                                    <Input
                                        type="number"
                                        min={1}
                                        max={10}
                                        value={form.data.max_options}
                                        onChange={(e) => form.setData('max_options', Number(e.target.value))}
                                    />
                                </div>
                            </div>

                            <div className="space-y-2 rounded-md border p-3">
                                <div className="text-xs font-medium uppercase">Feature Entitlements</div>
                                {(
                                    [
                                        ['allow_auto_background_sync', 'Background Webhook Sync'],
                                        ['allow_bidirectional_spa_sync', 'Bidirectional SPA -> Product Sync'],
                                        ['allow_custom_spas', 'Custom Target SPAs (e.g. Onboarding)'],
                                        ['allow_custom_product_attributes', 'Custom Product Catalog Attributes'],
                                        ['allow_backfill', 'Queued Historical Backfill'],
                                    ] as const
                                ).map(([key, label]) => (
                                    <label key={key} className="flex items-center gap-2 text-sm">
                                        <Checkbox
                                            checked={Boolean(form.data.features_json[key])}
                                            onCheckedChange={(checked) =>
                                                form.setData('features_json', {
                                                    ...form.data.features_json,
                                                    [key]: Boolean(checked),
                                                })
                                            }
                                        />
                                        <span>{label}</span>
                                    </label>
                                ))}
                            </div>

                            <div className="flex items-center gap-6">
                                <label className="flex items-center gap-2 text-sm">
                                    <Checkbox checked={form.data.is_active} onCheckedChange={(v) => form.setData('is_active', Boolean(v))} />
                                    <span>Active</span>
                                </label>
                                <label className="flex items-center gap-2 text-sm">
                                    <Checkbox checked={form.data.is_public} onCheckedChange={(v) => form.setData('is_public', Boolean(v))} />
                                    <span>Publicly Visible in Bitrix24 App</span>
                                </label>
                            </div>

                            <DialogFooter>
                                <Button type="button" variant="outline" onClick={() => setDialogOpen(false)}>
                                    Cancel
                                </Button>
                                <Button type="submit" disabled={form.processing}>
                                    {editingPlan ? 'Save Plan' : 'Create Plan'}
                                </Button>
                            </DialogFooter>
                        </form>
                    </DialogContent>
                </Dialog>
            </div>
        </AppLayout>
    );
}
