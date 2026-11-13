import { Head, Link } from '@inertiajs/react';
import { ArrowRight, CheckCircle2, HelpCircle, Mail, Minus, Sparkles } from 'lucide-react';
import { useState } from 'react';

import PublicLayout, { formatMoney, type PublicPlan } from '@/components/marketing/public-layout';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/components/ui/card';

interface PricingPageProps {
    plans?: PublicPlan[];
}

const faqs = [
    {
        q: 'How does the trial start when a customer installs the Bitrix24 app?',
        a: 'During Marketplace installation (/bitrix/install), KEEN Products provisions the tenant portal row and activates the default 7-day free trial subscription automatically using the configured plan trial_days.',
    },
    {
        q: 'What if two Bitrix24 portals have completely different UF_CRM_* or Catalog property IDs?',
        a: 'Each tenant gets an isolated TenantCatalogConfig, TenantFieldMapping, TenantEnumMapping, and TenantSpaBucket record. Portals either 1-click auto-provision clean fields or run Schema Scanner to map existing fields.',
    },
    {
        q: 'Does it support Bitrix24 On-Premise / Self-Hosted custom domains?',
        a: 'Yes. Set BITRIX24_ALLOWED_FRAME_ANCESTORS in .env to include custom intranet domains alongside dynamic per-tenant portal domains for iframe placement CSP headers.',
    },
    {
        q: 'How are Super Admin overrides and enterprise contracts handled?',
        a: 'SaaS operators can log into the Super Admin Console to manage plans, extend trials, apply custom grants or suspensions, inspect sync queues, and record every billing action in AdminAuditLog.',
    },
    {
        q: 'How do I contact support or request custom onboarding?',
        a: 'You can email our team directly at apps@keenenter.com for prompt assistance with setup, enterprise agreements, or technical questions.',
    },
];

export default function PricingPage({ plans = [] }: PricingPageProps) {
    const [billingCycle, setBillingCycle] = useState<'monthly' | 'yearly'>('monthly');

    const getDisplayPrice = (p: PublicPlan) => {
        if (billingCycle === 'monthly') {
            return formatMoney(p.price_monthly_cents, p.currency);
        }
        return formatMoney(p.price_yearly_cents, p.currency);
    };

    return (
        <PublicLayout>
            <Head title="Pricing & Subscription Tiers — KEEN Products" />

            <section className="border-b border-slate-200/80 bg-gradient-to-b from-indigo-50/70 to-slate-50 dark:border-slate-800/80 dark:from-indigo-950/30 dark:to-slate-950">
                <div className="mx-auto max-w-6xl space-y-5 px-6 py-14 text-center">
                    <Badge
                        variant="secondary"
                        className="mx-auto w-fit gap-1.5 border border-indigo-200 bg-indigo-50 text-indigo-700 dark:border-indigo-900 dark:bg-indigo-950/60 dark:text-indigo-300"
                    >
                        <Sparkles className="h-3.5 w-3.5" />
                        Simple SaaS Metering Per Bitrix24 Portal
                    </Badge>
                    <h1 className="mx-auto max-w-2xl text-3xl font-bold tracking-tight sm:text-4xl">
                        Flexible Plans Built for Growing Sales &amp; Ops Teams
                    </h1>
                    <p className="text-muted-foreground mx-auto max-w-xl text-sm sm:text-base">
                        Every Bitrix24 portal starts with an automated trial. Upgrade inside the embedded Bitrix24 Portal Console or manage portal
                        contracts directly from Super Admin.
                    </p>

                    <div className="bg-card inline-flex items-center rounded-lg border p-1 shadow-xs">
                        <button
                            type="button"
                            onClick={() => setBillingCycle('monthly')}
                            className={`rounded-md px-4 py-1.5 text-xs font-semibold transition-colors ${
                                billingCycle === 'monthly' ? 'bg-indigo-600 text-white' : 'text-muted-foreground hover:text-foreground'
                            }`}
                        >
                            Monthly Billing
                        </button>
                        <button
                            type="button"
                            onClick={() => setBillingCycle('yearly')}
                            className={`rounded-md px-4 py-1.5 text-xs font-semibold transition-colors ${
                                billingCycle === 'yearly' ? 'bg-indigo-600 text-white' : 'text-muted-foreground hover:text-foreground'
                            }`}
                        >
                            Yearly Billing
                        </button>
                    </div>
                </div>
            </section>

            <div className="mx-auto max-w-6xl space-y-14 px-6 py-12">
                {/* Cards Grid */}
                {plans.length > 0 ? (
                    <section className="grid gap-5 md:grid-cols-2 lg:grid-cols-4">
                        {plans.map((p, idx) => {
                            const highlighted = idx === 1 || p.code === 'growth' || p.code === 'pro';
                            const unit = billingCycle === 'monthly' ? '/mo' : '/yr';
                            const rawAmount = billingCycle === 'monthly' ? p.price_monthly_cents : p.price_yearly_cents;

                            return (
                                <Card
                                    key={p.id}
                                    className={`flex flex-col ${
                                        highlighted
                                            ? 'border-indigo-500 ring-1 ring-indigo-500/30 dark:border-indigo-400'
                                            : 'border-slate-200/90 dark:border-slate-800'
                                    }`}
                                >
                                    <CardHeader>
                                        <div className="flex items-center justify-between gap-2">
                                            <CardTitle className="text-base">{p.name}</CardTitle>
                                            {highlighted && (
                                                <Badge className="bg-indigo-600 text-[10px] text-white hover:bg-indigo-600">Popular</Badge>
                                            )}
                                        </div>
                                        <div className="pt-1 text-3xl font-bold tracking-tight">
                                            {getDisplayPrice(p)}
                                            {rawAmount > 0 && <span className="text-muted-foreground text-xs font-normal">{unit}</span>}
                                        </div>
                                        <CardDescription className="text-xs">{p.description}</CardDescription>
                                    </CardHeader>

                                    <CardContent className="flex-1 space-y-2.5 text-xs">
                                        <div className="flex items-center gap-2">
                                            <CheckCircle2 className="h-4 w-4 shrink-0 text-emerald-600" />
                                            <span>{p.max_monthly_syncs ?? 'Unlimited'} monthly sync runs</span>
                                        </div>
                                        <div className="flex items-center gap-2">
                                            <CheckCircle2 className="h-4 w-4 shrink-0 text-emerald-600" />
                                            <span>Up to {p.max_options} fee option groups</span>
                                        </div>
                                        <div className="flex items-center gap-2">
                                            <CheckCircle2 className="h-4 w-4 shrink-0 text-emerald-600" />
                                            <span>
                                                {p.features_json?.allow_bidirectional_spa_sync
                                                    ? 'Bidirectional CRM <-> SPA sync'
                                                    : 'CRM -> SPA automatic sync'}
                                            </span>
                                        </div>
                                        <div className="text-muted-foreground flex items-center gap-2">
                                            <CheckCircle2 className="h-4 w-4 shrink-0 text-emerald-600" />
                                            <span>{p.trial_days > 0 ? `${p.trial_days}-day trial included` : 'Instant activation'}</span>
                                        </div>
                                    </CardContent>

                                    <CardFooter>
                                        <Button
                                            asChild
                                            className={`w-full ${highlighted ? 'bg-indigo-600 text-white hover:bg-indigo-700' : ''}`}
                                            variant={highlighted ? 'default' : 'outline'}
                                        >
                                            <Link href={route('register')}>
                                                Get Started
                                                <ArrowRight className="ml-1.5 h-3.5 w-3.5" />
                                            </Link>
                                        </Button>
                                    </CardFooter>
                                </Card>
                            );
                        })}
                    </section>
                ) : (
                    <Card>
                        <CardContent className="text-muted-foreground py-10 text-center text-sm">
                            No public plans are configured yet. Configure plans in the Super Admin Console.
                        </CardContent>
                    </Card>
                )}

                {/* Comparison Matrix */}
                {plans.length > 0 && (
                    <section className="space-y-4">
                        <h2 className="text-xl font-bold tracking-tight">Plan Feature Matrix</h2>
                        <Card className="overflow-hidden border-slate-200/90 dark:border-slate-800">
                            <div className="overflow-x-auto">
                                <table className="w-full text-left text-xs">
                                    <thead className="bg-muted/60 text-muted-foreground border-b">
                                        <tr>
                                            <th className="px-4 py-3 font-semibold">Capability</th>
                                            {plans.map((p) => (
                                                <th key={p.id} className="text-foreground px-4 py-3 font-semibold">
                                                    {p.name}
                                                </th>
                                            ))}
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y">
                                        <tr>
                                            <td className="px-4 py-3 font-medium">Monthly Sync Runs</td>
                                            {plans.map((p) => (
                                                <td key={p.id} className="px-4 py-3">
                                                    {p.max_monthly_syncs ?? 'Unlimited'}
                                                </td>
                                            ))}
                                        </tr>
                                        <tr>
                                            <td className="px-4 py-3 font-medium">Fee Option Groups</td>
                                            {plans.map((p) => (
                                                <td key={p.id} className="px-4 py-3">
                                                    Up to {p.max_options}
                                                </td>
                                            ))}
                                        </tr>
                                        <tr>
                                            <td className="px-4 py-3 font-medium">Bidirectional SPA &rarr; CRM Sync</td>
                                            {plans.map((p) => (
                                                <td key={p.id} className="px-4 py-3">
                                                    {p.features_json?.allow_bidirectional_spa_sync ? (
                                                        <CheckCircle2 className="h-4 w-4 text-emerald-600" />
                                                    ) : (
                                                        <Minus className="text-muted-foreground h-4 w-4" />
                                                    )}
                                                </td>
                                            ))}
                                        </tr>
                                        <tr>
                                            <td className="px-4 py-3 font-medium">Schema Auto-Provisioning Wizard</td>
                                            {plans.map((p) => (
                                                <td key={p.id} className="px-4 py-3">
                                                    <CheckCircle2 className="h-4 w-4 text-emerald-600" />
                                                </td>
                                            ))}
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </Card>
                    </section>
                )}

                {/* FAQ */}
                <section className="space-y-4">
                    <div className="flex items-center gap-2">
                        <HelpCircle className="h-5 w-5 text-indigo-600 dark:text-indigo-400" />
                        <h2 className="text-xl font-bold tracking-tight">Frequently Asked Questions</h2>
                    </div>
                    <div className="grid gap-4 md:grid-cols-2">
                        {faqs.map((item) => (
                            <Card key={item.q} className="border-slate-200/90 dark:border-slate-800">
                                <CardHeader className="pb-2">
                                    <CardTitle className="text-sm">{item.q}</CardTitle>
                                </CardHeader>
                                <CardContent className="text-muted-foreground text-xs leading-relaxed">{item.a}</CardContent>
                            </Card>
                        ))}
                    </div>
                </section>

                {/* Contact Us Section */}
                <section className="rounded-2xl border border-indigo-100 bg-gradient-to-r from-indigo-50/80 via-white to-indigo-50/50 p-8 text-center shadow-xs dark:border-indigo-950 dark:from-indigo-950/30 dark:via-slate-900 dark:to-indigo-950/20">
                    <div className="mx-auto max-w-xl space-y-3">
                        <h3 className="text-xl font-bold tracking-tight">Need a Tailored Plan or Enterprise Contract?</h3>
                        <p className="text-muted-foreground text-sm">
                            We offer custom seat licensing, high-frequency background sync quotas, and dedicated onboarding for large Bitrix24 organizations.
                        </p>
                        <div className="pt-2">
                            <Button asChild size="lg" className="bg-indigo-600 text-white hover:bg-indigo-700 dark:bg-indigo-500 dark:hover:bg-indigo-600">
                                <a href="mailto:apps@keenenter.com">
                                    <Mail className="mr-2 h-4 w-4" />
                                    Contact Us (apps@keenenter.com)
                                </a>
                            </Button>
                        </div>
                    </div>
                </section>
            </div>
        </PublicLayout>
    );
}
