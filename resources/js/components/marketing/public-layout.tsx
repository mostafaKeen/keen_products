import { Link, usePage } from '@inertiajs/react';
import { ArrowRight, ExternalLink, Mail, Menu, X } from 'lucide-react';
import { type PropsWithChildren, useState } from 'react';

import AppLogoIcon from '@/components/app-logo-icon';
import { Button } from '@/components/ui/button';
import { type SharedData } from '@/types';

export interface PublicPlan {
    id: number;
    code: string;
    name: string;
    description: string | null;
    price_monthly_cents: number;
    price_yearly_cents: number;
    currency: string;
    trial_days: number;
    max_monthly_syncs: number | null;
    max_options: number;
    features_json: Record<string, boolean> | null;
}

export interface BitrixAppUrls {
    install: string;
    app: string;
    events: string;
}

export function formatMoney(cents: number, currency: string): string {
    if (cents === 0) return 'Free Trial';
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: currency || 'USD',
        maximumFractionDigits: 0,
    }).format(cents / 100);
}

const navLinks = [
    { label: 'Overview', href: '/' },
    { label: 'Architecture & Sync', href: '/features' },
    { label: 'Pricing & Plans', href: '/pricing' },
];

export default function PublicLayout({ children }: PropsWithChildren) {
    const { auth } = usePage<SharedData>().props;
    const { url } = usePage();
    const [mobileOpen, setMobileOpen] = useState(false);

    const pathname = url.split('?')[0] || '/';
    const isAdmin = Boolean(auth.user?.is_super_admin);

    return (
        <div className="flex min-h-screen flex-col bg-slate-50 text-slate-900 dark:bg-slate-950 dark:text-slate-100">
            <header className="sticky top-0 z-40 border-b border-slate-200/80 bg-white/85 backdrop-blur-md dark:border-slate-800/80 dark:bg-slate-950/85">
                <div className="mx-auto flex max-w-6xl items-center justify-between gap-4 px-6 py-3.5">
                    <Link href="/" className="flex items-center gap-2.5">
                        <div className="flex h-9 w-9 items-center justify-center rounded-lg bg-indigo-600 text-white shadow-xs dark:bg-indigo-500">
                            <AppLogoIcon className="h-5 w-5 fill-current text-white" />
                        </div>
                        <div className="leading-tight">
                            <div className="text-sm font-bold tracking-tight">KEEN Products</div>
                            <div className="text-muted-foreground text-[11px]">Bitrix24 Marketplace SaaS</div>
                        </div>
                    </Link>

                    <nav className="hidden items-center gap-1 md:flex">
                        {navLinks.map((item) => {
                            const active = pathname === item.href;
                            return (
                                <Link
                                    key={item.href}
                                    href={item.href}
                                    className={`rounded-md px-3 py-1.5 text-sm font-medium transition-colors ${
                                        active
                                            ? 'bg-indigo-50 text-indigo-700 dark:bg-indigo-950/60 dark:text-indigo-300'
                                            : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900 dark:text-slate-300 dark:hover:bg-slate-900 dark:hover:text-white'
                                    }`}
                                >
                                    {item.label}
                                </Link>
                            );
                        })}
                        <a
                            href="mailto:apps@keenenter.com"
                            className="rounded-md px-3 py-1.5 text-sm font-medium text-slate-600 hover:bg-slate-100 hover:text-slate-900 dark:text-slate-300 dark:hover:bg-slate-900 dark:hover:text-white"
                        >
                            Contact Us
                        </a>
                    </nav>

                    <div className="hidden items-center gap-2.5 text-sm md:flex">
                        {auth.user ? (
                            <Button
                                asChild
                                size="sm"
                                className="bg-indigo-600 text-white hover:bg-indigo-700 dark:bg-indigo-500 dark:hover:bg-indigo-600"
                            >
                                <Link href={isAdmin ? '/admin/dashboard' : '/dashboard'}>
                                    {isAdmin ? 'Super Admin Console' : 'Account Dashboard'}
                                    <ArrowRight className="ml-1.5 h-3.5 w-3.5" />
                                </Link>
                            </Button>
                        ) : (
                            <>
                                <Button asChild variant="ghost" size="sm">
                                    <Link href={route('login')}>Log in</Link>
                                </Button>
                                <Button
                                    asChild
                                    size="sm"
                                    className="bg-indigo-600 text-white hover:bg-indigo-700 dark:bg-indigo-500 dark:hover:bg-indigo-600"
                                >
                                    <Link href={route('register')}>
                                        Create Account
                                        <ArrowRight className="ml-1.5 h-3.5 w-3.5" />
                                    </Link>
                                </Button>
                            </>
                        )}
                    </div>

                    <button
                        type="button"
                        onClick={() => setMobileOpen((prev) => !prev)}
                        className="inline-flex h-9 w-9 items-center justify-center rounded-md border border-slate-200 text-slate-700 hover:bg-slate-100 md:hidden dark:border-slate-800 dark:text-slate-200 dark:hover:bg-slate-900"
                        aria-label="Toggle navigation menu"
                    >
                        {mobileOpen ? <X className="h-4 w-4" /> : <Menu className="h-4 w-4" />}
                    </button>
                </div>

                {mobileOpen && (
                    <div className="space-y-3 border-t border-slate-200 bg-white px-6 py-4 md:hidden dark:border-slate-800 dark:bg-slate-950">
                        <div className="flex flex-col gap-1">
                            {navLinks.map((item) => {
                                const active = pathname === item.href;
                                return (
                                    <Link
                                        key={item.href}
                                        href={item.href}
                                        onClick={() => setMobileOpen(false)}
                                        className={`rounded-md px-3 py-2 text-sm font-medium ${
                                            active
                                                ? 'bg-indigo-50 text-indigo-700 dark:bg-indigo-950/60 dark:text-indigo-300'
                                                : 'text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-900'
                                        }`}
                                    >
                                        {item.label}
                                    </Link>
                                );
                            })}
                            <a
                                href="mailto:apps@keenenter.com"
                                onClick={() => setMobileOpen(false)}
                                className="rounded-md px-3 py-2 text-sm font-medium text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-900"
                            >
                                Contact Us (apps@keenenter.com)
                            </a>
                        </div>
                        <div className="flex flex-col gap-2 border-t border-slate-200 pt-3 dark:border-slate-800">
                            {auth.user ? (
                                <Button asChild size="sm" className="w-full bg-indigo-600 text-white hover:bg-indigo-700">
                                    <Link href={isAdmin ? '/admin/dashboard' : '/dashboard'}>
                                        {isAdmin ? 'Super Admin Console' : 'Account Dashboard'}
                                    </Link>
                                </Button>
                            ) : (
                                <>
                                    <Button asChild variant="outline" size="sm" className="w-full">
                                        <Link href={route('login')}>Log in</Link>
                                    </Button>
                                    <Button asChild size="sm" className="w-full bg-indigo-600 text-white hover:bg-indigo-700">
                                        <Link href={route('register')}>Create Account</Link>
                                    </Button>
                                </>
                            )}
                        </div>
                    </div>
                )}
            </header>

            <main className="flex-1">{children}</main>

            <footer className="border-t border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-950">
                <div className="mx-auto grid max-w-6xl gap-8 px-6 py-10 md:grid-cols-12">
                    <div className="space-y-3 md:col-span-4">
                        <div className="flex items-center gap-2.5">
                            <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-indigo-600 text-white dark:bg-indigo-500">
                                <AppLogoIcon className="h-4 w-4 fill-current text-white" />
                            </div>
                            <span className="text-sm font-bold tracking-tight">KEEN Products</span>
                        </div>
                        <p className="text-muted-foreground max-w-sm text-xs leading-relaxed">
                            Multi-tenant Bitrix24 Marketplace app for dynamic Professional vs. Government fee breakdowns, automatic portal
                            custom-field mapping, and real-time CRM + SPA synchronization.
                        </p>
                    </div>

                    <div className="space-y-2.5 text-xs md:col-span-2">
                        <div className="text-foreground font-semibold">Product</div>
                        <ul className="text-muted-foreground space-y-1.5">
                            <li>
                                <Link href="/" className="hover:text-foreground">
                                    Overview
                                </Link>
                            </li>
                            <li>
                                <Link href="/features" className="hover:text-foreground">
                                    Architecture &amp; Sync
                                </Link>
                            </li>
                            <li>
                                <Link href="/pricing" className="hover:text-foreground">
                                    Pricing &amp; Tiers
                                </Link>
                            </li>
                        </ul>
                    </div>

                    <div className="space-y-2.5 text-xs md:col-span-3">
                        <div className="text-foreground font-semibold">Platform Access</div>
                        <ul className="text-muted-foreground space-y-1.5">
                            {auth.user ? (
                                <li>
                                    <Link
                                        href={isAdmin ? '/admin/dashboard' : '/dashboard'}
                                        className="hover:text-foreground inline-flex items-center gap-1"
                                    >
                                        {isAdmin ? 'Super Admin Console' : 'Account Dashboard'}
                                        <ExternalLink className="h-3 w-3" />
                                    </Link>
                                </li>
                            ) : (
                                <>
                                    <li>
                                        <Link href={route('login')} className="hover:text-foreground">
                                             Sign in to Console
                                        </Link>
                                    </li>
                                    <li>
                                        <Link href={route('register')} className="hover:text-foreground">
                                            Create SaaS Account
                                        </Link>
                                    </li>
                                    <li>
                                        <Link href={route('password.request')} className="hover:text-foreground">
                                            Reset Password
                                        </Link>
                                    </li>
                                </>
                            )}
                        </ul>
                    </div>

                    <div className="space-y-2.5 text-xs md:col-span-3">
                        <div className="text-foreground font-semibold">Contact &amp; Support</div>
                        <p className="text-muted-foreground text-xs leading-relaxed">
                            Need technical support, custom deployment, or enterprise licensing?
                        </p>
                        <a
                            href="mailto:apps@keenenter.com"
                            className="inline-flex items-center gap-1.5 font-medium text-indigo-600 hover:underline dark:text-indigo-400"
                        >
                            <Mail className="h-3.5 w-3.5" />
                            apps@keenenter.com
                        </a>
                    </div>
                </div>
                <div className="border-t border-slate-200/70 dark:border-slate-800/70">
                    <div className="text-muted-foreground mx-auto flex max-w-6xl flex-col items-center justify-between gap-2 px-6 py-4 text-xs sm:flex-row">
                        <span>&copy; {new Date().getFullYear()} KEEN Products. Built for Bitrix24 Cloud &amp; On-Premise.</span>
                        <span>Multi-tenant isolation powered by per-portal schema mapping &amp; encrypted OAuth.</span>
                    </div>
                </div>
            </footer>
        </div>
    );
}
