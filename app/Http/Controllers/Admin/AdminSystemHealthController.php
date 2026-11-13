<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use App\Models\SyncLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;

class AdminSystemHealthController extends Controller
{
    public function index(): Response
    {
        $pendingJobs = Schema::hasTable('jobs') ? DB::table('jobs')->count() : 0;
        $failedJobs = Schema::hasTable('failed_jobs')
            ? DB::table('failed_jobs')->orderByDesc('id')->limit(20)->get(['id', 'uuid', 'queue', 'exception', 'failed_at'])
            : collect();

        $errorLogs = SyncLog::with('tenant:id,domain')
            ->whereIn('status', ['failed', 'blocked'])
            ->latest('id')
            ->limit(30)
            ->get();

        $auditLogs = AdminAuditLog::with(['user:id,name,email', 'tenant:id,domain'])
            ->latest('id')
            ->limit(30)
            ->get();

        return Inertia::render('admin/system-health', [
            'queue' => [
                'connection' => config('queue.default'),
                'pending_jobs' => $pendingJobs,
                'failed_jobs' => $failedJobs,
            ],
            'error_logs' => $errorLogs,
            'audit_logs' => $auditLogs,
        ]);
    }

    public function retryFailedJob(Request $request, string $uuid): RedirectResponse
    {
        Artisan::call('queue:retry', ['id' => [$uuid]]);

        AdminAuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'failed_job_retried',
            'details_json' => ['uuid' => $uuid],
            'ip_address' => $request->ip(),
            'created_at' => now(),
        ]);

        return back()->with('success', "Queued retry for job {$uuid}.");
    }

    public function pruneOldLogs(Request $request): RedirectResponse
    {
        $deleted = SyncLog::where('created_at', '<', now()->subDays(90))->delete();

        AdminAuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'sync_logs_pruned',
            'details_json' => ['deleted_count' => $deleted],
            'ip_address' => $request->ip(),
            'created_at' => now(),
        ]);

        return back()->with('success', "Pruned {$deleted} sync log entries older than 90 days.");
    }
}
