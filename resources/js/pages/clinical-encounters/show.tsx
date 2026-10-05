import { CarePlanSection } from '@/components/care-plan/care-plan-section';
import { CancelEncounterDialog } from '@/components/clinical-encounters/cancel-encounter-dialog';
import { CompleteEncounterDialog } from '@/components/clinical-encounters/complete-encounter-dialog';
import { LaboratoryOrdersSection } from '@/components/clinical-encounters/laboratory-orders-section';
import { LaboratoryResultsSection } from '@/components/clinical-encounters/laboratory-results-section';
import { PrescriptionOrdersSection } from '@/components/clinical-encounters/prescription-orders-section';
import { RadiologyOrdersSection } from '@/components/clinical-encounters/radiology-orders-section';
import { RadiologyResultsSection } from '@/components/clinical-encounters/radiology-results-section';
import { WorkflowStatus } from '@/components/clinical-encounters/workflow-status';
import { ClinicalNotesSection } from '@/components/clinical-notes/clinical-notes-section';
import { ConsultationPanel } from '@/components/consultation/consultation-panel';
import { DiagnosisSection } from '@/components/diagnosis/diagnosis-section';
import { NhifPreApprovalSection } from '@/components/nhif/nhif-pre-approval-section';
import { TriagePanel } from '@/components/triage/triage-panel';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
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
import { type LaboratoryOrder, type LaboratoryReport } from '@/types/laboratory';
import { type NhifInsuranceSummary, type NhifPendingItems, type NhifPreApprovalSession } from '@/types/nhif';
import { type MedicationSafetyWarning, type PrescriptionOrder } from '@/types/prescription';
import { type RadiologyOrder, type RadiologyResult } from '@/types/radiology';
import { type TriageAssessment } from '@/types/triage';
import { Head, Link, router } from '@inertiajs/react';
import { PlayCircle, XCircle } from 'lucide-react';
import { useState } from 'react';

interface EncounterShowProps {
    encounter: ClinicalEncounter;
    triage: TriageAssessment | null;
    consultation: Consultation | null;
    diagnoses: EncounterDiagnosis[];
    carePlan: CarePlan | null;
    laboratoryOrders: LaboratoryOrder[];
    laboratoryResults: LaboratoryReport[];
    radiologyOrders: RadiologyOrder[];
    radiologyResults: RadiologyResult[];
    prescriptionOrders: PrescriptionOrder[];
    clinicalNotes: ClinicalNote[];
    medicationSafetyRules: Record<number, MedicationSafetyWarning[]>;
    departments: ClinicalEncounterDepartment[];
    referralPractitioners: ClinicalEncounterPractitioner[];
    nhifEligible: boolean;
    nhifInsurance: NhifInsuranceSummary | null;
    nhifPreApprovalSessions: NhifPreApprovalSession[];
    nhifPendingItems: NhifPendingItems | null;
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

function readStoredTab(encounterId: number): string {
    try {
        return localStorage.getItem(`encounter-tab-${encounterId}`) ?? 'assessment';
    } catch {
        return 'assessment';
    }
}

function storeTab(encounterId: number, tab: string) {
    try {
        localStorage.setItem(`encounter-tab-${encounterId}`, tab);
    } catch {
        // localStorage unavailable
    }
}

function TabBadge({ count }: { count: number }) {
    if (count <= 0) return null;
    return <span className="bg-primary/10 text-primary ml-1.5 rounded-full px-1.5 py-0.5 text-xs font-medium">{count}</span>;
}

export default function EncounterShow({
    encounter,
    triage,
    consultation,
    diagnoses,
    carePlan,
    laboratoryOrders,
    laboratoryResults,
    radiologyOrders,
    radiologyResults,
    prescriptionOrders,
    clinicalNotes,
    medicationSafetyRules,
    departments,
    referralPractitioners,
    nhifEligible,
    nhifInsurance,
    nhifPreApprovalSessions,
    nhifPendingItems,
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

    const [activeTab, setActiveTab] = useState(() => readStoredTab(encounter.id));

    const handleTabChange = (tab: string) => {
        setActiveTab(tab);
        storeTab(encounter.id, tab);
    };

    const orderCount = laboratoryOrders.length + radiologyOrders.length + prescriptionOrders.length;
    const resultCount = laboratoryResults.length + radiologyResults.length;
    const hasPendingNhif = nhifPendingItems && ((nhifPendingItems.lab_items?.length ?? 0) + (nhifPendingItems.medication_items?.length ?? 0)) > 0;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Encounter ${encounter.encounter_number}`} />

            <div className="flex flex-col gap-6 p-4">
                {/* ── Header ── */}
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

                {/* ── Encounter Details + Patient Cards ── */}
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

                {/* ── Workflow Status (always visible) ── */}
                <WorkflowStatus
                    encounter={encounter}
                    triage={triage}
                    consultation={consultation}
                    diagnoses={diagnoses}
                    carePlan={carePlan}
                    clinicalNotes={clinicalNotes}
                />

                {/* ── Tabbed Clinical Sections ── */}
                <Tabs value={activeTab} onValueChange={handleTabChange}>
                    <TabsList className="flex flex-wrap gap-1">
                        <TabsTrigger value="assessment">Assessment</TabsTrigger>
                        <TabsTrigger value="diagnosis">
                            Diagnosis
                            <TabBadge count={diagnoses.length} />
                        </TabsTrigger>
                        <TabsTrigger value="orders">
                            Orders
                            <TabBadge count={orderCount} />
                        </TabsTrigger>
                        <TabsTrigger value="results">
                            Results
                            <TabBadge count={resultCount} />
                        </TabsTrigger>
                        <TabsTrigger value="careplan">
                            Care Plan & NHIF
                            {hasPendingNhif && <span className="bg-amber-500 ml-1.5 inline-block h-2 w-2 rounded-full" />}
                        </TabsTrigger>
                    </TabsList>

                    {/* ── Assessment: Triage → Consultation → Clinical Notes ── */}
                    <TabsContent value="assessment" className="flex flex-col gap-6">
                        <TriagePanel encounterId={encounter.id} encounterStatus={encounter.status} triage={triage} />
                        <ConsultationPanel encounterId={encounter.id} encounterStatus={encounter.status} triage={triage} consultation={consultation} />
                        <ClinicalNotesSection encounterId={encounter.id} clinicalNotes={clinicalNotes} />
                    </TabsContent>

                    {/* ── Diagnosis ── */}
                    <TabsContent value="diagnosis" className="flex flex-col gap-6">
                        <DiagnosisSection encounterId={encounter.id} consultation={consultation} diagnoses={diagnoses} nhifEligible={nhifEligible} />
                    </TabsContent>

                    {/* ── Orders: Lab → Radiology → Prescriptions ── */}
                    <TabsContent value="orders" className="flex flex-col gap-6">
                        <LaboratoryOrdersSection encounterId={encounter.id} consultation={consultation} laboratoryOrders={laboratoryOrders} />
                        <RadiologyOrdersSection encounterId={encounter.id} consultation={consultation} radiologyOrders={radiologyOrders} />
                        <PrescriptionOrdersSection
                            encounterId={encounter.id}
                            consultation={consultation}
                            prescriptionOrders={prescriptionOrders}
                            medicationSafetyRules={medicationSafetyRules}
                        />
                    </TabsContent>

                    {/* ── Results: Lab Results → Radiology Results ── */}
                    <TabsContent value="results" className="flex flex-col gap-6">
                        <LaboratoryResultsSection laboratoryResults={laboratoryResults} />
                        <RadiologyResultsSection radiologyResults={radiologyResults} />
                    </TabsContent>

                    {/* ── Care Plan & NHIF ── */}
                    <TabsContent value="careplan" className="flex flex-col gap-6">
                        <CarePlanSection
                            encounterId={encounter.id}
                            diagnoses={diagnoses}
                            carePlan={carePlan}
                            departments={departments}
                            referralPractitioners={referralPractitioners}
                        />
                        <NhifPreApprovalSection
                            encounterId={encounter.id}
                            insurance={nhifInsurance ?? null}
                            pendingItems={nhifPendingItems ?? null}
                            sessions={nhifPreApprovalSessions ?? []}
                            patientName={patientName}
                            practitioner={encounter.practitioner ?? null}
                        />
                    </TabsContent>
                </Tabs>
            </div>
        </AppLayout>
    );
}
