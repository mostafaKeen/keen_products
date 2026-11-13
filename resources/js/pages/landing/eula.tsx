import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, CheckCircle2, FileText, Mail, ShieldCheck } from 'lucide-react';

import { EndpointUrlsCard } from '@/components/marketing/endpoint-urls-card';
import PublicLayout, { type BitrixAppUrls } from '@/components/marketing/public-layout';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';

interface EulaPageProps {
    app_urls?: BitrixAppUrls;
}

export default function EulaPage({ app_urls }: EulaPageProps) {
    return (
        <PublicLayout>
            <Head title="End User License Agreement (EULA) — KEEN Products" />

            {/* Header */}
            <section className="border-b border-slate-200/80 bg-gradient-to-b from-indigo-50/70 to-slate-50 dark:border-slate-800/80 dark:from-indigo-950/30 dark:to-slate-950">
                <div className="mx-auto max-w-4xl space-y-4 px-6 py-14">
                    <Badge
                        variant="secondary"
                        className="gap-1.5 border border-indigo-200 bg-indigo-50 text-indigo-700 dark:border-indigo-900 dark:bg-indigo-950/60 dark:text-indigo-300"
                    >
                        <FileText className="h-3.5 w-3.5" />
                        Legal Agreement
                    </Badge>
                    <h1 className="text-3xl font-bold tracking-tight sm:text-4xl">
                        End User License Agreement (EULA)
                    </h1>
                    <p className="text-muted-foreground text-sm sm:text-base">
                        Terms of Service and License Conditions for the <strong>KEEN Products</strong> Application on the Bitrix24 Marketplace.
                    </p>
                    <div className="text-muted-foreground flex flex-wrap items-center gap-4 pt-1 text-xs">
                        <span>Last Updated: November 2026</span>
                        <span>&bull;</span>
                        <span>Publisher: KEEN Enterprises</span>
                        <span>&bull;</span>
                        <a href="mailto:apps@keenenter.com" className="text-indigo-600 hover:underline dark:text-indigo-400">
                            apps@keenenter.com
                        </a>
                    </div>
                </div>
            </section>

            <div className="mx-auto max-w-4xl space-y-10 px-6 py-12 text-sm leading-relaxed text-slate-700 dark:text-slate-300">
                {/* Highlights Summary Card */}
                <Card className="border-indigo-100 bg-indigo-50/40 shadow-xs dark:border-indigo-950/60 dark:bg-indigo-950/20">
                    <CardHeader className="pb-3">
                        <div className="flex items-center gap-2">
                            <ShieldCheck className="h-5 w-5 text-indigo-600 dark:text-indigo-400" />
                            <CardTitle className="text-base text-slate-900 dark:text-slate-100">Key Highlights for Bitrix24 Administrators</CardTitle>
                        </div>
                    </CardHeader>
                    <CardContent className="space-y-2 text-xs">
                        <div className="flex items-start gap-2">
                            <CheckCircle2 className="mt-0.5 h-3.5 w-3.5 shrink-0 text-emerald-600" />
                            <span><strong>7-Day Free Trial:</strong> All new Bitrix24 installations automatically receive an evaluation period with no upfront commitment.</span>
                        </div>
                        <div className="flex items-start gap-2">
                            <CheckCircle2 className="mt-0.5 h-3.5 w-3.5 shrink-0 text-emerald-600" />
                            <span><strong>Per-Seat User Control:</strong> Licensed seats are allocated in the Admin Portal. Only authorized users can access the Fee Grid and quoting widgets.</span>
                        </div>
                        <div className="flex items-start gap-2">
                            <CheckCircle2 className="mt-0.5 h-3.5 w-3.5 shrink-0 text-emerald-600" />
                            <span><strong>Your Data Stays Yours:</strong> Your CRM Deals, Leads, Quotes, and Smart Processes remain stored within your Bitrix24 account.</span>
                        </div>
                    </CardContent>
                </Card>

                {/* Section 1 */}
                <section className="space-y-3">
                    <h2 className="text-xl font-bold text-slate-900 dark:text-white">1. Agreement to Terms</h2>
                    <p>
                        This End User License Agreement (&ldquo;EULA&rdquo; or &ldquo;Agreement&rdquo;) is a binding legal contract between <strong>KEEN Enterprises</strong> (&ldquo;we&rdquo;, &ldquo;us&rdquo;, or &ldquo;our&rdquo;) and the entity or individual (&ldquo;Customer&rdquo;, &ldquo;you&rdquo;, or &ldquo;Licensee&rdquo;) installing, configuring, or using the <strong>KEEN Products</strong> application (&ldquo;Application&rdquo;) from the Bitrix24 Marketplace.
                    </p>
                    <p>
                        By installing, authorizing through OAuth, or using the Application within your Bitrix24 Cloud or On-Premise instance, you agree to be bound by all terms in this Agreement. If you are acting on behalf of a company or organization, you represent that you have legal authority to bind that entity.
                    </p>
                </section>

                {/* Section 2 */}
                <section className="space-y-3">
                    <h2 className="text-xl font-bold text-slate-900 dark:text-white">2. Scope &amp; Grant of License</h2>
                    <p>
                        Subject to the terms of this Agreement and active subscription status, KEEN Enterprises grants you a limited, non-exclusive, non-transferable, revocable license to install and execute the Application solely inside the Bitrix24 portal domain associated with your installation.
                    </p>
                    <p>
                        The Application provides multi-option fee calculations, custom property schema adaptation, and bidirectional synchronization with Bitrix24 Smart Process Applications (SPAs).
                    </p>
                </section>

                {/* Section 3 */}
                <section className="space-y-3">
                    <h2 className="text-xl font-bold text-slate-900 dark:text-white">3. User Seats &amp; Access Governance</h2>
                    <p>
                        Depending on your active plan (such as the Per-User Plan at $5/user/month or fixed organization tiers), access to the Application&rsquo;s CRM tab widgets, calculations, and settings is governed per designated Bitrix24 user seat:
                    </p>
                    <ul className="list-disc space-y-1.5 pl-6 text-xs sm:text-sm">
                        <li>
                            <strong>Licensed User Assignment:</strong> Administrators assign specific Bitrix24 User IDs via the Application Settings or Admin Console.
                        </li>
                        <li>
                            <strong>Unselected Users:</strong> Users who have not been allocated a licensed seat are blocked from loading the widget and fee tools with an explicit notice to contact their administrator.
                        </li>
                        <li>
                            <strong>Account Integrity:</strong> You agree not to share Bitrix24 login credentials to circumvent per-user seat limits or license verification.
                        </li>
                    </ul>
                </section>

                {/* Section 4 */}
                <section className="space-y-3">
                    <h2 className="text-xl font-bold text-slate-900 dark:text-white">4. 7-Day Free Trial, Billing &amp; Renewals</h2>
                    <p>
                        Each newly provisioned Bitrix24 portal starts with an automated <strong>7-Day Free Trial</strong> enabling evaluation of fee grids and SPA sync pipelines:
                    </p>
                    <ul className="list-disc space-y-1.5 pl-6 text-xs sm:text-sm">
                        <li>
                            <strong>Trial Duration:</strong> The trial commences upon initial Marketplace installation and expires after seven (7) calendar days.
                        </li>
                        <li>
                            <strong>Grace Period:</strong> Expired subscriptions enter a 7-day grace window allowing plan upgrades before background synchronizations are halted.
                        </li>
                        <li>
                            <strong>Subscription Upgrades:</strong> Upgrades and seat adjustments can be requested from the embedded Application Settings or by contacting <a href="mailto:apps@keenenter.com" className="text-indigo-600 underline dark:text-indigo-400">apps@keenenter.com</a>.
                        </li>
                    </ul>
                </section>

                {/* Section 5 */}
                <section className="space-y-3">
                    <h2 className="text-xl font-bold text-slate-900 dark:text-white">5. Bitrix24 Data Ownership &amp; Security</h2>
                    <p>
                        You retain full, exclusive ownership of all CRM Deals, Leads, Quotes, Catalog products, and custom fields residing inside your Bitrix24 instance.
                    </p>
                    <p>
                        KEEN Products operates as an authorized processing agent over the Bitrix24 REST API. All OAuth tokens and secrets are stored using AES-256-CBC encryption, and embedded iframe communications are authenticated with short-lived HS256 JWT tokens. We do not sell, rent, or disclose your CRM information to third parties.
                    </p>
                </section>

                {/* Section 6 */}
                <section className="space-y-3">
                    <h2 className="text-xl font-bold text-slate-900 dark:text-white">6. Prohibited Uses</h2>
                    <p>Licensee shall not:</p>
                    <ul className="list-disc space-y-1.5 pl-6 text-xs sm:text-sm">
                        <li>Reverse-engineer, decompile, or disassemble any part of the Application backend;</li>
                        <li>Attempt to bypass license gates, quota meters, or seat assignment verification;</li>
                        <li>Falsify Bitrix24 domain origins, signatures (APPLICATION_TOKEN), or member IDs;</li>
                        <li>Use the Application for unlawful purposes or in violation of Bitrix24 Marketplace guidelines.</li>
                    </ul>
                </section>

                {/* Section 7 */}
                <section className="space-y-3">
                    <h2 className="text-xl font-bold text-slate-900 dark:text-white">7. Disclaimer of Warranties &amp; Limitation of Liability</h2>
                    <p>
                        THE APPLICATION IS PROVIDED ON AN &ldquo;AS IS&rdquo; AND &ldquo;AS AVAILABLE&rdquo; BASIS. TO THE MAXIMUM EXTENT PERMITTED BY LAW, KEEN ENTERPRISES DISCLAIMS ALL WARRANTIES, EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE, AND NON-INFRINGEMENT.
                    </p>
                    <p>
                        IN NO EVENT SHALL KEEN ENTERPRISES BE LIABLE FOR INDIRECT, INCIDENTAL, CONSEQUENTIAL, SPECIAL, OR PUNITIVE DAMAGES, OR FOR LOSS OF PROFITS, DATA, OR BUSINESS INTERRUPTION ARISING OUT OF THE USE OF OR INABILITY TO USE THE APPLICATION.
                    </p>
                </section>

                {/* Section 8 */}
                <section className="space-y-3">
                    <h2 className="text-xl font-bold text-slate-900 dark:text-white">8. Termination</h2>
                    <p>
                        This Agreement remains effective until terminated. You may terminate this Agreement at any time by uninstalling the Application from your Bitrix24 Marketplace Applications menu. Upon uninstallation, all active OAuth access credentials are immediately revoked.
                    </p>
                    <p>
                        We reserve the right to suspend or terminate portal access upon material breach of this Agreement, non-payment, or security violations.
                    </p>
                </section>

                {/* Section 9 */}
                <section className="space-y-3">
                    <h2 className="text-xl font-bold text-slate-900 dark:text-white">9. Governing Law &amp; Contact</h2>
                    <p>
                        For legal notices, billing questions, or licensing assistance, please contact:
                    </p>
                    <div className="rounded-lg border border-slate-200 bg-slate-50 p-4 text-xs dark:border-slate-800 dark:bg-slate-900">
                        <div className="font-semibold text-slate-900 dark:text-slate-100">KEEN Enterprises — KEEN Products Support</div>
                        <div className="mt-1 flex items-center gap-1.5 text-slate-600 dark:text-slate-400">
                            <Mail className="h-3.5 w-3.5 text-indigo-600 dark:text-indigo-400" />
                            Email: <a href="mailto:apps@keenenter.com" className="font-medium text-indigo-600 hover:underline dark:text-indigo-400">apps@keenenter.com</a>
                        </div>
                    </div>
                </section>

                {/* Developer Area URLs Card */}
                {app_urls && (
                    <section className="pt-4">
                        <EndpointUrlsCard appUrls={app_urls} />
                    </section>
                )}

                <div className="flex items-center justify-between border-t border-slate-200 pt-6 dark:border-slate-800">
                    <Button asChild variant="outline" size="sm">
                        <Link href="/">
                            <ArrowLeft className="mr-1.5 h-3.5 w-3.5" />
                            Back to Overview
                        </Link>
                    </Button>
                    <Button asChild size="sm" className="bg-indigo-600 text-white hover:bg-indigo-700">
                        <Link href="/privacy">View Privacy Policy</Link>
                    </Button>
                </div>
            </div>
        </PublicLayout>
    );
}

