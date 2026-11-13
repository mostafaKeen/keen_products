import { Link } from '@inertiajs/react';
import { ArrowLeft, CheckCircle2, Layers, ShieldCheck } from 'lucide-react';

import AppLogoIcon from '@/components/app-logo-icon';
import { Badge } from '@/components/ui/badge';

interface AuthLayoutProps {
    children: React.ReactNode;
    title?: string;
    description?: string;
}

const highlights = [
    'Per-portal Catalog property & UF_CRM_* schema crosswalk',
    'Atomic Option 1..N Professional vs. Government fee breakdown',
    'Bidirectional CRM <-> SPA synchronization with loop guards',
    'Super Admin subscription governance & immutable audit logs',
];

export default function AuthSplitLayout({ children, title, description }: AuthLayoutProps) {
    return (
        <div className="relative grid min-h-dvh flex-col bg-slate-50 lg:grid-cols-2 dark:bg-slate-950">
            {/* Left SaaS Brand Showcase */}
            <div className="relative hidden flex-col justify-between overflow-hidden border-r border-slate-800 bg-slate-950 p-10 text-white lg:flex">
                <div
                    className="pointer-events-none absolute inset-0 opacity-70"
                    style={{
                        background:
                            'radial-gradient(circle at 20% 20%, rgba(79, 70, 229, 0.28), transparent 55%), radial-gradient(circle at 80% 80%, rgba(99, 102, 241, 0.16), transparent 50%)',
                    }}
                />

                <div className="relative z-10 flex items-center justify-between">
                    <Link href={route('home')} className="flex items-center gap-2.5">
                        <div className="flex h-9 w-9 items-center justify-center rounded-lg bg-indigo-600 text-white shadow-sm">
                            <AppLogoIcon className="h-5 w-5 fill-current text-white" />
                        </div>
                        <div className="leading-tight">
                            <div className="text-sm font-bold tracking-tight text-white">KEEN Products</div>
                            <div className="text-[11px] text-slate-400">Bitrix24 Marketplace SaaS</div>
                        </div>
                    </Link>

                    <div className="flex items-center gap-4 text-xs text-slate-300">
                        <Link href="/features" className="hover:text-white">
                            Architecture
                        </Link>
                        <Link href="/pricing" className="hover:text-white">
                            Pricing
                        </Link>
                    </div>
                </div>

                <div className="relative z-10 my-auto max-w-md space-y-6">
                    <Badge className="gap-1.5 border border-indigo-400/30 bg-indigo-500/15 text-indigo-200 hover:bg-indigo-500/15">
                        <Layers className="h-3.5 w-3.5" />
                        Multi-Tenant Control Plane
                    </Badge>

                    <div className="space-y-3">
                        <h2 className="text-2xl font-bold tracking-tight text-white sm:text-3xl">
                            KEEN Products &amp; Schema Automation for Bitrix24
                        </h2>
                        <p className="text-sm leading-relaxed text-slate-300">
                            Manage subscription tiers, inspect Bitrix24 Cloud &amp; On-Premise portal integrations, and govern automated CRM &amp; SPA
                            synchronization from one unified console.
                        </p>
                    </div>

                    <ul className="space-y-3 text-sm text-slate-200">
                        {highlights.map((item) => (
                            <li key={item} className="flex items-start gap-2.5">
                                <CheckCircle2 className="mt-0.5 h-4 w-4 shrink-0 text-emerald-400" />
                                <span>{item}</span>
                            </li>
                        ))}
                    </ul>
                </div>

                <div className="relative z-10 flex items-center justify-between border-t border-slate-800/90 pt-5 text-xs text-slate-400">
                    <div className="flex items-center gap-2">
                        <ShieldCheck className="h-4 w-4 text-indigo-400" />
                        <span>Encrypted OAuth &bull; HS256 Placement JWTs &bull; CSP Frame Guard</span>
                    </div>
                </div>
            </div>

            {/* Right Auth Form Container */}
            <div className="flex flex-col justify-between px-6 py-8 sm:px-10">
                <div className="flex items-center justify-between">
                    <Link href={route('home')} className="flex items-center gap-2 lg:hidden">
                        <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-indigo-600 text-white">
                            <AppLogoIcon className="h-4 w-4 fill-current text-white" />
                        </div>
                        <span className="text-foreground text-sm font-bold tracking-tight">KEEN Products</span>
                    </Link>

                    <Link
                        href={route('home')}
                        className="text-muted-foreground hover:text-foreground ml-auto inline-flex items-center gap-1.5 text-xs font-medium transition-colors"
                    >
                        <ArrowLeft className="h-3.5 w-3.5" />
                        Back to KEEN Products
                    </Link>
                </div>

                <div className="mx-auto my-auto flex w-full max-w-md flex-col py-8">
                    <div className="bg-card rounded-xl border border-slate-200/90 p-6 shadow-sm sm:p-8 dark:border-slate-800">
                        <div className="mb-6 space-y-1.5 text-left">
                            <h1 className="text-foreground text-xl font-bold tracking-tight">{title}</h1>
                            {description && <p className="text-muted-foreground text-sm">{description}</p>}
                        </div>
                        {children}
                    </div>
                </div>

                <div className="text-muted-foreground space-y-1 text-center text-xs">
                    <div>Bitrix24 portal administrators can also launch directly from their Bitrix24 left menu placement.</div>
                    <div>
                        Need help or enterprise onboarding? Contact us at{' '}
                        <a href="mailto:apps@keenenter.com" className="text-foreground hover:underline font-medium">
                            apps@keenenter.com
                        </a>
                    </div>
                </div>
            </div>
        </div>
    );
}
