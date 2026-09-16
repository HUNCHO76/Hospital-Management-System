import { EmptyState } from '@/components/empty-state';
import { Pagination } from '@/components/pagination';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { clinicalEncounterStatusLabel, clinicalEncounterStatusVariant } from '@/lib/clinical-encounter-status';
import { clinicalNoteStatusLabel, clinicalNoteStatusVariant, clinicalNoteTypeLabel } from '@/lib/clinical-note-status';
import { diagnosisStatusLabel, diagnosisStatusVariant } from '@/lib/diagnosis-status';
import { fulfillmentStatusLabel, fulfillmentStatusVariant } from '@/lib/dispensing-status';
import {
    prescriptionFrequencyLabel,
    prescriptionOrderStatusLabel,
    prescriptionOrderStatusVariant,
    prescriptionRouteLabel,
    prescriptionVerificationStatusLabel,
    prescriptionVerificationStatusVariant,
} from '@/lib/prescription-status';
import { radiologyModalityLabel, radiologyOrderStatusLabel, radiologyOrderStatusVariant } from '@/lib/radiology-status';
import { type BreadcrumbItem, type Paginated } from '@/types';
import { type ClinicalEncounterDepartment, type ClinicalEncounterPractitioner } from '@/types/clinical-encounter';
import { type ClinicalHistoryEncounter, type ClinicalHistoryFilters } from '@/types/clinical-history';
import { Head, Link, router } from '@inertiajs/react';
import { ChevronDown, History } from 'lucide-react';
import { useState } from 'react';

const ENCOUNTER_TYPE_LABELS: Record<string, string> = {
    opd: 'OPD',
    walk_in: 'Walk-in',
    scheduled: 'Scheduled',
    follow_up: 'Follow-up',
};

interface PatientClinicalHistoryProps {
    patient: {
        id: number;
        patient_number: string;
        first_name: string;
        middle_name: string | null;
        last_name: string;
    };
    encounters: Paginated<ClinicalHistoryEncounter>;
    filters: ClinicalHistoryFilters;
    departments: ClinicalEncounterDepartment[];
    practitioners: ClinicalEncounterPractitioner[];
    encounterTypes: string[];
}

function formatDateTime(value: string | null): string {
    return value ? new Date(value).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' }) : '—';
}

/**
 * The patient's clinical timeline (Step 26) — a separately-permissioned
 * aggregation of their clinical encounters, distinct from patients/history
 * (Step 16), which is deliberately non-clinical. No clinical data is stored
 * here; everything is read from its own authoritative record via
 * ClinicalHistoryService, with links back to the full encounter for detail.
 */
export default function PatientClinicalHistory({
    patient,
    encounters,
    filters,
    departments,
    practitioners,
    encounterTypes,
}: PatientClinicalHistoryProps) {
    const fullName = [patient.first_name, patient.middle_name, patient.last_name].filter(Boolean).join(' ');

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Patients', href: '/patients' },
        { title: fullName, href: `/patients/${patient.id}` },
        { title: 'Clinical History', href: `/patients/${patient.id}/clinical-history` },
    ];

    const applyFilters = (overrides: Partial<ClinicalHistoryFilters>) => {
        const params: Record<string, string> = { ...filters, ...overrides };
        router.get(route('patients.clinical-history', patient.id), params, { preserveState: true, replace: true });
    };

    const hasFilters = Boolean(
        filters.date_from ||
            filters.date_to ||
            filters.department_id ||
            filters.practitioner_id ||
            filters.encounter_type ||
            filters.status ||
            filters.diagnosis ||
            filters.note_type,
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Clinical History — ${fullName}`} />

            <div className="flex flex-col gap-6 p-4">
                <div>
                    <h1 className="text-2xl font-semibold">Clinical History</h1>
                    <p className="text-muted-foreground text-sm">
                        {fullName} · {patient.patient_number}
                    </p>
                </div>

                <div className="flex flex-wrap items-center gap-2">
                    <Select
                        value={filters.department_id || 'all'}
                        onValueChange={(value) => applyFilters({ department_id: value === 'all' ? '' : value })}
                    >
                        <SelectTrigger className="w-[200px]">
                            <SelectValue placeholder="All departments" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All departments</SelectItem>
                            {departments.map((department) => (
                                <SelectItem key={department.id} value={String(department.id)}>
                                    {department.name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>

                    <Select
                        value={filters.practitioner_id || 'all'}
                        onValueChange={(value) => applyFilters({ practitioner_id: value === 'all' ? '' : value })}
                    >
                        <SelectTrigger className="w-[200px]">
                            <SelectValue placeholder="All practitioners" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All practitioners</SelectItem>
                            {practitioners.map((practitioner) => (
                                <SelectItem key={practitioner.id} value={String(practitioner.id)}>
                                    {practitioner.staff
                                        ? `${practitioner.staff.first_name} ${practitioner.staff.last_name}`
                                        : practitioner.practitioner_number}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>

                    <Select
                        value={filters.encounter_type || 'all'}
                        onValueChange={(value) => applyFilters({ encounter_type: value === 'all' ? '' : value })}
                    >
                        <SelectTrigger className="w-[160px]">
                            <SelectValue placeholder="All types" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All types</SelectItem>
                            {encounterTypes.map((type) => (
                                <SelectItem key={type} value={type}>
                                    {ENCOUNTER_TYPE_LABELS[type] ?? type}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>

                    <Select value={filters.status || 'all'} onValueChange={(value) => applyFilters({ status: value === 'all' ? '' : value })}>
                        <SelectTrigger className="w-[160px]">
                            <SelectValue placeholder="All statuses" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All statuses</SelectItem>
                            {Object.entries(clinicalEncounterStatusLabel).map(([value, label]) => (
                                <SelectItem key={value} value={value}>
                                    {label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>

                    <Input
                        type="date"
                        className="w-[160px]"
                        value={filters.date_from || ''}
                        onChange={(e) => applyFilters({ date_from: e.target.value })}
                        aria-label="From date"
                    />
                    <Input
                        type="date"
                        className="w-[160px]"
                        value={filters.date_to || ''}
                        onChange={(e) => applyFilters({ date_to: e.target.value })}
                        aria-label="To date"
                    />
                    <Input
                        className="w-[200px]"
                        placeholder="Diagnosis code or text"
                        value={filters.diagnosis || ''}
                        onChange={(e) => applyFilters({ diagnosis: e.target.value })}
                        aria-label="Filter by diagnosis"
                    />

                    {hasFilters && (
                        <Button variant="ghost" size="sm" onClick={() => router.get(route('patients.clinical-history', patient.id))}>
                            Clear filters
                        </Button>
                    )}
                </div>

                {encounters.data.length > 0 ? (
                    <div className="flex flex-col gap-4">
                        {encounters.data.map((encounter) => (
                            <EncounterSummary key={encounter.id} encounter={encounter} />
                        ))}
                    </div>
                ) : (
                    <EmptyState
                        icon={History}
                        title="No clinical encounters found"
                        description="This patient's clinical timeline will appear here once an encounter is recorded."
                    />
                )}

                <Pagination links={encounters.links} />
            </div>
        </AppLayout>
    );
}

function EncounterSummary({ encounter }: { encounter: ClinicalHistoryEncounter }) {
    const [open, setOpen] = useState(false);

    return (
        <Card>
            <Collapsible open={open} onOpenChange={setOpen}>
                <CollapsibleTrigger asChild>
                    <button type="button" className="flex w-full items-center justify-between gap-4 px-6 py-4 text-left">
                        <div className="flex flex-wrap items-center gap-3">
                            <span className="font-medium">{encounter.encounter_number}</span>
                            <Badge variant={clinicalEncounterStatusVariant[encounter.status]}>{clinicalEncounterStatusLabel[encounter.status]}</Badge>
                            <span className="text-muted-foreground text-sm">
                                {ENCOUNTER_TYPE_LABELS[encounter.encounter_type] ?? encounter.encounter_type}
                            </span>
                            <span className="text-muted-foreground text-sm">{encounter.department?.name ?? '—'}</span>
                            <span className="text-muted-foreground text-sm">{formatDateTime(encounter.started_at)}</span>
                        </div>
                        <ChevronDown className={`h-4 w-4 shrink-0 transition-transform ${open ? 'rotate-180' : ''}`} />
                    </button>
                </CollapsibleTrigger>
                <CollapsibleContent>
                    <CardContent className="space-y-4 border-t pt-4">
                        <div className="flex justify-end">
                            <Button asChild variant="outline" size="sm">
                                <Link href={route('clinical-encounters.show', encounter.id)}>View Full Encounter</Link>
                            </Button>
                        </div>

                        <div>
                            <h4 className="text-muted-foreground mb-1 text-xs font-medium tracking-wide uppercase">Triage</h4>
                            {encounter.triage ? (
                                <p className="text-sm">
                                    {encounter.triage.triage_category ?? 'Not classified'} · {encounter.triage.status}
                                </p>
                            ) : (
                                <p className="text-muted-foreground text-sm">No triage recorded.</p>
                            )}
                        </div>

                        <div>
                            <h4 className="text-muted-foreground mb-1 text-xs font-medium tracking-wide uppercase">Consultation</h4>
                            {encounter.consultation ? (
                                <p className="text-sm">
                                    {encounter.consultation.chief_complaint ?? 'No chief complaint recorded'} · {encounter.consultation.status}
                                </p>
                            ) : (
                                <p className="text-muted-foreground text-sm">No consultation recorded.</p>
                            )}
                        </div>

                        <div>
                            <h4 className="text-muted-foreground mb-1 text-xs font-medium tracking-wide uppercase">Diagnoses</h4>
                            {encounter.diagnoses.length > 0 ? (
                                <ul className="space-y-1">
                                    {encounter.diagnoses.map((diagnosis) => (
                                        <li key={diagnosis.id} className="flex flex-wrap items-center gap-2 text-sm">
                                            <Badge variant={diagnosisStatusVariant[diagnosis.status]}>{diagnosisStatusLabel[diagnosis.status]}</Badge>
                                            <span>
                                                {diagnosis.diagnosis_code
                                                    ? `${diagnosis.diagnosis_code.code} — ${diagnosis.diagnosis_code.description}`
                                                    : '—'}
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            ) : (
                                <p className="text-muted-foreground text-sm">No diagnoses recorded.</p>
                            )}
                        </div>

                        <div>
                            <h4 className="text-muted-foreground mb-1 text-xs font-medium tracking-wide uppercase">Treatment</h4>
                            {encounter.care_plan ? (
                                <p className="text-sm">
                                    {encounter.care_plan.status} · {encounter.care_plan.items.length} item
                                    {encounter.care_plan.items.length === 1 ? '' : 's'}
                                </p>
                            ) : (
                                <p className="text-muted-foreground text-sm">No treatment plan recorded.</p>
                            )}
                        </div>

                        <div>
                            <h4 className="text-muted-foreground mb-1 text-xs font-medium tracking-wide uppercase">Clinical Notes</h4>
                            {encounter.clinical_notes.length > 0 ? (
                                <ul className="space-y-1">
                                    {encounter.clinical_notes.map((note) => (
                                        <li key={note.id} className="flex flex-wrap items-center gap-2 text-sm">
                                            <Badge variant="outline">{clinicalNoteTypeLabel[note.note_type]}</Badge>
                                            <Badge variant={clinicalNoteStatusVariant[note.status]}>{clinicalNoteStatusLabel[note.status]}</Badge>
                                            <span className="text-muted-foreground">{note.author?.name ?? '—'}</span>
                                        </li>
                                    ))}
                                </ul>
                            ) : (
                                <p className="text-muted-foreground text-sm">No clinical notes recorded.</p>
                            )}
                        </div>

                        <div>
                            <h4 className="text-muted-foreground mb-1 text-xs font-medium tracking-wide uppercase">Laboratory Results</h4>
                            {encounter.laboratory_results.length > 0 ? (
                                <ul className="space-y-1">
                                    {encounter.laboratory_results.map((result) => (
                                        <li key={result.id} className="flex flex-wrap items-center gap-2 text-sm">
                                            <Badge variant="secondary">Released</Badge>
                                            <span>
                                                {result.test_code} — {result.test_name}: <span className="font-medium">{result.value}</span>
                                                {result.unit ? ` ${result.unit}` : ''}
                                            </span>
                                            {result.abnormal_flag && result.abnormal_flag !== 'normal' && (
                                                <Badge variant={result.abnormal_flag === 'critical' ? 'destructive' : 'default'}>
                                                    {result.abnormal_flag}
                                                </Badge>
                                            )}
                                            {result.active_report_id && (
                                                <Link
                                                    href={route('laboratory.reports.show', result.active_report_id)}
                                                    className="text-primary hover:underline"
                                                >
                                                    View Report
                                                </Link>
                                            )}
                                        </li>
                                    ))}
                                </ul>
                            ) : (
                                <p className="text-muted-foreground text-sm">No released laboratory results yet.</p>
                            )}
                        </div>

                        <div>
                            <h4 className="text-muted-foreground mb-1 text-xs font-medium tracking-wide uppercase">Radiology Results</h4>
                            {encounter.radiology_results.length > 0 ? (
                                <ul className="space-y-1">
                                    {encounter.radiology_results.map((result) => (
                                        <li key={result.id} className="flex flex-wrap items-center gap-2 text-sm">
                                            <Badge variant="secondary">Released</Badge>
                                            <span>
                                                {result.procedure_name}
                                                {result.modality ? ` (${radiologyModalityLabel[result.modality]})` : ''}
                                            </span>
                                            <Link href={route('radiology-results.show', result.id)} className="text-primary hover:underline">
                                                View Report
                                            </Link>
                                        </li>
                                    ))}
                                </ul>
                            ) : (
                                <p className="text-muted-foreground text-sm">No released radiology results yet.</p>
                            )}
                        </div>

                        <div>
                            <h4 className="text-muted-foreground mb-1 text-xs font-medium tracking-wide uppercase">Radiology Orders</h4>
                            {encounter.radiology_orders.length > 0 ? (
                                <ul className="space-y-2">
                                    {encounter.radiology_orders.map((order) => (
                                        <li key={order.id} className="text-sm">
                                            <div className="flex flex-wrap items-center gap-2">
                                                <Link href={route('radiology.orders.show', order.id)} className="font-medium hover:underline">
                                                    {order.order_number}
                                                </Link>
                                                <Badge variant={radiologyOrderStatusVariant[order.status]}>
                                                    {radiologyOrderStatusLabel[order.status]}
                                                </Badge>
                                            </div>
                                            <ul className="text-muted-foreground ml-1 space-y-0.5">
                                                {order.items.map((item) => (
                                                    <li key={item.id} className={item.status === 'cancelled' ? 'line-through' : ''}>
                                                        {item.procedure_code} — {item.procedure_name}
                                                        {item.modality_snapshot && ` (${radiologyModalityLabel[item.modality_snapshot]})`}
                                                    </li>
                                                ))}
                                            </ul>
                                        </li>
                                    ))}
                                </ul>
                            ) : (
                                <p className="text-muted-foreground text-sm">No radiology orders recorded.</p>
                            )}
                        </div>

                        <div>
                            <h4 className="text-muted-foreground mb-1 text-xs font-medium tracking-wide uppercase">Prescriptions</h4>
                            {encounter.prescription_orders.length > 0 ? (
                                <ul className="space-y-2">
                                    {encounter.prescription_orders.map((order) => (
                                        <li key={order.id} className="text-sm">
                                            <div className="flex flex-wrap items-center gap-2">
                                                <Link href={route('pharmacy.prescriptions.show', order.id)} className="font-medium hover:underline">
                                                    {order.prescription_number}
                                                </Link>
                                                <Badge variant={prescriptionOrderStatusVariant[order.status]}>
                                                    {prescriptionOrderStatusLabel[order.status]}
                                                </Badge>
                                                {order.verification_status && (
                                                    <Badge variant={prescriptionVerificationStatusVariant[order.verification_status]}>
                                                        {prescriptionVerificationStatusLabel[order.verification_status]}
                                                    </Badge>
                                                )}
                                                {order.dispensing_status && (
                                                    <Badge variant={fulfillmentStatusVariant[order.dispensing_status]}>
                                                        {fulfillmentStatusLabel[order.dispensing_status]}
                                                    </Badge>
                                                )}
                                            </div>
                                            <ul className="text-muted-foreground ml-1 space-y-0.5">
                                                {order.items.map((item) => (
                                                    <li key={item.id} className={item.status === 'cancelled' ? 'line-through' : ''}>
                                                        {item.medication_name_snapshot}
                                                        {item.strength_snapshot ? ` ${item.strength_snapshot}` : ''} —{' '}
                                                        {[item.dose, item.dose_unit].filter(Boolean).join(' ')},{' '}
                                                        {prescriptionFrequencyLabel[item.frequency]}, {prescriptionRouteLabel[item.route]}
                                                    </li>
                                                ))}
                                            </ul>
                                        </li>
                                    ))}
                                </ul>
                            ) : (
                                <p className="text-muted-foreground text-sm">No prescriptions recorded.</p>
                            )}
                        </div>
                    </CardContent>
                </CollapsibleContent>
            </Collapsible>
        </Card>
    );
}
