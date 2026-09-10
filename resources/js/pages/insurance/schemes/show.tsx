import { Can } from '@/components/authorization/can';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { type InsuranceScheme } from '@/types/insurance-scheme';
import { Head, Link } from '@inertiajs/react';
import { Pencil } from 'lucide-react';

interface SchemesShowProps {
    scheme: InsuranceScheme;
}

export default function SchemesShow({ scheme }: SchemesShowProps) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Insurance Schemes', href: '/insurance/schemes' },
        { title: scheme.name, href: `/insurance/schemes/${scheme.id}` },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={scheme.name} />

            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-semibold">{scheme.name}</h1>
                        <p className="text-muted-foreground text-sm">{scheme.code}</p>
                    </div>

                    <Can permission="insurance-schemes.update">
                        <Button asChild variant="outline">
                            <Link href={route('insurance.schemes.edit', scheme.id)}>
                                <Pencil className="h-4 w-4" />
                                Edit
                            </Link>
                        </Button>
                    </Can>
                </div>

                <Card className="max-w-2xl">
                    <CardHeader>
                        <CardTitle className="text-base">Scheme Details</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <dl className="space-y-2 text-sm">
                            <div className="flex justify-between gap-4">
                                <dt className="text-muted-foreground">Status</dt>
                                <dd>
                                    <Badge variant={scheme.status === 'active' ? 'default' : 'secondary'}>{scheme.status}</Badge>
                                </dd>
                            </div>
                            <div className="flex justify-between gap-4">
                                <dt className="text-muted-foreground">Provider</dt>
                                <dd>
                                    {scheme.insurance_provider ? (
                                        <Link href={route('insurance.providers.show', scheme.insurance_provider.id)} className="hover:underline">
                                            {scheme.insurance_provider.name}
                                        </Link>
                                    ) : (
                                        '—'
                                    )}
                                </dd>
                            </div>
                            <div className="flex justify-between gap-4">
                                <dt className="text-muted-foreground">Description</dt>
                                <dd className="text-right">{scheme.description ?? '—'}</dd>
                            </div>
                        </dl>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
