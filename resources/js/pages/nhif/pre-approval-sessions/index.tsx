import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'NHIF', href: '/nhif/dashboard' },
    { title: 'Pre-Approval Sessions', href: '#' },
];

const statusColors: Record<string, string> = {
    draft: 'bg-gray-100 text-gray-700',
    submitted: 'bg-blue-100 text-blue-700',
    partially_approved: 'bg-yellow-100 text-yellow-700',
    approved: 'bg-green-100 text-green-700',
    rejected: 'bg-red-100 text-red-700',
    issued: 'bg-purple-100 text-purple-700',
    cancelled: 'bg-gray-100 text-gray-500',
};

interface Session {
    id: number;
    nhif_authorization_no: string;
    status: string;
    submitted_at: string | null;
    issued_at: string | null;
    items: { id: number }[];
}

interface Patient {
    id: number;
    first_name: string;
    last_name: string;
}

interface Insurance {
    id: number;
    membership_number: string | null;
}

interface Props {
    patient: Patient;
    insurance: Insurance;
    sessions: Session[];
}

export default function Index({ patient, insurance, sessions }: Props) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="NHIF Pre-Approval Sessions" />

            <div className="p-6 space-y-6">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-2xl font-semibold">NHIF Pre-Approval Sessions</h1>
                        <p className="text-muted-foreground mt-1">
                            {patient.first_name} {patient.last_name}
                        </p>
                    </div>
                    <Button asChild>
                        <Link href={route('patients.insurance.nhif.pre-approval-sessions.create', [patient.id, insurance.id])}>
                            New Session
                        </Link>
                    </Button>
                </div>

                {sessions.length === 0 ? (
                    <p className="text-muted-foreground">No pre-approval sessions yet.</p>
                ) : (
                    <div className="border rounded-lg overflow-hidden">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 border-b">
                                <tr>
                                    <th className="text-left px-4 py-3 font-medium">#</th>
                                    <th className="text-left px-4 py-3 font-medium">Auth No</th>
                                    <th className="text-left px-4 py-3 font-medium">Items</th>
                                    <th className="text-left px-4 py-3 font-medium">Status</th>
                                    <th className="text-left px-4 py-3 font-medium">Submitted</th>
                                    <th className="text-left px-4 py-3 font-medium">Issued</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {sessions.map(session => (
                                    <tr key={session.id}>
                                        <td className="px-4 py-3">{session.id}</td>
                                        <td className="px-4 py-3 font-mono text-xs">{session.nhif_authorization_no}</td>
                                        <td className="px-4 py-3">{session.items.length}</td>
                                        <td className="px-4 py-3">
                                            <span className={`px-2 py-0.5 rounded-full text-xs font-medium ${statusColors[session.status] ?? 'bg-gray-100'}`}>
                                                {session.status.replace(/_/g, ' ')}
                                            </span>
                                        </td>
                                        <td className="px-4 py-3 text-muted-foreground">{session.submitted_at ?? '—'}</td>
                                        <td className="px-4 py-3 text-muted-foreground">{session.issued_at ?? '—'}</td>
                                        <td className="px-4 py-3">
                                            <Link
                                                href={route('patients.insurance.nhif.pre-approval-sessions.show', [patient.id, insurance.id, session.id])}
                                                className="text-primary hover:underline text-xs"
                                            >
                                                View
                                            </Link>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
