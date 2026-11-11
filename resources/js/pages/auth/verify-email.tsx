import { Head, useForm } from '@inertiajs/react';
import { CheckCircle2, LoaderCircle, MailCheck } from 'lucide-react';
import { FormEventHandler } from 'react';

import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import AuthLayout from '@/layouts/auth-layout';

export default function VerifyEmail({ status }: { status?: string }) {
    const { post, processing } = useForm({});

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('verification.send'));
    };

    return (
        <AuthLayout title="Verify your email address" description="Click the verification link we sent to your inbox to activate your account">
            <Head title="Email verification — FeeMatrix24" />

            <div className="mb-5 flex items-start gap-3 rounded-lg border border-indigo-200/80 bg-indigo-50/70 p-3.5 text-xs text-indigo-900 dark:border-indigo-900/60 dark:bg-indigo-950/35 dark:text-indigo-200">
                <MailCheck className="mt-0.5 h-4 w-4 shrink-0 text-indigo-600 dark:text-indigo-400" />
                <span>Check your inbox and spam folder for a message from FeeMatrix24. Need another link? Request a fresh email below.</span>
            </div>

            {status === 'verification-link-sent' && (
                <div className="mb-5 flex items-center gap-2 rounded-lg border border-emerald-200 bg-emerald-50 px-3.5 py-2.5 text-xs font-medium text-emerald-700 dark:border-emerald-900/60 dark:bg-emerald-950/40 dark:text-emerald-300">
                    <CheckCircle2 className="h-4 w-4 shrink-0" />
                    <span>A fresh verification link has been sent to your registered email address.</span>
                </div>
            )}

            <form onSubmit={submit} className="space-y-5 text-center">
                <Button
                    type="submit"
                    disabled={processing}
                    className="w-full bg-indigo-600 text-white hover:bg-indigo-700 dark:bg-indigo-500 dark:hover:bg-indigo-600"
                >
                    {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                    Resend verification email
                </Button>

                <div className="border-t border-slate-200/80 pt-4 dark:border-slate-800">
                    <TextLink href={route('logout')} method="post" as="button" className="mx-auto block cursor-pointer text-sm">
                        Log out
                    </TextLink>
                </div>
            </form>
        </AuthLayout>
    );
}
