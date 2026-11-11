import { Head } from '@inertiajs/react';
import { AlertCircle, ArrowRight, CheckCircle2, Loader2, Rocket, ShieldCheck, Sliders } from 'lucide-react';
import { useEffect, useState } from 'react';

import { SchemaMapper, type PortalTenantSchema } from '@/components/bitrix/schema-mapper';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { bitrixFetch, loadBx24Script } from '@/lib/bitrix-api';

interface PlanCard {
    id: number;
    code: string;
    name: string;
    description: string | null;
    price_monthly_cents: number;
    price_yearly_cents: number;
    currency: string;
    max_monthly_syncs: number | null;
    max_options: number;
    features_json: Record<string, boolean> | null;
}

interface Props {
    error: string | null;
    jwt: string | null;
    bx24_script_url?: string;
    tenant:
        | (PortalTenantSchema & {
              id: number;
              member_id: string;
              domain: string;
              app_status: string;
              install_status: string;
              license_type?: string | null;
          })
        | null;
    subscription?: {
        allowed: boolean;
        status: string;
        plan?: { id: number; name: string; max_options: number; max_monthly_syncs: number | null };
        max_options: number;
        features: Record<string, boolean>;
    };
    plans: PlanCard[];
    defaults?: {
        options_config: Array<{ key: string; label: string; sort: number; is_enabled: boolean }>;
        cost_types_config: Array<{ key: string; label: string; sort: number; is_enabled: boolean }>;
        option_meta_schema: Array<{ key: string; label: string; type: string }>;
    };
}

export default function BitrixInstallWizard({ error, jwt, bx24_script_url, tenant: initialTenant, subscription, plans, defaults }: Props) {
    const [step, setStep] = useState<1 | 2 | 3>(1);
    const [tenantState, setTenantState] = useState(initialTenant);
    const [completing, setCompleting] = useState(false);
    const [completeResult, setCompleteResult] = useState<{
        install_status: string;
        bindings?: { placements?: string[]; events?: string[]; warnings?: string[] };
    } | null>(null);
    const [completeError, setCompleteError] = useState<string | null>(null);

    useEffect(() => {
        loadBx24Script(bx24_script_url).then((loaded) => {
            if (loaded && window.BX24) {
                window.BX24.init(() => {
                    window.BX24?.fitWindow();
                });
            }
        });
    }, [bx24_script_url]);

    useEffect(() => {
        if (typeof window !== 'undefined' && window.BX24) {
            window.setTimeout(() => window.BX24?.fitWindow(), 100);
        }
    }, [step, completeResult]);

    const handleCompleteInstall = async (triggerFinishSdk: boolean) => {
        if (!jwt) return;
        setCompleting(true);
        setCompleteError(null);
        try {
            const res = await bitrixFetch<{
                success: boolean;
                install_status: string;
                bindings?: { placements?: string[]; events?: string[]; warnings?: string[] };
            }>('/api/bitrix/portal/complete-install', jwt, {});
            setCompleteResult(res);
            if (triggerFinishSdk && typeof window !== 'undefined' && window.BX24) {
                window.BX24.installFinish();
            }
        } catch (e) {
            setCompleteError((e as Error).message);
        } finally {
            setCompleting(false);
        }
    };

    if (error || !tenantState || !jwt) {
        return (
            <div className="min-h-screen bg-slate-50 p-6 dark:bg-slate-950">
                <Head title="Installation Wizard" />
                <div className="mx-auto max-w-2xl space-y-4">
                    <Alert variant="destructive">
                        <AlertCircle className="h-4 w-4" />
                        <AlertTitle>Installation Verification Required</AlertTitle>
                        <AlertDescription>
                            {error || 'Launch this Installation Wizard directly from your Bitrix24 Marketplace installation.'}
                        </AlertDescription>
                    </Alert>
                </div>
            </div>
        );
    }

    const steps = [
        { num: 1, title: 'Portal & Trial', icon: ShieldCheck },
        { num: 2, title: 'Schema Setup', icon: Sliders },
        { num: 3, title: 'Activate Tabs', icon: Rocket },
    ] as const;

    return (
        <div className="min-h-screen bg-slate-50 p-6 text-slate-900 dark:bg-slate-950 dark:text-slate-100">
            <Head title={`Install Fee Matrix — ${tenantState.domain}`} />

            <div className="mx-auto max-w-5xl space-y-6">
                {/* Header */}
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-bold tracking-tight">Bitrix24 Fee Breakdown Installer</h1>
                        <p className="text-muted-foreground text-sm">
                            Portal: <span className="text-foreground font-medium">{tenantState.domain}</span> · Member ID:{' '}
                            <span className="font-mono text-xs">{tenantState.member_id}</span>
                        </p>
                    </div>
                    <Badge variant="secondary" className="text-xs uppercase">
                        Status: {tenantState.install_status}
                    </Badge>
                </div>

                {/* Stepper */}
                <div className="grid grid-cols-3 gap-3">
                    {steps.map((s) => {
                        const Icon = s.icon;
                        const active = step === s.num;
                        const done = step > s.num || Boolean(completeResult);
                        return (
                            <button
                                key={s.num}
                                type="button"
                                onClick={() => setStep(s.num)}
                                className={`flex items-center gap-3 rounded-lg border p-3 text-left transition ${
                                    active ? 'border-indigo-600 bg-indigo-50/70 dark:bg-indigo-950/30' : 'bg-card hover:bg-muted/40'
                                }`}
                            >
                                <div
                                    className={`flex h-8 w-8 items-center justify-center rounded-full text-xs font-semibold ${
                                        done ? 'bg-emerald-600 text-white' : active ? 'bg-indigo-600 text-white' : 'bg-muted text-muted-foreground'
                                    }`}
                                >
                                    {done ? <CheckCircle2 className="h-4 w-4" /> : <Icon className="h-4 w-4" />}
                                </div>
                                <div>
                                    <div className="text-muted-foreground text-xs">Step {s.num}</div>
                                    <div className="text-sm font-semibold">{s.title}</div>
                                </div>
                            </button>
                        );
                    })}
                </div>

                {/* Step 1: Verification & Plan Tiers */}
                {step === 1 && (
                    <div className="space-y-4">
                        <Card>
                            <CardHeader>
                                <CardTitle>Portal Connected & Authenticated</CardTitle>
                                <CardDescription>
                                    Your Bitrix24 OAuth tokens and portal identity are verified. Your portal begins on the{' '}
                                    <strong>{subscription?.plan?.name ?? '14-Day Free Trial'}</strong> ({subscription?.status ?? 'trialing'}).
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                <div className="grid gap-3 md:grid-cols-3">
                                    <div className="rounded-md border p-3 text-sm">
                                        <div className="text-muted-foreground text-xs">Bitrix24 Domain</div>
                                        <div className="font-semibold">{tenantState.domain}</div>
                                    </div>
                                    <div className="rounded-md border p-3 text-sm">
                                        <div className="text-muted-foreground text-xs">Plan Limits</div>
                                        <div className="font-semibold">
                                            Up to {subscription?.max_options ?? 2} Options · {subscription?.plan?.max_monthly_syncs ?? 'Unlimited'}{' '}
                                            syncs/mo
                                        </div>
                                    </div>
                                    <div className="rounded-md border p-3 text-sm">
                                        <div className="text-muted-foreground text-xs">Marketplace Code</div>
                                        <div className="font-mono font-semibold">{tenantState.app_status}</div>
                                    </div>
                                </div>

                                {plans.length > 0 && (
                                    <div className="space-y-2 pt-2">
                                        <div className="text-muted-foreground text-xs font-semibold uppercase">Available Subscription Tiers</div>
                                        <div className="grid gap-3 md:grid-cols-4">
                                            {plans.map((p) => (
                                                <div key={p.id} className="rounded-md border p-3 text-xs">
                                                    <div className="text-sm font-semibold">{p.name}</div>
                                                    <div className="mt-1 font-mono text-indigo-600 dark:text-indigo-400">
                                                        {p.price_monthly_cents === 0
                                                            ? 'Free Trial'
                                                            : `$${(p.price_monthly_cents / 100).toFixed(0)}/mo`}
                                                    </div>
                                                    <div className="text-muted-foreground mt-1">
                                                        {p.max_monthly_syncs ?? 'Unlimited'} syncs · {p.max_options} options
                                                    </div>
                                                </div>
                                            ))}
                                        </div>
                                    </div>
                                )}

                                <div className="flex justify-end pt-2">
                                    <Button onClick={() => setStep(2)}>
                                        Continue to Schema Setup
                                        <ArrowRight className="ml-1.5 h-4 w-4" />
                                    </Button>
                                </div>
                            </CardContent>
                        </Card>
                    </div>
                )}

                {/* Step 2: Provisioning & Custom Mapping */}
                {step === 2 && (
                    <div className="space-y-4">
                        <SchemaMapper
                            jwt={jwt}
                            tenant={tenantState}
                            maxOptions={subscription?.max_options ?? 2}
                            features={subscription?.features ?? {}}
                            defaults={defaults}
                            onUpdated={(patch) => {
                                setTenantState((prev) => (prev ? { ...prev, ...patch } : prev));
                            }}
                        />

                        <div className="flex items-center justify-between">
                            <Button variant="outline" onClick={() => setStep(1)}>
                                Back
                            </Button>
                            <Button onClick={() => setStep(3)}>
                                Proceed to Step 3: Finalize Installation
                                <ArrowRight className="ml-1.5 h-4 w-4" />
                            </Button>
                        </div>
                    </div>
                )}

                {/* Step 3: Complete Install & BX24.installFinish() */}
                {step === 3 && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Complete Installation & Activate Placements</CardTitle>
                            <CardDescription>
                                Registers CRM entity tab placements (Leads, Deals, Quotes, SPAs) and background event handlers so the Fee Breakdown
                                tab renders live inside your Bitrix24 CRM records.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            {completeError && (
                                <Alert variant="destructive">
                                    <AlertCircle className="h-4 w-4" />
                                    <AlertDescription>{completeError}</AlertDescription>
                                </Alert>
                            )}

                            {completeResult ? (
                                <Alert>
                                    <CheckCircle2 className="h-4 w-4 text-emerald-600" />
                                    <AlertTitle>Installation Completed!</AlertTitle>
                                    <AlertDescription className="space-y-2">
                                        <p>
                                            Portal status is now <strong>{completeResult.install_status}</strong>. You can now open any configured
                                            Deal, Lead, or Quote in Bitrix24 and use the <strong>Fee Breakdown</strong> tab.
                                        </p>
                                        {completeResult.bindings?.placements && (
                                            <div className="text-xs">
                                                <strong>Active Placements:</strong> {completeResult.bindings.placements.join(', ') || 'None yet'}
                                            </div>
                                        )}
                                    </AlertDescription>
                                </Alert>
                            ) : (
                                <div className="bg-muted/30 flex flex-wrap items-center justify-between gap-3 rounded-md border p-4">
                                    <div className="text-sm">
                                        Schema status: <strong className="uppercase">{tenantState.schema_status}</strong> · Mode:{' '}
                                        <span className="font-mono">{tenantState.fee_sync_mode}</span>
                                    </div>
                                    <div className="flex gap-2">
                                        <Button variant="outline" disabled={completing} onClick={() => handleCompleteInstall(false)}>
                                            Save & Activate Only
                                        </Button>
                                        <Button disabled={completing} onClick={() => handleCompleteInstall(true)}>
                                            {completing && <Loader2 className="mr-1.5 h-4 w-4 animate-spin" />}
                                            Finish Installation
                                        </Button>
                                    </div>
                                </div>
                            )}
                        </CardContent>
                    </Card>
                )}
            </div>
        </div>
    );
}
