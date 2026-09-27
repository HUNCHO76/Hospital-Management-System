import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type Paginated } from '@/types';
import { Head } from '@inertiajs/react';
import { Pagination } from '@/components/pagination';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'NHIF', href: '/nhif/dashboard' },
    { title: 'Authorization Context', href: '/nhif/authorization-context' },
];

interface AuthContext {
    id: number;
    status: string;
    source_type: string | null;
    source_id: number | null;
    approved_amount: number | null;
    resolved_at: string | null;
    patient: { id: number; name: string } | null;
    hospital: { name: string } | null;
}

interface Props {
    contexts: Paginated<AuthContext>;
    filters: { patient_id?: string };
}

export default function AuthorizationContextIndex({ contexts }: Props) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="NHIF Authorization Context" />

            <div className="flex h-full flex-col gap-4 p-4">
                <div>
                    <h1 className="text-xl font-semibold">NHIF Authorization Context</h1>
                    <p className="text-sm text-muted-foreground">
                        Active NHIF pre-authorizations available for clinical modules to reference.
                    </p>
                </div>

                <div className="rounded-md border">
                    <table className="w-full text-sm">
                        <thead className="border-b bg-muted/40">
                            <tr>
                                <th className="px-4 py-3 text-left font-medium">Patient</th>
                                <th className="px-4 py-3 text-left font-medium">Source</th>
                                <th className="px-4 py-3 text-right font-medium">Approved (TZS)</th>
                                <th className="px-4 py-3 text-left font-medium">Hospital</th>
                                <th className="px-4 py-3 text-left font-medium">Resolved At</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {contexts.data.length === 0 ? (
                                <tr>
                                    <td colSpan={5} className="px-4 py-8 text-center text-muted-foreground">
                                        No approved pre-authorizations found.
                                    </td>
                                </tr>
                            ) : (
                                contexts.data.map((ctx) => (
                                    <tr key={ctx.id} className="hover:bg-muted/30">
                                        <td className="px-4 py-3">{ctx.patient?.name ?? '—'}</td>
                                        <td className="px-4 py-3 text-xs">{ctx.source_type ? `${ctx.source_type} #${ctx.source_id}` : '—'}</td>
                                        <td className="px-4 py-3 text-right">{ctx.approved_amount != null ? Number(ctx.approved_amount).toLocaleString() : '—'}</td>
                                        <td className="px-4 py-3">{ctx.hospital?.name ?? '—'}</td>
                                        <td className="px-4 py-3 text-xs">{ctx.resolved_at ? new Date(ctx.resolved_at).toLocaleString() : '—'}</td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                </div>

                <Pagination links={contexts.links} />
            </div>
        </AppLayout>
    );
}
