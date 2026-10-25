import { CarePlanSection } from '@/components/care-plan/care-plan-section';
import { CancelEncounterDialog } from '@/components/clinical-encounters/cancel-encounter-dialog';
import { CompleteEncounterDialog } from '@/components/clinical-encounters/complete-encounter-dialog';
import { LaboratoryOrdersSection } from '@/components/clinical-encounters/laboratory-orders-section';
import { LaboratoryResultsSection } from '@/components/clinical-encounters/laboratory-results-section';
import { PrescriptionOrdersSection } from '@/components/clinical-encounters/prescription-orders-section';
import { ProcedureOrdersSection } from '@/components/clinical-encounters/procedure-orders-section';
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
import { type ProcedureOrder } from '@/types/procedure';
import { type RadiologyOrder, type RadiologyResult } from '@/types/radiology';
import { type TriageAssessment } from '@/types/triage';
import { NhifClaimFinalizeDialog } from '@/components/nhif/nhif-claim-finalize-dialog';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Head, Link, router } from '@inertiajs/react';
import { AlertCircle, AlertTriangle, PlayCircle, Send, XCircle } from 'lucide-react';
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
    procedureOrders: ProcedureOrder[];
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
    procedureOrders = [],
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

    const completedProcedures = procedureOrders.filter((o) => o.status === 'completed');
    const orderCount = laboratoryOrders.length + radiologyOrders.length + prescriptionOrders.length + procedureOrders.length;
    const resultCount = laboratoryResults.length + radiologyResults.length + completedProcedures.length;
    const hasPendingNhif = nhifPendingItems && (
        (nhifPendingItems.lab_items?.length ?? 0) +
        (nhifPendingItems.medication_items?.length ?? 0) +
        (nhifPendingItems.radiology_items?.length ?? 0) +
        ((nhifPendingItems as any).procedure_items?.length ?? 0)
    ) > 0;

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

                        {nhifEligible && can('claims.create') && (
                            encounter.status === 'completed' ? (
                                <NhifClaimFinalizeDialog
                                    clinicalEncounterId={encounter.id}
                                    patientName={patientName}
                                    patientCardNo={nhifInsurance?.card_number}
                                    trigger={
                                        <Button size="sm" variant="outline" className="gap-1 border-primary/40 text-primary">
                                            <Send className="h-4 w-4" />
                                            Send Claim
                                        </Button>
                                    }
                                />
                            ) : (
                                <Button
                                    size="sm"
                                    variant="outline"
                                    className="gap-1 border-amber-500/40 text-amber-700 hover:bg-amber-500/10 dark:text-amber-400"
                                    onClick={() => {
                                        alert('Doctor should complete Clinical Encounters for the patient before sending claim.');
                                    }}
                                    title="Doctor should complete Clinical Encounters for the patient before sending claim"
                                >
                                    <AlertCircle className="h-4 w-4" />
                                    Send Claim
                                </Button>
                            )
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

                {/* ── NHIF Expired Authorization Alert ── */}
                {nhifEligible && nhifInsurance?.is_authorization_expired && (
                    <Alert variant="destructive" className="border-amber-500/50 bg-amber-50/80 text-amber-900 dark:border-amber-500/40 dark:bg-amber-950/40 dark:text-amber-200">
                        <AlertTriangle className="h-5 w-5 text-amber-600 dark:text-amber-400" />
                        <div className="ml-2">
                            <AlertTitle className="text-base font-semibold text-amber-800 dark:text-amber-300">
                                NHIF Card Re-verification Required
                            </AlertTitle>
                            <AlertDescription className="text-sm text-amber-700 dark:text-amber-300">
                                This patient's 24-hour NHIF authorization has expired. Pre-approvals, orders, and claim submissions cannot be processed until the card is re-verified.
                                <div className="mt-1.5 font-semibold text-amber-900 dark:text-amber-100">
                                    Action: Please instruct the patient to visit Reception to re-verify their NHIF card.
                                </div>
                            </AlertDescription>
                        </div>
                    </Alert>
                )}

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

                    {/* ── Orders: Lab → Radiology → Prescriptions → Procedures ── */}
                    <TabsContent value="orders" className="flex flex-col gap-6">
                        <LaboratoryOrdersSection encounterId={encounter.id} consultation={consultation} laboratoryOrders={laboratoryOrders} />
                        <RadiologyOrdersSection
                            encounterId={encounter.id}
                            consultation={consultation}
                            radiologyOrders={radiologyOrders}
                            nhifEligible={nhifEligible}
                            nhifInsurance={nhifInsurance}
                            nhifPreApprovalSessions={nhifPreApprovalSessions}
                            patientName={patientName}
                        />
                        <PrescriptionOrdersSection
                            encounterId={encounter.id}
                            consultation={consultation}
                            prescriptionOrders={prescriptionOrders}
                            medicationSafetyRules={medicationSafetyRules}
                            nhifEligible={nhifEligible}
                            nhifInsurance={nhifInsurance}
                            nhifPreApprovalSessions={nhifPreApprovalSessions}
                            patientName={patientName}
                        />
                        <ProcedureOrdersSection
                            encounterId={encounter.id}
                            consultation={consultation}
                            procedureOrders={procedureOrders}
                            nhifEligible={nhifEligible}
                            nhifInsurance={nhifInsurance}
                            nhifPreApprovalSessions={nhifPreApprovalSessions}
                            patientName={patientName}
                        />
                    </TabsContent>

                    {/* ── Results: Lab Results → Radiology Results → Procedure Execution Records ── */}
                    <TabsContent value="results" className="flex flex-col gap-6">
                        <LaboratoryResultsSection laboratoryResults={laboratoryResults} />
                        <RadiologyResultsSection radiologyResults={radiologyResults} />

                        {completedProcedures.length > 0 && (
                            <Card>
                                <CardHeader>
                                    <div className="flex items-center justify-between">
                                        <CardTitle className="text-base flex items-center gap-2">
                                            <CheckCircle2 className="h-5 w-5 text-emerald-600" />
                                            Clinical Procedure Records ({completedProcedures.length})
                                        </CardTitle>
                                        <Badge variant="outline" className="text-emerald-700 border-emerald-500/40 bg-emerald-50 dark:bg-emerald-950/20">
                                            Official Documentation
                                        </Badge>
                                    </div>
                                </CardHeader>
                                <CardContent className="space-y-4">
                                    <div className="divide-y rounded-md border">
                                        {completedProcedures.map((proc) => (
                                            <div key={proc.id} className="p-4 space-y-2">
                                                <div className="flex flex-wrap items-center justify-between gap-2">
                                                    <div>
                                                        <span className="font-semibold text-base">{proc.procedure_name}</span>
                                                        <span className="text-xs text-muted-foreground ml-2 font-mono">
                                                            #{proc.order_number}
                                                        </span>
                                                    </div>
                                                    <span className="text-xs text-muted-foreground">
                                                        Performed: {proc.performed_at ? new Date(proc.performed_at).toLocaleString() : '—'} by{' '}
                                                        <strong>{proc.performer?.UserName ?? 'Clinician'}</strong>
                                                    </span>
                                                </div>

                                                {proc.procedure_notes && (
                                                    <div className="text-xs">
                                                        <span className="font-medium text-muted-foreground">Technique & Notes:</span>{' '}
                                                        {proc.procedure_notes}
                                                    </div>
                                                )}

                                                {proc.findings && (
                                                    <div className="text-xs">
                                                        <span className="font-medium text-muted-foreground">Findings:</span>{' '}
                                                        {proc.findings}
                                                    </div>
                                                )}

                                                {proc.results && (
                                                    <div className="text-xs text-emerald-800 dark:text-emerald-300 bg-emerald-50/60 dark:bg-emerald-950/30 p-2 rounded">
                                                        <span className="font-semibold">Results & Clinical Plan:</span> {proc.results}
                                                    </div>
                                                )}

                                                {proc.complications && (
                                                    <div className="text-xs text-destructive bg-destructive/10 p-2 rounded">
                                                        <span className="font-semibold">Complications:</span> {proc.complications}
                                                    </div>
                                                )}
                                            </div>
                                        ))}
                                    </div>
                                </CardContent>
                            </Card>
                        )}
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
