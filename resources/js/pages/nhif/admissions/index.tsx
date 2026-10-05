import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';
import { Alert, AlertDescription } from '@/components/ui/alert';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'NHIF', href: '/nhif/dashboard' },
    { title: 'Admitted Patients', href: '/nhif/admissions' },
];

interface NhifAdmissionDetail {
    AdmissionNo?: string;
    CardNo?: string;
    FirstName?: string;
    LastName?: string;
    DateAdmitted?: string;
    SchemeID?: number | string;
    WardTypeID?: number | string;
    RoomTypeID?: number | string;
    [key: string]: any;
}

interface Props {
    admittedPatients: NhifAdmissionDetail[];
    error: string | null;
}

export default function NhifAdmissionsIndex({ admittedPatients, error }: Props) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="NHIF Admitted Patients" />

            <div className="flex h-full flex-col gap-4 p-4">
                <div>
                    <h1 className="text-xl font-semibold">NHIF Admitted Patients</h1>
                    <p className="text-sm text-muted-foreground">
                        Live view of patients currently admitted under your facility on the NHIF portal.
                    </p>
                </div>

                {error && (
                    <Alert variant="destructive">
                        <AlertDescription>{error}</AlertDescription>
                    </Alert>
                )}

                <div className="rounded-md border">
                    <table className="w-full text-sm">
                        <thead className="border-b bg-muted/40">
                            <tr>
                                <th className="px-4 py-3 text-left font-medium">Admission No</th>
                                <th className="px-4 py-3 text-left font-medium">Card No</th>
                                <th className="px-4 py-3 text-left font-medium">Name</th>
                                <th className="px-4 py-3 text-left font-medium">Date Admitted</th>
                                <th className="px-4 py-3 text-left font-medium">Scheme</th>
                                <th className="px-4 py-3 text-left font-medium">Ward/Room Type</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {!admittedPatients || admittedPatients.length === 0 ? (
                                <tr>
                                    <td colSpan={6} className="px-4 py-8 text-center text-muted-foreground">
                                        No patients currently admitted according to NHIF.
                                    </td>
                                </tr>
                            ) : (
                                admittedPatients.map((patient, idx) => (
                                    <tr key={idx} className="hover:bg-muted/30">
                                        <td className="px-4 py-3 font-medium">{patient.AdmissionNo ?? '—'}</td>
                                        <td className="px-4 py-3">{patient.CardNo ?? '—'}</td>
                                        <td className="px-4 py-3">
                                            {[patient.FirstName, patient.LastName].filter(Boolean).join(' ') || '—'}
                                        </td>
                                        <td className="px-4 py-3">
                                            {patient.DateAdmitted ? new Date(patient.DateAdmitted).toLocaleDateString() : '—'}
                                        </td>
                                        <td className="px-4 py-3">{patient.SchemeID ?? '—'}</td>
                                        <td className="px-4 py-3 text-xs">
                                            Ward: {patient.WardTypeID ?? '—'} / Room: {patient.RoomTypeID ?? '—'}
                                        </td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                </div>
            </div>
        </AppLayout>
    );
}
