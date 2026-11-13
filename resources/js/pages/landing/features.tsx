import { Head, Link } from '@inertiajs/react';
import { ArrowLeftRight, ArrowRight, CheckCircle2, Cpu, DatabaseZap, KeyRound, Layers, Mail, ShieldCheck, Sliders } from 'lucide-react';

import { EndpointUrlsCard } from '@/components/marketing/endpoint-urls-card';
import PublicLayout, { type BitrixAppUrls } from '@/components/marketing/public-layout';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';

interface FeaturesPageProps {
    app_urls?: BitrixAppUrls;
}

const schemaCrosswalk = [
    {
        logicalKey: 'Cost Type Catalog Property',
        portalA: 'property104 (ID 104)',
        portalB: 'property301 (ID 301)',
        canonical: 'cost_type_prop_code',
    },
    {
        logicalKey: 'Fee Option Catalog Property',
        portalA: 'property105 (ID 105)',
        portalB: 'property302 (ID 302)',
        canonical: 'option_prop_code',
    },
    {
        logicalKey: 'Professional Option 1 Enum',
        portalA: 'Enum #4102 ("Prof")',
        portalB: 'Enum #9001 ("Professional")',
        canonical: 'enum_key: prof',
    },
    {
        logicalKey: 'Deal Prof Option 1 Subtotal',
        portalA: 'UF_CRM_17102001_P1',
        portalB: 'UF_CRM_999111',
        canonical: 'field_key: prof_opt1',
    },
    {
        logicalKey: 'Smart Process Container (Opt 1)',
        portalA: 'entityTypeId: 1036',
        portalB: 'entityTypeId: 1090',
        canonical: 'bucket_key: prof_opt1',
    },
];

export default function FeaturesPage({ app_urls }: FeaturesPageProps) {
    return (
        <PublicLayout>
            <Head title="Architecture & Sync — KEEN Products" />

            <section className="border-b border-slate-200/80 bg-gradient-to-b from-indigo-50/70 to-slate-50 dark:border-slate-800/80 dark:from-indigo-950/30 dark:to-slate-950">
                <div className="mx-auto max-w-6xl space-y-4 px-6 py-14">
                    <Badge
                        variant="secondary"
                        className="border border-indigo-200 bg-indigo-50 text-indigo-700 dark:border-indigo-900 dark:bg-indigo-950/60 dark:text-indigo-300"
                    >
                        Technical Deep Dive
                    </Badge>
                    <h1 className="max-w-3xl text-3xl font-bold tracking-tight sm:text-4xl">
                        Why Adaptive Per-Tenant Schema Mapping Matters in Bitrix24 Marketplace
                    </h1>
                    <p className="text-muted-foreground max-w-2xl text-base">
                        In single-portal scripts, developer constants like <code className="font-mono text-xs">property104</code> or{' '}
                        <code className="font-mono text-xs">UF_CRM_17238_OPT1</code> are hardcoded. Across hundreds of Cloud &amp; On-Premise
                        customers, every Bitrix24 portal assigns completely different internal field names and Enum IDs.
                    </p>
                </div>
            </section>

            <div className="mx-auto max-w-6xl space-y-14 px-6 py-12">
                {/* Schema Crosswalk Visual Table */}
                <section className="space-y-4">
                    <div className="flex flex-col justify-between gap-3 sm:flex-row sm:items-end">
                        <div>
                            <h2 className="text-xl font-bold tracking-tight">Per-Tenant Field &amp; Enum Crosswalk</h2>
                            <p className="text-muted-foreground text-sm">
                                KEEN Products translates each customer portal&rsquo;s native Bitrix24 identifiers into canonical keys once at
                                onboarding.
                            </p>
                        </div>
                        <Badge variant="outline" className="w-fit font-mono text-xs">
                            BitrixSchemaScanner + BitrixSchemaProvisioner
                        </Badge>
                    </div>

                    <Card className="overflow-hidden border-slate-200/90 dark:border-slate-800">
                        <div className="overflow-x-auto">
                            <table className="w-full text-left text-xs">
                                <thead className="bg-muted/60 text-muted-foreground border-b">
                                    <tr>
                                        <th className="px-4 py-3 font-semibold">Logical Concept</th>
                                        <th className="px-4 py-3 font-semibold">Customer Portal A (Cloud)</th>
                                        <th className="px-4 py-3 font-semibold">Customer Portal B (On-Premise)</th>
                                        <th className="px-4 py-3 font-semibold">Canonical Adapter Key</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {schemaCrosswalk.map((row) => (
                                        <tr key={row.logicalKey} className="hover:bg-muted/30">
                                            <td className="text-foreground px-4 py-3 font-medium">{row.logicalKey}</td>
                                            <td className="px-4 py-3 font-mono text-slate-600 dark:text-slate-300">{row.portalA}</td>
                                            <td className="px-4 py-3 font-mono text-slate-600 dark:text-slate-300">{row.portalB}</td>
                                            <td className="px-4 py-3 font-mono font-semibold text-indigo-600 dark:text-indigo-400">
                                                {row.canonical}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </Card>
                </section>

                {/* Bidirectional Sync Architecture */}
                <section className="grid gap-6 md:grid-cols-2">
                    <Card className="border-slate-200/90 dark:border-slate-800">
                        <CardHeader>
                            <div className="mb-1 flex items-center gap-2 text-indigo-600 dark:text-indigo-400">
                                <DatabaseZap className="h-5 w-5" />
                                <span className="text-xs font-semibold tracking-wider uppercase">Forward Pipeline</span>
                            </div>
                            <CardTitle className="text-lg">Widget &amp; CRM Entity &rarr; Catalog + SPAs</CardTitle>
                            <CardDescription>Triggered interactively in Deal/Lead tabs or via Bitrix CRM webhooks.</CardDescription>
                        </CardHeader>
                        <CardContent className="text-muted-foreground space-y-2.5 text-sm">
                            {[
                                'Verifies tenant license & monthly quota via SubscriptionGate before API calls run.',
                                'Reads product rows & normalizes Cost Type + Fee Option using BitrixFieldAdapter.',
                                'Updates parent entity totals (Prof / Gov / Total per Option 1..N) cleanly.',
                                'Synchronizes child Smart Process (SPA) items using idempotent upserts & batch throttling.',
                            ].map((line) => (
                                <div key={line} className="flex items-start gap-2">
                                    <CheckCircle2 className="mt-0.5 h-4 w-4 shrink-0 text-emerald-600" />
                                    <span>{line}</span>
                                </div>
                            ))}
                        </CardContent>
                    </Card>

                    <Card className="border-slate-200/90 dark:border-slate-800">
                        <CardHeader>
                            <div className="mb-1 flex items-center gap-2 text-indigo-600 dark:text-indigo-400">
                                <ArrowLeftRight className="h-5 w-5" />
                                <span className="text-xs font-semibold tracking-wider uppercase">Reverse Pipeline</span>
                            </div>
                            <CardTitle className="text-lg">SPA Item Event &rarr; Parent CRM Recalculation</CardTitle>
                            <CardDescription>Available on Bidirectional tiers with loop-suppression safeguards.</CardDescription>
                        </CardHeader>
                        <CardContent className="text-muted-foreground space-y-2.5 text-sm">
                            {[
                                'Captures ONCRMDYNAMICITEMADD / UPDATE / DELETE events securely via application_token validation.',
                                'Resolves parent CRM entity automatically through SyncedSpaLink & Bitrix parent bindings.',
                                'Applies SYNC_SOURCE echo suppression & state hashing to guarantee zero infinite loops.',
                                'Records execution duration, REST batch call counts, & status in tenant SyncLogs.',
                            ].map((line) => (
                                <div key={line} className="flex items-start gap-2">
                                    <CheckCircle2 className="mt-0.5 h-4 w-4 shrink-0 text-emerald-600" />
                                    <span>{line}</span>
                                </div>
                            ))}
                        </CardContent>
                    </Card>
                </section>

                {/* Governance & Endpoint Reference */}
                <section className="grid gap-6 lg:grid-cols-12 lg:items-start">
                    <div className="grid gap-4 sm:grid-cols-2 lg:col-span-7">
                        {[
                            {
                                icon: KeyRound,
                                title: 'Signed HS256 Widget JWTs',
                                desc: 'Placement iframes exchange Bitrix auth payloads once for short-lived scoped tokens verified by VerifyWidgetJwt.',
                            },
                            {
                                icon: ShieldCheck,
                                title: 'Dynamic Frame Ancestors CSP',
                                desc: 'BitrixIframeHeaders authorizes Bitrix24 regional clouds plus custom On-Premise domains configured in env.',
                            },
                            {
                                icon: Sliders,
                                title: 'Self-Service Portal Console',
                                desc: 'Portal admins trigger backfill jobs, re-scan schema mappings, toggle sync direction, and upgrade plans inside Bitrix24.',
                            },
                            {
                                icon: Cpu,
                                title: 'Super Admin Operations Deck',
                                desc: 'SaaS operators inspect global portal health, grant custom plan tiers, adjust billing dates, and track AdminAuditLogs.',
                            },
                        ].map((card) => {
                            const Icon = card.icon;
                            return (
                                <Card key={card.title} className="border-slate-200/90 dark:border-slate-800">
                                    <CardHeader className="pb-2">
                                        <Icon className="mb-1.5 h-4 w-4 text-indigo-600 dark:text-indigo-400" />
                                        <CardTitle className="text-sm">{card.title}</CardTitle>
                                    </CardHeader>
                                    <CardContent className="text-muted-foreground text-xs leading-relaxed">{card.desc}</CardContent>
                                </Card>
                            );
                        })}
                    </div>

                    <div className="space-y-4 lg:col-span-5">
                        <EndpointUrlsCard appUrls={app_urls} />
                        <div className="flex flex-wrap gap-3">
                            <Button asChild className="bg-indigo-600 text-white hover:bg-indigo-700">
                                <Link href="/pricing">
                                    Compare Subscription Tiers
                                    <ArrowRight className="ml-1.5 h-4 w-4" />
                                </Link>
                            </Button>
                            <Button asChild variant="outline">
                                <a href="mailto:apps@keenenter.com">
                                    <Mail className="mr-1.5 h-4 w-4" />
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
