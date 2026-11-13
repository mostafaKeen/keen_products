import { Check, Copy, Link2 } from 'lucide-react';
import { useState } from 'react';

import { type BitrixAppUrls } from '@/components/marketing/public-layout';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';

export function EndpointUrlsCard({ appUrls }: { appUrls?: BitrixAppUrls }) {
    const [copiedKey, setCopiedKey] = useState<string | null>(null);

    const rows = [
        { key: 'install', label: 'Install Handler URL', value: appUrls?.install ?? '/bitrix/install' },
        { key: 'app', label: 'Application Handler URL (PLACEMENT)', value: appUrls?.app ?? '/bitrix/app' },
        { key: 'events', label: 'Event Webhook URL', value: appUrls?.events ?? '/bitrix/events' },
        { key: 'eula', label: 'Marketplace EULA / Terms URL', value: appUrls?.eula ?? '/eula' },
        { key: 'privacy', label: 'Marketplace Privacy Policy URL', value: appUrls?.privacy ?? '/privacy' },
    ];

    const copy = async (key: string, value: string) => {
        try {
            await navigator.clipboard.writeText(value);
            setCopiedKey(key);
            setTimeout(() => setCopiedKey((prev) => (prev === key ? null : prev)), 1800);
        } catch {
            // Clipboard access may be unavailable in restricted contexts
        }
    };

    return (
        <Card className="border-slate-200/90 shadow-sm dark:border-slate-800">
            <CardHeader className="pb-3">
                <div className="flex items-center gap-2">
                    <div className="flex h-7 w-7 items-center justify-center rounded-md bg-indigo-50 text-indigo-600 dark:bg-indigo-950/60 dark:text-indigo-400">
                        <Link2 className="h-4 w-4" />
                    </div>
                    <CardTitle className="text-base">Bitrix24 Developer Area URLs</CardTitle>
                </div>
                <CardDescription>
                    Configure these endpoints when publishing your Mass-Market Marketplace or On-Premise integration in Bitrix24.
                </CardDescription>
            </CardHeader>
            <CardContent className="space-y-3 text-xs">
                {rows.map((row) => {
                    const isCopied = copiedKey === row.key;
                    return (
                        <div key={row.key} className="space-y-1">
                            <div className="text-muted-foreground font-medium">{row.label}</div>
                            <div className="flex items-center justify-between gap-2 rounded-md border bg-slate-50 px-2.5 py-1.5 dark:bg-slate-900">
                                <code className="overflow-x-auto font-mono text-[11px] text-slate-800 dark:text-slate-200">{row.value}</code>
                                <button
                                    type="button"
                                    onClick={() => copy(row.key, row.value)}
                                    className="inline-flex shrink-0 items-center gap-1 rounded px-1.5 py-0.5 text-[11px] font-medium text-indigo-600 hover:bg-indigo-50 dark:text-indigo-400 dark:hover:bg-indigo-950/60"
                                >
                                    {isCopied ? <Check className="h-3 w-3 text-emerald-600" /> : <Copy className="h-3 w-3" />}
                                    {isCopied ? 'Copied' : 'Copy'}
                                </button>
                            </div>
                        </div>
                    );
                })}
            </CardContent>
        </Card>
    );
}
