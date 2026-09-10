import { EmptyState } from '@/components/empty-state';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';
import { History as HistoryIcon } from 'lucide-react';

interface TimelineEntry {
    id: number;
    action: string;
    module: string;
    user: string | null;
    created_at: string;
}

interface PatientHistoryProps {
    patient: {
        id: number;
        patient_number: string;
        first_name: string;
        middle_name: string | null;
        last_name: string;
    };
    timeline: TimelineEntry[];
}

function formatDateTime(value: string): string {
    return new Date(value).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });
}

function formatAction(action: string): string {
    return action
        .split('_')
        .map((word) => word.charAt(0).toUpperCase() + word.slice(1))
        .join(' ');
}

/**
 * A read-only aggregation of this patient's registration, insurance,
 * appointment, and reception activity — built entirely from the existing
 * audit log (see PatientHistoryService), not a separate clinical record.
 * No diagnosis, treatment, or other clinical detail ever appears here.
 */
export default function PatientHistory({ patient, timeline }: PatientHistoryProps) {
    const fullName = [patient.first_name, patient.middle_name, patient.last_name].filter(Boolean).join(' ');

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Patients', href: '/patients' },
        { title: fullName, href: `/patients/${patient.id}` },
        { title: 'History', href: `/patients/${patient.id}/history` },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`History — ${fullName}`} />

            <div className="flex flex-col gap-6 p-4">
                <div>
                    <h1 className="text-2xl font-semibold">Patient History</h1>
                    <p className="text-muted-foreground text-sm">
                        {fullName} · {patient.patient_number}
                    </p>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Activity Timeline</CardTitle>
                    </CardHeader>
                    <CardContent>
                        {timeline.length > 0 ? (
                            <ul className="divide-sidebar-border/70 dark:divide-sidebar-border divide-y">
                                {timeline.map((entry) => (
                                    <li key={entry.id} className="flex items-center justify-between gap-4 py-2.5 text-sm">
                                        <div className="flex items-center gap-3">
                                            <span className="bg-primary h-2 w-2 shrink-0 rounded-full" aria-hidden="true" />
                                            <div>
                                                <p className="font-medium">{formatAction(entry.action)}</p>
                                                <p className="text-muted-foreground text-xs">
                                                    {entry.module}
                                                    {entry.user ? ` · ${entry.user}` : ''}
                                                </p>
                                            </div>
                                        </div>
                                        <span className="text-muted-foreground shrink-0 text-xs whitespace-nowrap">
                                            {formatDateTime(entry.created_at)}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        ) : (
                            <EmptyState icon={HistoryIcon} title="No activity yet" description="Activity will appear here as this patient's record changes." />
                        )}
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
