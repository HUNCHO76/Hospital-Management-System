import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { radiologyModalityLabel, radiologyOrderPriorityLabel } from '@/lib/radiology-status';
import { type BreadcrumbItem } from '@/types';
import { type RadiologyAppointment } from '@/types/radiology';
import { Head, Link, useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { type FormEventHandler } from 'react';

interface RadiologyReportCreateProps {
    appointment: RadiologyAppointment;
    existingReportId: number | null;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Radiology', href: '/radiology' },
    { title: 'Results', href: '/radiology/results' },
    { title: 'Start Report', href: '#' },
];

/**
 * The patient-safety-style confirmation before a report draft is created
 * (Phase 5, Step 42) — a read-only preview of exactly which patient/order/
 * procedure/examination the report will be attached to, mirroring
 * RadiologyExaminationStartDialog's own identity-confirmation panel. The
 * backend independently re-validates every relationship regardless of what
 * this page shows.
 */
export default function RadiologyReportCreate({ appointment, existingReportId }: RadiologyReportCreateProps) {
    const { post, processing } = useForm({ radiology_appointment_id: appointment.id });

    const patientName = appointment.patient
        ? [appointment.patient.first_name, appointment.patient.middle_name, appointment.patient.last_name].filter(Boolean).join(' ')
        : '—';

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('radiology-results.store'));
    };

    if (existingReportId) {
        return (
            <AppLayout breadcrumbs={breadcrumbs}>
                <Head title="Start Report" />
                <div className="flex flex-col gap-4 p-4">
                    <p className="text-sm">A report already exists for this examination.</p>
                    <Button asChild className="w-fit">
                        <Link href={route('radiology-results.show', existingReportId)}>Open Report</Link>
                    </Button>
                </div>
            </AppLayout>
        );
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Start Report" />

            <div className="flex flex-col gap-6 p-4">
                <div>
                    <h1 className="text-2xl font-semibold">Start Radiology Report</h1>
                    <p className="text-muted-foreground text-sm">Confirm the patient and examination before beginning.</p>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Examination Summary</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <dl className="grid grid-cols-2 gap-2 text-sm">
                            <dt className="text-muted-foreground">Patient</dt>
                            <dd className="text-right font-medium">{patientName}</dd>
                            <dt className="text-muted-foreground">Patient Number</dt>
                            <dd className="text-right">{appointment.patient?.patient_number ?? '—'}</dd>
                            <dt className="text-muted-foreground">Date of Birth</dt>
                            <dd className="text-right">{appointment.patient?.date_of_birth ?? '—'}</dd>
                            <dt className="text-muted-foreground">Sex</dt>
                            <dd className="text-right">{appointment.patient?.gender ?? '—'}</dd>
                            <dt className="text-muted-foreground">Order Number</dt>
                            <dd className="text-right">{appointment.order?.order_number ?? '—'}</dd>
                            <dt className="text-muted-foreground">Priority</dt>
                            <dd className="text-right">{appointment.order ? radiologyOrderPriorityLabel[appointment.order.priority] : '—'}</dd>
                            <dt className="text-muted-foreground">Procedure</dt>
                            <dd className="text-right">{appointment.item?.procedure_name ?? '—'}</dd>
                            <dt className="text-muted-foreground">Modality</dt>
                            <dd className="text-right">{appointment.modality ? radiologyModalityLabel[appointment.modality] : '—'}</dd>
                            <dt className="text-muted-foreground">Performed By</dt>
                            <dd className="text-right">{appointment.performed_by?.name ?? '—'}</dd>
                        </dl>
                    </CardContent>
                </Card>

                <form onSubmit={submit}>
                    <Button type="submit" disabled={processing}>
                        {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                        Start Report
                    </Button>
                </form>
            </div>
        </AppLayout>
    );
}
