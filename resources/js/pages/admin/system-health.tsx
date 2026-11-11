import { FlashBanner } from '@/components/flash-banner';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';

interface SystemHealthProps {
    queue: {
        connection: string;
        pending_jobs: number;
        failed_jobs: Array<{
            id: number;
            uuid: string;
            queue: string;
            exception: string;
            failed_at: string;
        }>;
    };
    error_logs: Array<{
        id: number;
        status: string;
        trigger_source: string;
        entity_type_id: number | null;
        entity_id: number | null;
        errors_json: string[] | null;
        created_at: string;
        tenant?: { id: number; domain: string };
    }>;
    audit_logs: Array<{
        id: number;
        action: string;
        created_at: string;
        details_json: Record<string, unknown> | null;
        user?: { id: number; name: string; email: string } | null;
        tenant?: { id: number; domain: string } | null;
    }>;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Overview', href: '/admin/dashboard' },
    { title: 'System Health', href: '/admin/system-health' },
];

export default function AdminSystemHealth({ queue, error_logs, audit_logs }: SystemHealthProps) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="System Health" />
            <FlashBanner />

            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">Queue, Sync Errors &amp; Audit Trail</h1>
                        <p className="text-muted-foreground text-sm">
                            Queue connection: <code>{queue.connection}</code> · Pending jobs: <strong>{queue.pending_jobs}</strong> · Failed jobs:{' '}
                            <strong>{queue.failed_jobs.length}</strong>
                        </p>
                    </div>
                    <Button variant="outline" onClick={() => router.post('/admin/system-health/prune-logs')}>
                        Prune Sync Logs &gt; 90 Days
                    </Button>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Failed Background Jobs</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-2 text-xs">
                        {queue.failed_jobs.length === 0 ? (
                            <p className="text-muted-foreground py-4 text-center">No failed queue jobs.</p>
                        ) : (
                            queue.failed_jobs.map((job) => (
                                <div key={job.uuid} className="flex items-start justify-between gap-3 rounded border p-3">
                                    <div className="min-w-0 flex-1">
                                        <div className="font-mono font-medium">
                                            {job.uuid} ({job.queue}) · {job.failed_at}
                                        </div>
                                        <pre className="text-muted-foreground mt-1 line-clamp-2 whitespace-pre-wrap">{job.exception}</pre>
                                    </div>
                                    <Button
                                        size="sm"
                                        variant="secondary"
                                        onClick={() => router.post(`/admin/system-health/failed-jobs/${job.uuid}/retry`)}
                                    >
                                        Retry
                                    </Button>
                                </div>
                            ))
                        )}
                    </CardContent>
                </Card>

                <div className="grid gap-6 lg:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Recent Blocked / Failed Portal Syncs</CardTitle>
                        </CardHeader>
                        <CardContent className="max-h-96 space-y-2 overflow-y-auto text-xs">
                            {error_logs.length === 0 ? (
                                <p className="text-muted-foreground py-4 text-center">Zero failed/blocked syncs.</p>
                            ) : (
                                error_logs.map((log) => (
                                    <div key={log.id} className="rounded border p-2.5">
                                        <div className="flex justify-between font-medium">
                                            <span>
                                                {log.tenant?.domain} · {log.trigger_source}
                                            </span>
                                            <Badge variant="destructive">{log.status}</Badge>
                                        </div>
                                        <div className="mt-1 text-rose-600">{(log.errors_json ?? []).join('; ') || 'Unknown error'}</div>
                                    </div>
                                ))
                            )}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Super Admin Audit Log</CardTitle>
                        </CardHeader>
                        <CardContent className="max-h-96 space-y-2 overflow-y-auto text-xs">
                            {audit_logs.length === 0 ? (
                                <p className="text-muted-foreground py-4 text-center">No admin actions recorded yet.</p>
                            ) : (
                                audit_logs.map((a) => (
                                    <div key={a.id} className="rounded border p-2.5">
                                        <div className="flex justify-between font-medium">
                                            <span>{a.action}</span>
                                            <span className="text-muted-foreground">{a.user?.name ?? 'System'}</span>
                                        </div>
                                        <div className="text-muted-foreground mt-0.5">
                                            {a.tenant?.domain ? `${a.tenant.domain} · ` : ''}
                                            {JSON.stringify(a.details_json)}
                                        </div>
                                    </div>
                                ))
                            )}
                        </CardContent>
                    </Card>
                </div>
            </div>
        </AppLayout>
    );
}
