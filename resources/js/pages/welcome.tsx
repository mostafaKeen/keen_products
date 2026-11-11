import { Head, Link } from '@inertiajs/react';
import {
    ArrowRight,
    CheckCircle2,
    Database,
    GitBranch,
    Layers,
    Lock,
    RefreshCw,
    ShieldCheck,
    Sparkles,
    Workflow,
} from 'lucide-react';

import { EndpointUrlsCard } from '@/components/marketing/endpoint-urls-card';
import PublicLayout, { type BitrixAppUrls, formatMoney, type PublicPlan } from '@/components/marketing/public-layout';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';

interface WelcomeProps {
    plans?: PublicPlan[];
    app_urls?: BitrixAppUrls;
}

const previewRows = [
    { item: 'Retainer & Advisory Scope', costType: 'Professional', option: 'Option 1', amount: '$4,200' },
    { item: 'Authority Registration Filing', costType: 'Government', option: 'Option 1', amount: '$1,150' },
    { item: 'Enterprise Multi-Entity Package', costType: 'Professional', option: 'Option 2', amount: '$6,800' },
    { item: 'Priority Stamp & Notary Dispatch', costType: 'Government', option: 'Option 2', amount: '$1,490' },
];

const rolloutSteps = [
    {
        step: '01',
        title: 'Marketplace Install',
        desc: 'Any Bitrix24 Cloud or On-Premise admin installs the app; OAuth credentials & trial subscription initialize automatically.',
    },
    {
        step: '02',
        title: 'Schema Auto-Provision or Scan',
        desc: '1-click creation of Catalog properties, Enum IDs, CRM fields (UF_CRM_*), and SPA buckets — or auto-map your portal’s existing schema.',
    },
    {
        step: '03',
        title: 'Placement & Real-Time Sync',
        desc: 'Embedded widget splits Professional vs. Government fees across Option 1..N while keeping Deals, Quotes, & SPAs synchronized.',
    },
    {
        step: '04',
        title: 'SaaS Quotas & Admin Control',
        desc: 'Per-portal monthly sync metering, trial countdowns, grace period protection, and Super Admin plan overrides.',
    },
];

export default function Welcome({ plans = [], app_urls }: WelcomeProps) {
    return (
        <PublicLayout>
            <Head title="FeeMatrix24 — Multi-Option Fee Breakdown & SPA Sync for Bitrix24" />

            {/* Hero */}
            <section className="relative overflow-hidden border-b border-slate-200/80 bg-gradient-to-b from-indigo-50/75 via-white to-slate-50 dark:border-slate-800/80 dark:from-indigo-950/35 dark:via-slate-950 dark:to-slate-950">
                <div className="mx-auto grid max-w-6xl gap-10 px-6 py-14 lg:grid-cols-12 lg:items-center lg:py-16">
                    <div className="space-y-6 lg:col-span-6">
                        <Badge
                            variant="secondary"
                            className="gap-1.5 border border-indigo-200/80 bg-indigo-50 text-indigo-700 dark:border-indigo-900 dark:bg-indigo-950/60 dark:text-indigo-300"
                        >
                            <Sparkles className="h-3.5 w-3.5" />
                            Bitrix24 Marketplace Multi-Tenant SaaS
                        </Badge>

                        <h1 className="text-3xl font-bold tracking-tight text-slate-950 sm:text-4xl lg:text-[2.5rem] lg:leading-[1.15] dark:text-white">
                            Dynamic Multi-Option Fee Breakdown &amp; SPA Sync for Every Bitrix24 Portal
                        </h1>

                        <p className="text-base leading-relaxed text-slate-600 dark:text-slate-300">
                            Stop hardcoding portal-specific custom fields. <span className="font-semibold text-foreground">FeeMatrix24</span>{' '}
                            dynamically provisions or crosswalks each customer’s Catalog properties, Enum IDs,{' '}
                            <code className="rounded bg-slate-200/70 px-1.5 py-0.5 font-mono text-xs dark:bg-slate-800">UF_CRM_*</code>{' '}
                            fields, and Smart Processes while syncing Leads, Deals, Quotes, Invoices, and SPAs in real time.
                        </p>

                        <div className="flex flex-wrap items-center gap-3 pt-1">
                            <Button
                                asChild
                                size="lg"
                                className="bg-indigo-600 text-white shadow-sm hover:bg-indigo-700 dark:bg-indigo-500 dark:hover:bg-indigo-600"
                            >
                                <Link href="/features">
                                    Explore Architecture
                                    <ArrowRight className="ml-2 h-4 w-4" />
                                </Link>
                            </Button>
                            <Button asChild variant="outline" size="lg">
                                <Link href="/pricing">View Subscription Plans</Link>
                            </Button>
                        </div>

                        <div className="flex flex-wrap items-center gap-5 pt-2 text-xs text-muted-foreground">
                            <span className="inline-flex items-center gap-1.5">
                                <CheckCircle2 className="h-4 w-4 text-emerald-600" />
                                Tenant-isolated OAuth &amp; schema map
                            </span>
                            <span className="inline-flex items-center gap-1.5">
                                <CheckCircle2 className="h-4 w-4 text-emerald-600" />
                                Bidirectional CRM &harr; SPA engine
                            </span>
                            <span className="inline-flex items-center gap-1.5">
                                <CheckCircle2 className="h-4 w-4 text-emerald-600" />
                                Cloud &amp; On-Premise CSP ready
                            </span>
                        </div>
                    </div>

                    {/* Visual Placement Widget Mockup */}
                    <div className="lg:col-span-6">
                        <Card className="overflow-hidden border-indigo-200/70 shadow-lg dark:border-indigo-900/60">
                            <div className="flex items-center justify-between border-b bg-slate-900 px-4 py-3 text-white dark:bg-slate-900">
                                <div className="flex items-center gap-2">
                                    <div className="flex h-6 w-6 items-center justify-center rounded bg-indigo-500">
                                        <Layers className="h-3.5 w-3.5 text-white" />
                                    </div>
                                    <span className="text-xs font-semibold">Bitrix24 Deal #4821 — Fee Matrix Placement</span>
                                </div>
                                <Badge className="bg-emerald-500/20 text-[10px] text-emerald-300 hover:bg-emerald-500/20">
                                    Synced &bull; 140ms
                                </Badge>
                            </div>
                            <CardContent className="space-y-4 p-5">
                                <div className="grid grid-cols-2 gap-3">
                                    <div className="rounded-lg border border-indigo-200/70 bg-indigo-50/70 p-3 dark:border-indigo-900/60 dark:bg-indigo-950/35">
                                        <div className="text-[11px] font-medium text-indigo-700 dark:text-indigo-300">Option 1 Total</div>
                                        <div className="mt-1 text-lg font-bold tracking-tight">$5,350</div>
                                        <div className="mt-1 flex items-center justify-between text-[11px] text-muted-foreground">
                                            <span>Prof: $4,200</span>
                                            <span>Gov: $1,150</span>
                                        </div>
                                    </div>
                                    <div className="rounded-lg border bg-slate-50/90 p-3 dark:bg-slate-900/70">
                                        <div className="text-[11px] font-medium text-muted-foreground">Option 2 Total</div>
                                        <div className="mt-1 text-lg font-bold tracking-tight">$8,290</div>
                                        <div className="mt-1 flex items-center justify-between text-[11px] text-muted-foreground">
                                            <span>Prof: $6,800</span>
                                            <span>Gov: $1,490</span>
                                        </div>
                                    </div>
                                </div>

                                <div className="overflow-hidden rounded-lg border text-xs">
                                    <div className="grid grid-cols-12 border-b bg-muted/60 px-3 py-2 font-medium text-muted-foreground">
                                        <div className="col-span-5">Product Row</div>
                                        <div className="col-span-3">Type</div>
                                        <div className="col-span-2">Bucket</div>
                                        <div className="col-span-2 text-right">Amount</div>
                                    </div>
                                    <div className="divide-y">
                                        {previewRows.map((row) => (
                                            <div key={row.item} className="grid grid-cols-12 items-center px-3 py-2">
                                                <div className="col-span-5 truncate pr-2 font-medium">{row.item}</div>
                                                <div className="col-span-3">
                                                    <span
                                                        className={`inline-flex rounded px-1.5 py-0.5 text-[10px] font-medium ${
                                                            row.costType === 'Professional'
                                                                ? 'bg-indigo-100 text-indigo-700 dark:bg-indigo-950 dark:text-indigo-300'
                                                                : 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300'
                                                        }`}
                                                    >
                                                        {row.costType}
                                                    </span>
                                                </div>
                                                <div className="col-span-2 text-muted-foreground">{row.option}</div>
                                                <div className="col-span-2 text-right font-mono font-medium">{row.amount}</div>
                                            </div>
                                        ))}
                                    </div>
                                </div>

                                <div className="flex flex-wrap items-center justify-between gap-2 rounded-md border border-dashed px-3 py-2 text-[11px] text-muted-foreground">
                                    <span>Catalog Props: property301 / property302</span>
                                    <span>SPA Buckets: 1090..1093 Synced</span>
                                </div>
                            </CardContent>
                        </Card>
                    </div>
                </div>
            </section>

            <div className="mx-auto max-w-6xl space-y-16 px-6 py-14">
                {/* Core Capabilities */}
                <section className="space-y-6">
                    <div className="max-w-2xl space-y-2">
                        <h2 className="text-2xl font-bold tracking-tight">Built for Multi-Tenant Marketplace Scale</h2>
                        <p className="text-sm text-muted-foreground">
                            Every installed Bitrix24 account assigns its own internal IDs to properties, enums, CRM custom fields, and
                            Smart Processes. FeeMatrix24 abstracts those differences cleanly per tenant.
                        </p>
                    </div>

                    <div className="grid gap-5 md:grid-cols-3">
                        {[
                            {
                                icon: Workflow,
                                title: 'Adaptive Schema Provisioning',
                                desc: 'Auto-create missing Catalog properties, Enum options, UF_CRM_* fields, and SPA containers in one wizard — or scan and map fields already live in the portal.',
                            },
                            {
                                icon: Database,
                                title: 'Atomic CRM + Catalog + SPA Sync',
                                desc: 'Update product rows once inside CRM or the placement widget and automatically compute Option 1..N Professional, Government, and Subtotal buckets.',
                            },
                            {
                                icon: ShieldCheck,
                                title: 'Subscription & Super Admin Engine',
                                desc: 'Enforce monthly sync quotas, max fee option limits, trial days, grace windows, and Super Admin portal overrides with immutable audit logging.',
                            },
                        ].map((item) => {
                            const Icon = item.icon;
                            return (
                                <Card key={item.title} className="border-slate-200/90 dark:border-slate-800">
                                    <CardHeader className="pb-2">
                                        <div className="mb-2 flex h-9 w-9 items-center justify-center rounded-lg bg-indigo-50 text-indigo-600 dark:bg-indigo-950/70 dark:text-indigo-400">
                                            <Icon className="h-5 w-5" />
                                        </div>
                                        <CardTitle className="text-base">{item.title}</CardTitle>
                                    </CardHeader>
                                    <CardContent>
                                        <p className="text-sm leading-relaxed text-muted-foreground">{item.desc}</p>
                                    </CardContent>
                                </Card>
                            );
                        })}
                    </div>
                </section>

                {/* How It Works */}
                <section className="space-y-6">
                    <div className="flex flex-col justify-between gap-3 sm:flex-row sm:items-end">
                        <div className="space-y-1.5">
                            <h2 className="text-2xl font-bold tracking-tight">How Marketplace Rollout Works</h2>
                            <p className="text-sm text-muted-foreground">
                                From Bitrix24 installation to full multi-option Deal &amp; SPA synchronization in minutes.
                            </p>
                        </div>
                        <Link
                            href="/features"
                            className="inline-flex items-center gap-1.5 text-sm font-medium text-indigo-600 hover:underline dark:text-indigo-400"
                        >
                            Deep dive into schema &amp; security architecture
                            <ArrowRight className="h-4 w-4" />
                        </Link>
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        {rolloutSteps.map((s) => (
                            <div key={s.step} className="rounded-xl border border-slate-200/90 bg-card p-5 shadow-xs dark:border-slate-800">
                                <div className="font-mono text-xs font-semibold text-indigo-600 dark:text-indigo-400">STEP {s.step}</div>
                                <div className="mt-2 font-semibold">{s.title}</div>
                                <p className="mt-1.5 text-xs leading-relaxed text-muted-foreground">{s.desc}</p>
                            </div>
                        ))}
                    </div>
                </section>

                {/* Developer Area URLs + Security Highlights */}
                <section className="grid gap-6 lg:grid-cols-12 lg:items-start">
                    <div className="space-y-4 lg:col-span-6">
                        <h2 className="text-2xl font-bold tracking-tight">Security &amp; Multi-Portal Isolation</h2>
                        <p className="text-sm leading-relaxed text-muted-foreground">
                            Each Bitrix24 portal authenticates through encrypted OAuth tokens and signed placement session JWTs, with
                            configurable iframe ancestors for custom corporate domains and On-Premise editions.
                        </p>
                        <div className="grid gap-3 sm:grid-cols-2">
                            <div className="rounded-lg border bg-card p-4 text-xs">
                                <Lock className="mb-2 h-4 w-4 text-indigo-600 dark:text-indigo-400" />
                                <div className="font-semibold">Encrypted Portal Tokens</div>
                                <div className="mt-1 text-muted-foreground">
                                    OAuth access, refresh, and application signature tokens are encrypted at rest per portal.
                                </div>
                            </div>
                            <div className="rounded-lg border bg-card p-4 text-xs">
                                <GitBranch className="mb-2 h-4 w-4 text-indigo-600 dark:text-indigo-400" />
                                <div className="font-semibold">Loop-Safe Event Sync</div>
                                <div className="mt-1 text-muted-foreground">
                                    Timestamp &amp; origin guards prevent infinite ping-pong loops during bidirectional CRM &harr; SPA updates.
                                </div>
                            </div>
                        </div>
                        <div className="rounded-lg border bg-card p-4 text-xs">
                            <div className="flex items-center gap-2 font-semibold">
                                <RefreshCw className="h-4 w-4 text-indigo-600 dark:text-indigo-400" />
                                Self-Hosted &amp; Cloud Domain Whitelisting
                            </div>
                            <p className="mt-1 text-muted-foreground">
                                Set <code className="font-mono text-[11px]">BITRIX24_ALLOWED_FRAME_ANCESTORS</code> to allow enterprise custom
                                intranet hosts seamlessly alongside standard <code className="font-mono text-[11px]">*.bitrix24.*</code>{' '}
                                origins.
                            </p>
                        </div>
                    </div>

                    <div className="lg:col-span-6">
                        <EndpointUrlsCard appUrls={app_urls} />
                    </div>
                </section>

                {/* Public Plans */}
                {plans.length > 0 && (
                    <section className="space-y-5">
                        <div className="flex flex-col justify-between gap-3 sm:flex-row sm:items-end">
                            <div>
                                <h2 className="text-2xl font-bold tracking-tight">Transparent Subscription Tiers</h2>
                                <p className="text-sm text-muted-foreground">
                                    Every installed portal receives a trial period and can upgrade cleanly as volume grows.
                                </p>
                            </div>
                            <Button asChild variant="outline" size="sm">
                                <Link href="/pricing">
                                    Compare Monthly vs Yearly
                                    <ArrowRight className="ml-1.5 h-3.5 w-3.5" />
                                </Link>
                            </Button>
                        </div>

                        <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
                            {plans.map((p) => (
                                <Card key={p.id} className="flex flex-col border-slate-200/90 dark:border-slate-800">
                                    <CardHeader className="pb-2">
                                        <CardTitle className="text-base">{p.name}</CardTitle>
                                        <div className="text-2xl font-bold">
                                            {formatMoney(p.price_monthly_cents, p.currency)}
                                            {p.price_monthly_cents > 0 && (
                                                <span className="text-xs font-normal text-muted-foreground">/mo</span>
                                            )}
                                        </div>
                                        <CardDescription className="text-xs">{p.description}</CardDescription>
                                    </CardHeader>
                                    <CardContent className="flex-1 space-y-2 text-xs text-muted-foreground">
                                        <div className="flex items-center gap-1.5 text-foreground">
                                            <CheckCircle2 className="h-3.5 w-3.5 shrink-0 text-emerald-600" />
                                            <span>{p.max_monthly_syncs ?? 'Unlimited'} monthly syncs</span>
                                        </div>
                                        <div className="flex items-center gap-1.5 text-foreground">
                                            <CheckCircle2 className="h-3.5 w-3.5 shrink-0 text-emerald-600" />
                                            <span>Up to {p.max_options} fee option groups</span>
                                        </div>
                                        <div className="flex items-center gap-1.5">
                                            <CheckCircle2 className="h-3.5 w-3.5 shrink-0 text-emerald-600" />
                                            <span>
                                                {p.features_json?.allow_bidirectional_spa_sync
                                                    ? 'Bidirectional SPA sync included'
                                                    : 'CRM -> SPA sync'}
                                            </span>
                                        </div>
                                    </CardContent>
                                </Card>
                            ))}
                        </div>
                    </section>
                )}
            </div>
        </PublicLayout>
    );
}
