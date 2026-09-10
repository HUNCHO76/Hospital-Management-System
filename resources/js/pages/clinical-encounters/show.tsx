import { CarePlanSection } from '@/components/care-plan/care-plan-section';
import { CancelEncounterDialog } from '@/components/clinical-encounters/cancel-encounter-dialog';
import { CompleteEncounterDialog } from '@/components/clinical-encounters/complete-encounter-dialog';
import { LaboratoryOrdersSection } from '@/components/clinical-encounters/laboratory-orders-section';
import { WorkflowStatus } from '@/components/clinical-encounters/workflow-status';
import { ClinicalNotesSection } from '@/components/clinical-notes/clinical-notes-section';
import { ConsultationPanel } from '@/components/consultation/consultation-panel';
import { DiagnosisSection } from '@/components/diagnosis/diagnosis-section';
import { TriagePanel } from '@/components/triage/triage-panel';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { usePermissions } from '@/hooks/use-permissions';
import AppLayout from '@/layouts/app-layout';
import {
    allowedClinicalEncounterActions,
    clinicalEncounterDispositionLabel,
    clinicalEncounterStatusLabel,
    clinicalEncounterStatusVariant,
} from '@/lib/clinical-encounter-status';
import { type BreadcrumbItem } from '@/types';
import { type CarePlan } from '@/types/care-plan';
import { type ClinicalEncounter, type ClinicalEncounterDepartment, type ClinicalEncounterPractitioner } from '@/types/clinical-encounter';
import { type ClinicalNote } from '@/types/clinical-note';
import { type Consultation } from '@/types/consultation';
import { type EncounterDiagnosis } from '@/types/diagnosis';
import { type LaboratoryOrder } from '@/types/laboratory';
import { type TriageAssessment } from '@/types/triage';
import { Head, Link, router } from '@inertiajs/react';
import { PlayCircle, XCircle } from 'lucide-react';

interface EncounterShowProps {
    encounter: ClinicalEncounter;
    triage: TriageAssessment | null;
    consultation: Consultation | null;
    diagnoses: EncounterDiagnosis[];
    carePlan: CarePlan | null;
    laboratoryOrders: LaboratoryOrder[];
    clinicalNotes: ClinicalNote[];
    departments: ClinicalEncounterDepartment[];
    referralPractitioners: ClinicalEncounterPractitioner[];
}

function formatDateTime(value: string): string {
    return new Date(value).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });
}

const ENCOUNTER_TYPE_LABELS: Record<string, string> = {
    opd: 'OPD',
    walk_in: 'Walk-in',
    scheduled: 'Scheduled',
    follow_up: 'Follow-up',
};

export default function EncounterShow({
    encounter,
    triage,
    consultation,
    diagnoses,
    carePlan,
    laboratoryOrders,
    clinicalNotes,
    departments,
    referralPractitioners,
}: EncounterShowProps) {
    const { can } = usePermissions();
    const patientName = encounter.patient
        ? [encounter.patient.first_name, encounter.patient.middle_name, encounter.patient.last_name].filter(Boolean).join(' ')
        : '—';

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Clinical Encounters', href: '/clinical-encounters' },
        { title: encounter.encounter_number, href: `/clinical-encounters/${encounter.id}` },
    ];

    const actions = allowedClinicalEncounterActions(encounter.status);

    const runAction = (action: string) => {
        router.post(route(`clinical-encounters.${action}`, encounter.id), {}, { preserveScroll: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Encounter ${encounter.encounter_number}`} />

            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-semibold">{encounter.encounter_number}</h1>
                        <p className="text-muted-foreground text-sm">{patientName}</p>
                    </div>

                    <div className="flex flex-wrap items-center gap-2">
                        <Badge variant={clinicalEncounterStatusVariant[encounter.status]}>{clinicalEncounterStatusLabel[encounter.status]}</Badge>

                        {actions.includes('start') && can('clinical-encounters.start') && (
                            <Button size="sm" onClick={() => runAction('start')}>
                                <PlayCircle className="h-4 w-4" />
                                Start
                            </Button>
                        )}

                        {actions.includes('complete') && can('clinical-encounters.complete') && (
                            <CompleteEncounterDialog
                                encounterId={encounter.id}
                                patientId={encounter.patient?.id}
                                departmentId={encounter.department?.id}
                                trigger={<Button size="sm">Complete</Button>}
                            />
                        )}

                        {actions.includes('cancel') && can('clinical-encounters.cancel') && (
                            <CancelEncounterDialog
                                encounterId={encounter.id}
                                trigger={
                                    <Button size="sm" variant="destructive">
                                        <XCircle className="h-4 w-4" />
                                        Cancel
                                    </Button>
                                }
                            />
                        )}
                    </div>
                </div>

                <div className="grid gap-4 md:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Encounter Details</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <dl className="space-y-2 text-sm">
                                <div className="flex justify-between gap-4">
                                    <dt className="text-muted-foreground">Type</dt>
                                    <dd>{ENCOUNTER_TYPE_LABELS[encounter.encounter_type] ?? encounter.encounter_type}</dd>
                                </div>
                                <div className="flex justify-between gap-4">
                                    <dt className="text-muted-foreground">Department</dt>
                                    <dd>{encounter.department?.name ?? '—'}</dd>
                                </div>
                                <div className="flex justify-between gap-4">
                                    <dt className="text-muted-foreground">Practitioner</dt>
                                    <dd>
                                        {encounter.practitioner?.staff
                                            ? `${encounter.practitioner.staff.first_name} ${encounter.practitioner.staff.last_name}`
                                            : 'Not yet assigned'}
                                    </dd>
                                </div>
                                <div className="flex justify-between gap-4">
                                    <dt className="text-muted-foreground">Created</dt>
                                    <dd>{formatDateTime(encounter.created_at)}</dd>
                                </div>
                                {encounter.reception_visit && (
                                    <div className="flex justify-between gap-4">
                                        <dt className="text-muted-foreground">Reception Visit</dt>
                                        <dd>{encounter.reception_visit.visit_number}</dd>
                                    </div>
                                )}
                                {encounter.appointment && (
                                    <div className="flex justify-between gap-4">
                                        <dt className="text-muted-foreground">Appointment</dt>
                                        <dd>{encounter.appointment.appointment_number}</dd>
                                    </div>
                                )}
                                {(encounter.status === 'completed' || encounter.status === 'cancelled') && (
                                    <div className="flex justify-between gap-4">
                                        <dt className="text-muted-foreground">Ended</dt>
                                        <dd>{encounter.ended_at ? formatDateTime(encounter.ended_at) : '—'}</dd>
                                    </div>
                                )}
                                {encounter.disposition && (
                                    <div className="flex justify-between gap-4">
                                        <dt className="text-muted-foreground">Disposition</dt>
                                        <dd>{clinicalEncounterDispositionLabel[encounter.disposition]}</dd>
                                    </div>
                                )}
                                {encounter.disposition_notes && (
                                    <div className="flex justify-between gap-4">
                                        <dt className="text-muted-foreground">Disposition Notes</dt>
                                        <dd className="text-right">{encounter.disposition_notes}</dd>
                                    </div>
                                )}
                                {encounter.status === 'cancelled' && (
                                    <div className="flex justify-between gap-4">
                                        <dt className="text-muted-foreground">Cancellation Reason</dt>
                                        <dd>{encounter.cancellation_reason ?? '—'}</dd>
                                    </div>
                                )}
                            </dl>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Patient</CardTitle>
                        </CardHeader>
                        <CardContent>
                            {encounter.patient ? (
                                <dl className="space-y-2 text-sm">
                                    <div className="flex justify-between gap-4">
                                        <dt className="text-muted-foreground">Name</dt>
                                        <dd>
                                            <Link href={route('patients.show', encounter.patient.id)} className="font-medium hover:underline">
                                                {patientName}
                                            </Link>
                                        </dd>
                                    </div>
                                    <div className="flex justify-between gap-4">
                                        <dt className="text-muted-foreground">Patient Number</dt>
                                        <dd>{encounter.patient.patient_number}</dd>
                                    </div>
                                    <div className="flex justify-between gap-4">
                                        <dt className="text-muted-foreground">Date of Birth</dt>
                                        <dd>{encounter.patient.date_of_birth ?? '—'}</dd>
                                    </div>
                                    <div className="flex justify-between gap-4">
                                        <dt className="text-muted-foreground">Gender</dt>
                                        <dd>{encounter.patient.gender ?? '—'}</dd>
                                    </div>
                                </dl>
                            ) : (
                                <p className="text-muted-foreground text-sm">Patient information unavailable.</p>
                            )}
                        </CardContent>
                    </Card>
                </div>

                <WorkflowStatus
                    encounter={encounter}
                    triage={triage}
                    consultation={consultation}
                    diagnoses={diagnoses}
                    carePlan={carePlan}
                    clinicalNotes={clinicalNotes}
                />

                <TriagePanel encounterId={encounter.id} encounterStatus={encounter.status} triage={triage} />

                <ConsultationPanel encounterId={encounter.id} encounterStatus={encounter.status} triage={triage} consultation={consultation} />

                <DiagnosisSection encounterId={encounter.id} consultation={consultation} diagnoses={diagnoses} />

                <CarePlanSection
                    encounterId={encounter.id}
                    diagnoses={diagnoses}
                    carePlan={carePlan}
                    departments={departments}
                    referralPractitioners={referralPractitioners}
                />

                <LaboratoryOrdersSection encounterId={encounter.id} consultation={consultation} laboratoryOrders={laboratoryOrders} />

                <ClinicalNotesSection encounterId={encounter.id} clinicalNotes={clinicalNotes} />
            </div>
        </AppLayout>
    );
}
