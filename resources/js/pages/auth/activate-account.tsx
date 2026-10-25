import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthLayout from '@/layouts/auth-layout';
import { Head, Link, useForm } from '@inertiajs/react';
import { AlertCircle, CheckCircle2, KeyRound, LoaderCircle, ShieldCheck } from 'lucide-react';
import { FormEventHandler } from 'react';

interface Props {
    valid: boolean;
    token: string;
    user: {
        name: string;
        email: string | null;
        department?: string;
        job_title?: string;
    } | null;
}

export default function ActivateAccount({ valid, token, user }: Props) {
    const { data, setData, post, processing, errors, reset } = useForm({
        password: '',
        password_confirmation: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('activation.store', token), {
            onFinish: () => reset('password', 'password_confirmation'),
        });
    };

    if (!valid || !user) {
        return (
            <AuthLayout
                title="Account Activation"
                description="Link Expired or Invalid"
            >
                <Head title="Invalid Activation Link" />

                <div className="space-y-4 text-center">
                    <div className="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-destructive/10 text-destructive">
                        <AlertCircle className="h-6 w-6" />
                    </div>
                    <h2 className="text-lg font-semibold">Activation Link Expired or Invalid</h2>
                    <p className="text-sm text-muted-foreground">
                        This activation link has already been used or has expired. Please contact your Hospital
                        Administrator to request a new secure activation link.
                    </p>
                    <div className="pt-2">
                        <Button asChild variant="outline" className="w-full">
                            <Link href={route('login')}>Return to Login</Link>
                        </Button>
                    </div>
                </div>
            </AuthLayout>
        );
    }

    return (
        <AuthLayout
            title="Activate Staff Account"
            description="Welcome to St. Joseph DMI Hospital Management System"
        >
            <Head title="Activate Staff Account" />

            <div className="space-y-6">
                {/* Staff banner */}
                <div className="rounded-lg border bg-muted/40 p-4 space-y-1 text-sm">
                    <div className="flex items-center gap-2 font-semibold text-base">
                        <ShieldCheck className="h-5 w-5 text-primary" />
                        <span>{user.name}</span>
                    </div>
                    <p className="text-muted-foreground">
                        {user.job_title ?? 'Staff Member'} {user.department ? `· ${user.department}` : ''}
                    </p>
                    {user.email && (
                        <p className="text-xs font-mono text-muted-foreground">{user.email}</p>
                    )}
                </div>

                <form onSubmit={submit} className="space-y-4">
                    <div className="grid gap-2">
                        <Label htmlFor="password">Create Password</Label>
                        <Input
                            id="password"
                            type="password"
                            name="password"
                            autoComplete="new-password"
                            value={data.password}
                            className="mt-1 block w-full"
                            autoFocus
                            placeholder="Minimum 8 characters"
                            onChange={(e) => setData('password', e.target.value)}
                        />
                        <InputError message={errors.password} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="password_confirmation">Confirm Password</Label>
                        <Input
                            id="password_confirmation"
                            type="password"
                            name="password_confirmation"
                            autoComplete="new-password"
                            value={data.password_confirmation}
                            className="mt-1 block w-full"
                            placeholder="Re-type password"
                            onChange={(e) => setData('password_confirmation', e.target.value)}
                        />
                        <InputError message={errors.password_confirmation} />
                    </div>

                    <Button type="submit" className="w-full mt-2" disabled={processing}>
                        {processing ? (
                            <>
                                <LoaderCircle className="mr-2 h-4 w-4 animate-spin" />
                                Activating Account...
                            </>
                        ) : (
                            <>
                                <KeyRound className="mr-2 h-4 w-4" />
                                Set Password & Activate Account
                            </>
                        )}
                    </Button>
                </form>
            </div>
        </AuthLayout>
    );
}
