import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';
import { ShieldAlert, Sparkles } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Dashboard',
        href: '/dashboard',
    },
];

export default function Dashboard() {
    const { auth } = usePage<SharedData>().props;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Dashboard" />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <Card className="max-w-2xl">
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2">
                            <Sparkles className="h-5 w-5 text-indigo-600" />
                            Welcome, {auth.user.name}
                        </CardTitle>
                        <CardDescription>Bitrix24 Multi-Tenant Fee Breakdown &amp; SPA Sync Platform</CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <Alert>
                            <ShieldAlert className="h-4 w-4" />
                            <AlertTitle>Standard User Account</AlertTitle>
                            <AlertDescription>
                                Your account does not currently have Super Admin permissions. SaaS portal oversight, subscription billing, and system
                                diagnostics are restricted to Super Administrators. If you manage a Bitrix24 CRM portal, open the application directly
                                inside your Bitrix24 portal menu.
                            </AlertDescription>
                        </Alert>

                        <div className="flex flex-wrap gap-3 text-sm">
                            <Link
                                href="/settings/profile"
                                className="hover:bg-muted inline-flex items-center rounded-md border px-3 py-1.5 font-medium"
                            >
                                Account Settings
                            </Link>
                            <Link href="/" className="hover:bg-muted inline-flex items-center rounded-md border px-3 py-1.5 font-medium">
                                Marketplace Setup Overview
                            </Link>
                        </div>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
