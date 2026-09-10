import { Can } from '@/components/authorization/can';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { type InsuranceProvider } from '@/types/insurance-provider';
import { type InsuranceScheme } from '@/types/insurance-scheme';
import { Head, Link } from '@inertiajs/react';
import { Pencil, Plus } from 'lucide-react';

interface ProvidersShowProps {
    provider: InsuranceProvider & { schemes: InsuranceScheme[] };
}

export default function ProvidersShow({ provider }: ProvidersShowProps) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Insurance Providers', href: '/insurance/providers' },
        { title: provider.name, href: `/insurance/providers/${provider.id}` },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={provider.name} />

            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-semibold">{provider.name}</h1>
                        <p className="text-muted-foreground text-sm">{provider.code}</p>
                    </div>

                    <Can permission="insurance-providers.update">
                        <Button asChild variant="outline">
                            <Link href={route('insurance.providers.edit', provider.id)}>
                                <Pencil className="h-4 w-4" />
                                Edit
                            </Link>
                        </Button>
                    </Can>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Provider Details</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <dl className="space-y-2 text-sm">
                            <div className="flex justify-between gap-4">
                                <dt className="text-muted-foreground">Type</dt>
                                <dd className="capitalize">{provider.type}</dd>
                            </div>
                            <div className="flex justify-between gap-4">
                                <dt className="text-muted-foreground">Registration Number</dt>
                                <dd>{provider.registration_number ?? '—'}</dd>
                            </div>
                            <div className="flex justify-between gap-4">
                                <dt className="text-muted-foreground">Phone</dt>
                                <dd>{provider.phone ?? '—'}</dd>
                            </div>
                            <div className="flex justify-between gap-4">
                                <dt className="text-muted-foreground">Email</dt>
                                <dd>{provider.email ?? '—'}</dd>
                            </div>
                            <div className="flex justify-between gap-4">
                                <dt className="text-muted-foreground">Address</dt>
                                <dd className="text-right">{provider.address ?? '—'}</dd>
                            </div>
                            <div className="flex justify-between gap-4">
                                <dt className="text-muted-foreground">Status</dt>
                                <dd>
                                    <Badge variant={provider.status === 'active' ? 'default' : 'secondary'}>{provider.status}</Badge>
                                </dd>
                            </div>
                        </dl>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader className="flex flex-row items-center justify-between space-y-0">
                        <CardTitle className="text-base">Schemes</CardTitle>
                        <Can permission="insurance-schemes.create">
                            <Button asChild size="sm" variant="outline">
                                <Link href={route('insurance.schemes.create')}>
                                    <Plus className="h-4 w-4" />
                                    Add Scheme
                                </Link>
                            </Button>
                        </Can>
                    </CardHeader>
                    <CardContent>
                        {provider.schemes.length > 0 ? (
                            <ul className="divide-sidebar-border/70 dark:divide-sidebar-border divide-y">
                                {provider.schemes.map((scheme) => (
                                    <li key={scheme.id} className="flex items-center justify-between gap-4 py-2">
                                        <div>
                                            <Link href={route('insurance.schemes.show', scheme.id)} className="font-medium hover:underline">
                                                {scheme.name}
                                            </Link>
                                            <span className="text-muted-foreground ml-2 text-xs">{scheme.code}</span>
                                        </div>
                                        <Badge variant={scheme.status === 'active' ? 'default' : 'secondary'}>{scheme.status}</Badge>
                                    </li>
                                ))}
                            </ul>
                        ) : (
                            <p className="text-muted-foreground text-sm">No schemes found for this provider.</p>
                        )}
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
