import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { NhifLiveAdmissionDetailsDialog } from '@/components/admissions/nhif-live-admission-details-dialog';
import { BedDouble, ExternalLink, ShieldCheck } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'NHIF', href: '/nhif/dashboard' },
    { title: 'Admitted Patients', href: '/nhif/admissions' },
];

interface NhifAdmissionDetail {
    AdmissionNo?: string;
    AdmissionID?: string;
    AuthorizationNo?: string;
    CardNo?: string;
    FullName?: string;
    FirstName?: string;
    LastName?: string;
    DateAdmitted?: string;
    Scheme?: string;
    SchemeID?: number | string;
    WardDisplay?: string;
    RoomDisplay?: string;
    WardTypeID?: number | string;
    RoomTypeID?: number | string;
    AdmissionType?: string;
    LocalAdmissionID?: number;
    LocalAdmissionNumber?: string;
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
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-xl font-semibold flex items-center gap-2">
                            <BedDouble className="h-5 w-5 text-primary" />
                            NHIF Admitted Patients
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            Live view of patients admitted under your facility on NHIF portal with ward, room, and admission details.
                        </p>
                    </div>
                    {admittedPatients && admittedPatients.length > 0 && (
                        <Badge variant="outline" className="gap-1 font-normal">
                            <ShieldCheck className="h-3.5 w-3.5 text-green-600" />
                            {admittedPatients.length} Active {admittedPatients.length === 1 ? 'Admission' : 'Admissions'}
                        </Badge>
                    )}
                </div>

                {error && (
                    <Alert variant="destructive">
                        <AlertDescription>{error}</AlertDescription>
                    </Alert>
                )}

                <div className="rounded-md border bg-card">
                    <table className="w-full text-sm">
                        <thead className="border-b bg-muted/50">
                            <tr>
                                <th className="px-4 py-3 text-left font-medium">Admission No</th>
                                <th className="px-4 py-3 text-left font-medium">Auth No</th>
                                <th className="px-4 py-3 text-left font-medium">Card No</th>
                                <th className="px-4 py-3 text-left font-medium">Patient Name</th>
                                <th className="px-4 py-3 text-left font-medium">Scheme</th>
                                <th className="px-4 py-3 text-left font-medium">Ward / Room</th>
                                <th className="px-4 py-3 text-left font-medium">Date Admitted</th>
                                <th className="px-4 py-3 text-left font-medium">Admission Type</th>
                                <th className="px-4 py-3 text-right font-medium">Actions</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {!admittedPatients || admittedPatients.length === 0 ? (
                                <tr>
                                    <td colSpan={9} className="px-4 py-8 text-center text-muted-foreground">
                                        No patients currently admitted according to NHIF.
                                    </td>
                                </tr>
                            ) : (
                                admittedPatients.map((patient, idx) => {
                                    const patientName =
                                        patient.FullName ||
                                        [patient.FirstName, patient.LastName].filter(Boolean).join(' ') ||
                                        '—';

                                    const admissionNumber = patient.AdmissionNo || patient.LocalAdmissionNumber || '—';

                                    const wardAndRoom =
                                        patient.WardDisplay || patient.RoomDisplay
                                            ? `${patient.WardDisplay ?? '—'} / ${patient.RoomDisplay ?? '—'}`
                                            : patient.WardTypeID
                                              ? `Ward: ${patient.WardTypeID} / Room: ${patient.RoomTypeID ?? '—'}`
                                              : '—';

                                    const schemeName =
                                        patient.Scheme ||
                                        (patient.SchemeID ? `Scheme ${patient.SchemeID}` : '—');

                                    return (
                                        <tr key={idx} className="hover:bg-muted/30 transition-colors">
                                            <td className="px-4 py-3 font-semibold text-foreground">
                                                {admissionNumber}
                                                {patient.LocalAdmissionNumber && patient.LocalAdmissionNumber !== patient.AdmissionNo && (
                                                    <div className="text-xs text-muted-foreground font-normal">
                                                        Local: {patient.LocalAdmissionNumber}
                                                    </div>
                                                )}
                                            </td>
                                            <td className="px-4 py-3 font-mono text-xs text-muted-foreground">
                                                {patient.AuthorizationNo ?? '—'}
                                            </td>
                                            <td className="px-4 py-3 font-mono text-xs">
                                                {patient.CardNo ?? '—'}
                                            </td>
                                            <td className="px-4 py-3 font-medium">
                                                {patientName}
                                            </td>
                                            <td className="px-4 py-3">
                                                <Badge variant="secondary" className="font-normal text-xs">
                                                    {schemeName}
                                                </Badge>
                                            </td>
                                            <td className="px-4 py-3 text-xs">
                                                <span className="font-medium">{wardAndRoom}</span>
                                            </td>
                                            <td className="px-4 py-3 text-xs text-muted-foreground">
                                                {patient.DateAdmitted
                                                    ? new Date(patient.DateAdmitted).toLocaleDateString()
                                                    : '—'}
                                            </td>
                                            <td className="px-4 py-3 text-xs">
                                                {patient.AdmissionType ?? (patient.AdmissionTypeID ? `Type #${patient.AdmissionTypeID}` : '—')}
                                            </td>
                                            <td className="px-4 py-3 text-right">
                                                <div className="flex items-center justify-end gap-2">
                                                    {patient.AuthorizationNo && (
                                                        <NhifLiveAdmissionDetailsDialog
                                                            authorizationNo={patient.AuthorizationNo}
                                                            admissionId={patient.LocalAdmissionID}
                                                            trigger={
                                                                <Button variant="outline" size="sm" className="h-7 text-xs px-2 gap-1">
                                                                    <ExternalLink className="h-3 w-3" />
                                                                    NHIF Details
                                                                </Button>
                                                            }
                                                        />
                                                    )}
                                                    {patient.LocalAdmissionID && (
                                                        <Button asChild variant="ghost" size="sm" className="h-7 text-xs px-2">
                                                            <Link href={`/inpatient/${patient.LocalAdmissionID}/clinical-profile`}>
                                                                Clinical Profile
                                                            </Link>
                                                        </Button>
                                                    )}
                                                </div>
                                            </td>
                                        </tr>
                                    );
                                })
                            )}
                        </tbody>
                    </table>
                </div>
            </div>
        </AppLayout>
    );
}
