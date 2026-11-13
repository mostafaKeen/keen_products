import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, CheckCircle2, Lock, Mail, ShieldCheck } from 'lucide-react';

import { EndpointUrlsCard } from '@/components/marketing/endpoint-urls-card';
import PublicLayout, { type BitrixAppUrls } from '@/components/marketing/public-layout';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';

interface PrivacyPageProps {
    app_urls?: BitrixAppUrls;
}

export default function PrivacyPage({ app_urls }: PrivacyPageProps) {
    return (
        <PublicLayout>
            <Head title="Privacy Policy — KEEN Products" />

            {/* Header */}
            <section className="border-b border-slate-200/80 bg-gradient-to-b from-indigo-50/70 to-slate-50 dark:border-slate-800/80 dark:from-indigo-950/30 dark:to-slate-950">
                <div className="mx-auto max-w-4xl space-y-4 px-6 py-14">
                    <Badge
                        variant="secondary"
                        className="gap-1.5 border border-indigo-200 bg-indigo-50 text-indigo-700 dark:border-indigo-900 dark:bg-indigo-950/60 dark:text-indigo-300"
                    >
                        <Lock className="h-3.5 w-3.5" />
                        Data Privacy &amp; Protection
                    </Badge>
                    <h1 className="text-3xl font-bold tracking-tight sm:text-4xl">
                        Privacy Policy
                    </h1>
                    <p className="text-muted-foreground text-sm sm:text-base">
                        How <strong>KEEN Products</strong> handles, secures, and protects your information when used within Bitrix24.
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
                {/* Privacy Highlights Card */}
                <Card className="border-indigo-100 bg-indigo-50/40 shadow-xs dark:border-indigo-950/60 dark:bg-indigo-950/20">
                    <CardHeader className="pb-3">
                        <div className="flex items-center gap-2">
                            <ShieldCheck className="h-5 w-5 text-indigo-600 dark:text-indigo-400" />
                            <CardTitle className="text-base text-slate-900 dark:text-slate-100">Our Privacy Principles</CardTitle>
                        </div>
                    </CardHeader>
                    <CardContent className="space-y-2 text-xs">
                        <div className="flex items-start gap-2">
                            <CheckCircle2 className="mt-0.5 h-3.5 w-3.5 shrink-0 text-emerald-600" />
                            <span><strong>No Data Selling:</strong> We never sell, lease, or monetize your Bitrix24 CRM records or business data.</span>
                        </div>
                        <div className="flex items-start gap-2">
                            <CheckCircle2 className="mt-0.5 h-3.5 w-3.5 shrink-0 text-emerald-600" />
                            <span><strong>Encrypted In Transit &amp; At Rest:</strong> All OAuth tokens are encrypted with AES-256-CBC, and communication takes place over HTTPS.</span>
                        </div>
                        <div className="flex items-start gap-2">
                            <CheckCircle2 className="mt-0.5 h-3.5 w-3.5 shrink-0 text-emerald-600" />
                            <span><strong>Minimal Data Processing:</strong> We only process the specific CRM product lines and custom properties required to calculate fee subtotals and sync SPAs.</span>
                        </div>
                    </CardContent>
                </Card>

                {/* Section 1 */}
                <section className="space-y-3">
                    <h2 className="text-xl font-bold text-slate-900 dark:text-white">1. Introduction &amp; Scope</h2>
                    <p>
                        This Privacy Policy describes how <strong>KEEN Enterprises</strong> (&ldquo;we&rdquo;, &ldquo;us&rdquo;, or &ldquo;our&rdquo;) collects, uses, and safeguards information through the <strong>KEEN Products</strong> application (&ldquo;Application&rdquo;) designed for the Bitrix24 Marketplace.
                    </p>
                    <p>
                        This policy applies to all Bitrix24 Cloud and On-Premise (Self-Hosted) portals where the Application is installed. By installing or utilizing the Application, you acknowledge and consent to the data practices described in this document.
                    </p>
                </section>

                {/* Section 2 */}
                <section className="space-y-3">
                    <h2 className="text-xl font-bold text-slate-900 dark:text-white">2. Information We Process</h2>
                    <p>
                        To operate fee calculations and synchronize Bitrix24 CRM entities and Smart Process Applications, the Application processes:
                    </p>
                    <ul className="list-disc space-y-2 pl-6 text-xs sm:text-sm">
                        <li>
                            <strong>Bitrix24 Portal Identity &amp; OAuth Credentials:</strong> Member ID, Bitrix24 domain name, client REST endpoint, and encrypted OAuth access and refresh tokens.
                        </li>
                        <li>
                            <strong>User Identity for Seat Licensing:</strong> Bitrix24 User ID, first name, last name, and email address obtained via the authorized Bitrix24 session to confirm that the viewer has been allocated a licensed seat.
                        </li>
                        <li>
                            <strong>CRM Product Row &amp; Custom Property Data:</strong> Product IDs, names, prices, quantities, tax rates, and associated Catalog properties (such as Option groups and Cost Types) necessary to calculate subtotals and mirror line items.
                        </li>
                        <li>
                            <strong>Smart Process Application (SPA) Mappings:</strong> Item IDs and configured field bindings for target SPAs associated with your portal.
                        </li>
                        <li>
                            <strong>Operational Logs:</strong> Transaction timestamps, entity identifiers, execution durations, and HTTP API call counts stored for quota tracking and diagnostic support.
                        </li>
                    </ul>
                </section>

                {/* Section 3 */}
                <section className="space-y-3">
                    <h2 className="text-xl font-bold text-slate-900 dark:text-white">3. How We Use the Information</h2>
                    <p>We process your information strictly for the following purposes:</p>
                    <ul className="list-disc space-y-1.5 pl-6 text-xs sm:text-sm">
                        <li>To provide the quoting grid interface inside Bitrix24 CRM detail tabs;</li>
                        <li>To automatically calculate Professional vs. Government fee breakdowns across Options 1..N;</li>
                        <li>To synchronize product row values into configured Bitrix24 Smart Process Applications in real time;</li>
                        <li>To enforce per-seat user license allocations and monthly sync quotas;</li>
                        <li>To diagnose sync discrepancies and provide prompt technical assistance when contacted.</li>
                    </ul>
                </section>

                {/* Section 4 */}
                <section className="space-y-3">
                    <h2 className="text-xl font-bold text-slate-900 dark:text-white">4. Data Security &amp; Architectural Safeguards</h2>
                    <p>
                        We employ enterprise-grade security measures to safeguard all portal credentials and data transactions:
                    </p>
                    <ul className="list-disc space-y-1.5 pl-6 text-xs sm:text-sm">
                        <li>
                            <strong>Token Encryption:</strong> OAuth access tokens and refresh tokens are encrypted at rest using AES-256-CBC cipher suites.
                        </li>
                        <li>
                            <strong>Scoped Placement JWTs:</strong> Embedded iframes exchange initial Bitrix auth parameters once for short-lived, signed HS256 JSON Web Tokens.
                        </li>
                        <li>
                            <strong>SSRF &amp; Rebinding Protection:</strong> REST requests validate host domains against strict Bitrix24 allowlists and reject internal or private IP address ranges.
                        </li>
                        <li>
                            <strong>Content Security Policy (CSP):</strong> Iframe embedding is strictly locked to verified Bitrix24 cloud hosts and explicitly allowlisted self-hosted domains using <code>frame-ancestors</code> directives.
                        </li>
                    </ul>
                </section>

                {/* Section 5 */}
                <section className="space-y-3">
                    <h2 className="text-xl font-bold text-slate-900 dark:text-white">5. Data Sharing &amp; Third Parties</h2>
                    <p>
                        We do not sell, rent, trade, or share your data with advertisers or marketing platforms. Information is only communicated directly between your Bitrix24 portal and the secure application server via encrypted HTTPS APIs.
                    </p>
                </section>

                {/* Section 6 */}
                <section className="space-y-3">
                    <h2 className="text-xl font-bold text-slate-900 dark:text-white">6. Data Retention &amp; Erasure</h2>
                    <p>
                        We retain tenant metadata and synchronization logs only for as long as your portal maintains an active installation or subscription:
                    </p>
                    <ul className="list-disc space-y-1.5 pl-6 text-xs sm:text-sm">
                        <li>
                            <strong>Application Uninstallation:</strong> When an administrator uninstalls the Application via Bitrix24 Marketplace, active OAuth credentials are immediately invalidated.
                        </li>
                        <li>
                            <strong>Right to Erasure:</strong> Customers may request permanent deletion of all portal configuration records and sync history at any time by emailing <a href="mailto:apps@keenenter.com" className="text-indigo-600 underline dark:text-indigo-400">apps@keenenter.com</a>.
                        </li>
                    </ul>
                </section>

                {/* Section 7 */}
                <section className="space-y-3">
                    <h2 className="text-xl font-bold text-slate-900 dark:text-white">7. Your Rights (GDPR &amp; International Standards)</h2>
                    <p>
                        Depending on your jurisdiction, you have the right to access, rectify, restrict, or request deletion of personal information processed by the Application. Portal administrators can modify seat allocations directly from the Application Settings at any time.
                    </p>
                </section>

                {/* Section 8 */}
                <section className="space-y-3">
                    <h2 className="text-xl font-bold text-slate-900 dark:text-white">8. Contact Information</h2>
                    <p>
                        If you have questions regarding this Privacy Policy or wish to exercise your data rights, please contact our Data Protection and Support Team:
                    </p>
                    <div className="rounded-lg border border-slate-200 bg-slate-50 p-4 text-xs dark:border-slate-800 dark:bg-slate-900">
                        <div className="font-semibold text-slate-900 dark:text-slate-100">KEEN Enterprises — Data Privacy &amp; Support</div>
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
                        <Link href="/eula">View EULA / Terms</Link>
                    </Button>
                </div>
            </div>
        </PublicLayout>
    );
}

