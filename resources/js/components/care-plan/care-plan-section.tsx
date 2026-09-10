import { CancelCarePlanDialog } from '@/components/care-plan/cancel-care-plan-dialog';
import { CancelCarePlanItemDialog } from '@/components/care-plan/cancel-care-plan-item-dialog';
import { CorrectCarePlanDialog } from '@/components/care-plan/correct-care-plan-dialog';
import { CorrectCarePlanItemDialog } from '@/components/care-plan/correct-care-plan-item-dialog';
import { MedicationSearch } from '@/components/care-plan/medication-search';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { usePermissions } from '@/hooks/use-permissions';
import {
    allowedCarePlanItemStatusActions,
    allowedCarePlanStatusActions,
    canCancelCarePlan,
    canCancelCarePlanItem,
    canCorrectCarePlan,
    canCorrectCarePlanItem,
    carePlanItemPriorityLabel,
    carePlanItemStatusLabel,
    carePlanItemStatusVariant,
    carePlanItemTypeLabel,
    carePlanStatusLabel,
    carePlanStatusVariant,
    isCarePlanMutable,
} from '@/lib/care-plan-status';
import { type CarePlan, type CarePlanItem, type CarePlanItemFormData, type CarePlanItemType, type PrescriptionRoute } from '@/types/care-plan';
import { type ClinicalEncounterDepartment, type ClinicalEncounterPractitioner } from '@/types/clinical-encounter';
import { type EncounterDiagnosis } from '@/types/diagnosis';
import { type MedicationSearchResult } from '@/types/medication';
import { router, useForm } from '@inertiajs/react';
import { CheckCircle2, ClipboardPlus, LoaderCircle, PenLine, PlayCircle, XCircle } from 'lucide-react';
import { type FormEventHandler, useState } from 'react';

interface CarePlanSectionProps {
    encounterId: number;
    diagnoses: EncounterDiagnosis[];
    carePlan: CarePlan | null;
    departments: ClinicalEncounterDepartment[];
    referralPractitioners: ClinicalEncounterPractitioner[];
}

const STATUS_ACTION_LABELS: Record<string, string> = { active: 'Activate', completed: 'Complete' };
const ITEM_STATUS_ACTION_LABELS: Record<string, string> = { active: 'Start', completed: 'Complete' };

const ITEM_TYPES: CarePlanItemType[] = [
    'medication',
    'procedure',
    'clinical_intervention',
    'referral',
    'monitoring',
    'follow_up',
    'radiology',
    'other',
];
const PRIORITIES: ('low' | 'medium' | 'high')[] = ['low', 'medium', 'high'];
const ROUTES: PrescriptionRoute[] = ['oral', 'intravenous', 'intramuscular', 'subcutaneous', 'topical', 'rectal', 'inhalation', 'other'];

function formatDateTime(value: string): string {
    return new Date(value).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });
}

/**
 * The treatment/care-plan workflow embedded on the clinical encounter screen
 * (spec §26), mirroring DiagnosisSection/ConsultationPanel — there is no
 * standalone treatment page. Requires at least one non-cancelled diagnosis
 * (spec §14); the backend re-validates this independently.
 */
export function CarePlanSection({ encounterId, diagnoses, carePlan, departments, referralPractitioners }: CarePlanSectionProps) {
    const { can } = usePermissions();
    const activeDiagnoses = diagnoses.filter((d) => d.status !== 'cancelled');

    if (activeDiagnoses.length === 0) {
        return (
            <Card>
                <CardHeader>
                    <CardTitle className="text-base">Treatment Plan</CardTitle>
                </CardHeader>
                <CardContent>
                    <p className="text-muted-foreground text-sm">A diagnosis must be recorded before a treatment plan can be created.</p>
                </CardContent>
            </Card>
        );
    }

    const startCarePlan = () => {
        router.post(route('clinical-encounters.care-plan.store', encounterId), {}, { preserveScroll: true });
    };

    if (!carePlan) {
        return (
            <Card>
                <CardHeader>
                    <CardTitle className="text-base">Treatment Plan</CardTitle>
                </CardHeader>
                <CardContent className="flex items-center justify-between gap-4">
                    <p className="text-muted-foreground text-sm">No treatment plan has been started for this encounter yet.</p>
                    {can('treatment.create') && (
                        <Button size="sm" onClick={startCarePlan}>
                            <ClipboardPlus className="h-4 w-4" />
                            Start Treatment Plan
                        </Button>
                    )}
                </CardContent>
            </Card>
        );
    }

    const runStatusAction = (status: string) => {
        router.post(route(`clinical-encounters.care-plan.${status === 'active' ? 'activate' : status}`, encounterId), {}, { preserveScroll: true });
    };

    const mutable = isCarePlanMutable(carePlan.status);

    return (
        <Card>
            <CardHeader className="flex flex-row flex-wrap items-center justify-between gap-2 space-y-0">
                <CardTitle className="text-base">Treatment Plan</CardTitle>
                <div className="flex flex-wrap items-center gap-2">
                    <Badge variant={carePlanStatusVariant[carePlan.status]}>{carePlanStatusLabel[carePlan.status]}</Badge>

                    {allowedCarePlanStatusActions(carePlan.status).map((status) => (
                        <Button
                            key={status}
                            size="sm"
                            onClick={() => runStatusAction(status)}
                            disabled={!can('treatment.activate') && !can('treatment.complete')}
                        >
                            <PlayCircle className="h-4 w-4" />
                            {STATUS_ACTION_LABELS[status] ?? status}
                        </Button>
                    ))}

                    {canCancelCarePlan(carePlan.status) && can('treatment.cancel') && (
                        <CancelCarePlanDialog
                            encounterId={encounterId}
                            trigger={
                                <Button size="sm" variant="destructive">
                                    <XCircle className="h-4 w-4" />
                                    Cancel
                                </Button>
                            }
                        />
                    )}

                    {canCorrectCarePlan(carePlan.status) && can('treatment.correct') && (
                        <CorrectCarePlanDialog
                            encounterId={encounterId}
                            carePlan={carePlan}
                            trigger={
                                <Button size="sm" variant="outline">
                                    <PenLine className="h-4 w-4" />
                                    Correct
                                </Button>
                            }
                        />
                    )}
                </div>
            </CardHeader>
            <CardContent className="space-y-6">
                {mutable && can('treatment.update') ? (
                    <CarePlanGoalsForm encounterId={encounterId} carePlan={carePlan} />
                ) : (
                    <div className="grid gap-3 text-sm sm:grid-cols-2">
                        <Field label="Goals" value={carePlan.goals} />
                        <Field label="Clinical Instructions" value={carePlan.clinical_instructions} />
                        <Field label="Monitoring Instructions" value={carePlan.monitoring_instructions} />
                        <Field label="Follow-up Instructions" value={carePlan.follow_up_instructions} />
                    </div>
                )}

                {carePlan.status === 'cancelled' && carePlan.cancellation_reason && (
                    <p className="text-muted-foreground text-sm">Cancelled: {carePlan.cancellation_reason}</p>
                )}
                {carePlan.corrected_at && (
                    <p className="text-muted-foreground text-sm">
                        Corrected {formatDateTime(carePlan.corrected_at)}
                        {carePlan.correction_reason ? ` — ${carePlan.correction_reason}` : ''}
                    </p>
                )}

                <div className="space-y-3 border-t pt-4">
                    <h3 className="text-sm font-medium">Treatment Items</h3>

                    {carePlan.items.length > 0 ? (
                        <ul className="divide-sidebar-border/70 dark:divide-sidebar-border divide-y">
                            {carePlan.items.map((item) => (
                                <CarePlanItemRow key={item.id} encounterId={encounterId} item={item} />
                            ))}
                        </ul>
                    ) : (
                        <p className="text-muted-foreground text-sm">No treatment items have been added yet.</p>
                    )}

                    {mutable && can('treatment.create') && (
                        <AddCarePlanItemForm
                            encounterId={encounterId}
                            diagnoses={activeDiagnoses}
                            departments={departments}
                            practitioners={referralPractitioners}
                        />
                    )}
                </div>
            </CardContent>
        </Card>
    );
}

function Field({ label, value }: { label: string; value: string | null }) {
    return (
        <div className="grid gap-1">
            <span className="text-muted-foreground">{label}</span>
            <span className="whitespace-pre-wrap">{value || '—'}</span>
        </div>
    );
}

function CarePlanGoalsForm({ encounterId, carePlan }: { encounterId: number; carePlan: CarePlan }) {
    const { data, setData, put, processing, errors } = useForm({
        goals: carePlan.goals ?? '',
        clinical_instructions: carePlan.clinical_instructions ?? '',
        monitoring_instructions: carePlan.monitoring_instructions ?? '',
        follow_up_instructions: carePlan.follow_up_instructions ?? '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        put(route('clinical-encounters.care-plan.update', encounterId), { preserveScroll: true });
    };

    return (
        <form onSubmit={submit} className="grid gap-4 sm:grid-cols-2">
            <div className="grid gap-1.5">
                <Label htmlFor="care_plan_goals">Goals</Label>
                <Textarea id="care_plan_goals" rows={2} value={data.goals} onChange={(e) => setData('goals', e.target.value)} />
                <InputError message={errors.goals} />
            </div>
            <div className="grid gap-1.5">
                <Label htmlFor="care_plan_clinical_instructions">Clinical Instructions</Label>
                <Textarea
                    id="care_plan_clinical_instructions"
                    rows={2}
                    value={data.clinical_instructions}
                    onChange={(e) => setData('clinical_instructions', e.target.value)}
                />
                <InputError message={errors.clinical_instructions} />
            </div>
            <div className="grid gap-1.5">
                <Label htmlFor="care_plan_monitoring_instructions">Monitoring Instructions</Label>
                <Textarea
                    id="care_plan_monitoring_instructions"
                    rows={2}
                    value={data.monitoring_instructions}
                    onChange={(e) => setData('monitoring_instructions', e.target.value)}
                />
                <InputError message={errors.monitoring_instructions} />
            </div>
            <div className="grid gap-1.5">
                <Label htmlFor="care_plan_follow_up_instructions">Follow-up Instructions</Label>
                <Textarea
                    id="care_plan_follow_up_instructions"
                    rows={2}
                    value={data.follow_up_instructions}
                    onChange={(e) => setData('follow_up_instructions', e.target.value)}
                />
                <InputError message={errors.follow_up_instructions} />
            </div>
            <div className="flex justify-end sm:col-span-2">
                <Button type="submit" size="sm" disabled={processing}>
                    {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                    Save
                </Button>
            </div>
        </form>
    );
}

function CarePlanItemRow({ encounterId, item }: { encounterId: number; item: CarePlanItem }) {
    const { can } = usePermissions();

    const runStatusAction = (status: string) => {
        router.post(route('clinical-encounters.care-plan.items.status', [encounterId, item.id]), { status }, { preserveScroll: true });
    };

    return (
        <li className="flex flex-col gap-2 py-3 first:pt-0 last:pb-0">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <div className="flex flex-wrap items-center gap-2">
                    <Badge variant="outline">{carePlanItemTypeLabel[item.type]}</Badge>
                    <Badge variant={carePlanItemStatusVariant[item.status]}>{carePlanItemStatusLabel[item.status]}</Badge>
                    {item.priority && <span className="text-muted-foreground text-xs">{carePlanItemPriorityLabel[item.priority]} priority</span>}
                    <span className="font-medium">{item.description}</span>
                </div>

                <div className="flex flex-wrap items-center gap-2">
                    {allowedCarePlanItemStatusActions(item.status).map((status) => (
                        <Button key={status} size="sm" onClick={() => runStatusAction(status)} disabled={!can('treatment.update')}>
                            <CheckCircle2 className="h-4 w-4" />
                            {ITEM_STATUS_ACTION_LABELS[status] ?? status}
                        </Button>
                    ))}

                    {canCancelCarePlanItem(item.status) && can('treatment.cancel') && (
                        <CancelCarePlanItemDialog
                            encounterId={encounterId}
                            itemId={item.id}
                            trigger={
                                <Button size="sm" variant="destructive">
                                    <XCircle className="h-4 w-4" />
                                    Cancel
                                </Button>
                            }
                        />
                    )}

                    {canCorrectCarePlanItem(item.status) && can('treatment.correct') && (
                        <CorrectCarePlanItemDialog
                            encounterId={encounterId}
                            item={item}
                            trigger={
                                <Button size="sm" variant="outline">
                                    <PenLine className="h-4 w-4" />
                                    Correct
                                </Button>
                            }
                        />
                    )}
                </div>
            </div>

            <div className="text-muted-foreground grid gap-1 text-xs">
                {item.encounter_diagnosis?.diagnosis_code && (
                    <span>
                        For: {item.encounter_diagnosis.diagnosis_code.code} — {item.encounter_diagnosis.diagnosis_code.description}
                    </span>
                )}
                {item.instructions && <span>{item.instructions}</span>}
                {item.prescription && (
                    <span>
                        {item.prescription.medication?.name}
                        {item.prescription.strength ? ` ${item.prescription.strength}` : ''}
                        {item.prescription.dose ? ` — ${item.prescription.dose}` : ''}
                        {item.prescription.route ? `, ${item.prescription.route}` : ''}
                        {item.prescription.frequency ? `, ${item.prescription.frequency}` : ''}
                        {item.prescription.duration ? `, ${item.prescription.duration}` : ''}
                        {item.prescription.instructions ? ` (${item.prescription.instructions})` : ''}
                    </span>
                )}
                {item.type === 'referral' && (
                    <span>
                        Referred to: {item.referred_to_department?.name ?? '—'}
                        {item.referred_to_practitioner?.name ? ` — ${item.referred_to_practitioner.name}` : ''}
                    </span>
                )}
                {(item.start_date || item.end_date) && (
                    <span>
                        {item.start_date ?? '—'} to {item.end_date ?? '—'}
                    </span>
                )}
                <span>
                    {item.created_by?.name ?? '—'} · {formatDateTime(item.created_at)}
                </span>
                {item.status === 'cancelled' && item.cancellation_reason && <span>Cancelled: {item.cancellation_reason}</span>}
                {item.corrected_at && <span>Corrected {formatDateTime(item.corrected_at)}</span>}
            </div>
        </li>
    );
}

function AddCarePlanItemForm({
    encounterId,
    diagnoses,
    departments,
    practitioners,
}: {
    encounterId: number;
    diagnoses: EncounterDiagnosis[];
    departments: ClinicalEncounterDepartment[];
    practitioners: ClinicalEncounterPractitioner[];
}) {
    const [selectedMedication, setSelectedMedication] = useState<MedicationSearchResult | null>(null);
    const { data, setData, post, processing, errors, reset } = useForm<CarePlanItemFormData>({
        type: '',
        description: '',
        priority: '',
        encounter_diagnosis_id: '',
        start_date: '',
        end_date: '',
        instructions: '',
        referred_to_department_id: '',
        referred_to_practitioner_id: '',
        prescription: {
            medication_id: '',
            strength: '',
            dose: '',
            route: '',
            frequency: '',
            duration: '',
            quantity: '',
            instructions: '',
        },
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('clinical-encounters.care-plan.items.store', encounterId), {
            preserveScroll: true,
            onSuccess: () => {
                reset();
                setSelectedMedication(null);
            },
        });
    };

    const setPrescriptionField = (key: string, value: string) => {
        setData('prescription', { ...data.prescription, [key]: value });
    };

    return (
        <form onSubmit={submit} className="space-y-4 rounded-md border p-3">
            <h4 className="text-sm font-medium">Add Treatment Item</h4>

            <div className="grid gap-4 sm:grid-cols-2">
                <div className="grid gap-1.5">
                    <Label>Type</Label>
                    <Select value={data.type} onValueChange={(value) => setData('type', value as CarePlanItemType)}>
                        <SelectTrigger>
                            <SelectValue placeholder="Select a type" />
                        </SelectTrigger>
                        <SelectContent>
                            {ITEM_TYPES.map((type) => (
                                <SelectItem key={type} value={type}>
                                    {carePlanItemTypeLabel[type]}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <InputError message={errors.type} />
                </div>

                <div className="grid gap-1.5">
                    <Label>Priority</Label>
                    <Select value={data.priority} onValueChange={(value) => setData('priority', value as CarePlanItemFormData['priority'])}>
                        <SelectTrigger>
                            <SelectValue placeholder="Not set" />
                        </SelectTrigger>
                        <SelectContent>
                            {PRIORITIES.map((priority) => (
                                <SelectItem key={priority} value={priority}>
                                    {carePlanItemPriorityLabel[priority]}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <InputError message={errors.priority} />
                </div>

                <div className="grid gap-1.5 sm:col-span-2">
                    <Label htmlFor="item_description">Description</Label>
                    <Textarea id="item_description" rows={2} value={data.description} onChange={(e) => setData('description', e.target.value)} />
                    <InputError message={errors.description} />
                </div>

                <div className="grid gap-1.5 sm:col-span-2">
                    <Label>Addresses Diagnosis</Label>
                    <Select
                        value={data.encounter_diagnosis_id || 'none'}
                        onValueChange={(value) => setData('encounter_diagnosis_id', value === 'none' ? '' : value)}
                    >
                        <SelectTrigger>
                            <SelectValue placeholder="Not specific to a diagnosis" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="none">Not specific to a diagnosis</SelectItem>
                            {diagnoses.map((diagnosis) => (
                                <SelectItem key={diagnosis.id} value={String(diagnosis.id)}>
                                    {diagnosis.diagnosis_code ? `${diagnosis.diagnosis_code.code} — ${diagnosis.diagnosis_code.description}` : '—'}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <InputError message={errors.encounter_diagnosis_id} />
                </div>

                <div className="grid gap-1.5">
                    <Label htmlFor="item_start_date">Start Date</Label>
                    <Input id="item_start_date" type="date" value={data.start_date} onChange={(e) => setData('start_date', e.target.value)} />
                    <InputError message={errors.start_date} />
                </div>
                <div className="grid gap-1.5">
                    <Label htmlFor="item_end_date">End Date</Label>
                    <Input id="item_end_date" type="date" value={data.end_date} onChange={(e) => setData('end_date', e.target.value)} />
                    <InputError message={errors.end_date} />
                </div>

                <div className="grid gap-1.5 sm:col-span-2">
                    <Label htmlFor="item_instructions">Instructions</Label>
                    <Textarea id="item_instructions" rows={2} value={data.instructions} onChange={(e) => setData('instructions', e.target.value)} />
                    <InputError message={errors.instructions} />
                </div>

                {data.type === 'referral' && (
                    <>
                        <div className="grid gap-1.5">
                            <Label>Referred-to Department</Label>
                            <Select value={data.referred_to_department_id} onValueChange={(value) => setData('referred_to_department_id', value)}>
                                <SelectTrigger>
                                    <SelectValue placeholder="Select a department" />
                                </SelectTrigger>
                                <SelectContent>
                                    {departments.map((department) => (
                                        <SelectItem key={department.id} value={String(department.id)}>
                                            {department.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={errors.referred_to_department_id} />
                        </div>
                        <div className="grid gap-1.5">
                            <Label>Referred-to Practitioner</Label>
                            <Select
                                value={data.referred_to_practitioner_id || 'none'}
                                onValueChange={(value) => setData('referred_to_practitioner_id', value === 'none' ? '' : value)}
                            >
                                <SelectTrigger>
                                    <SelectValue placeholder="Not specified" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="none">Not specified</SelectItem>
                                    {practitioners.map((practitioner) => (
                                        <SelectItem key={practitioner.id} value={String(practitioner.id)}>
                                            {practitioner.staff
                                                ? `${practitioner.staff.first_name} ${practitioner.staff.last_name}`
                                                : practitioner.practitioner_number}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={errors.referred_to_practitioner_id} />
                        </div>
                    </>
                )}

                {data.type === 'medication' && (
                    <div className="space-y-4 rounded-md border p-3 sm:col-span-2">
                        <h5 className="text-sm font-medium">Prescription</h5>

                        <div className="grid gap-1.5">
                            <Label>Medication</Label>
                            <MedicationSearch
                                selected={selectedMedication}
                                onSelect={(medication) => {
                                    setSelectedMedication(medication);
                                    setPrescriptionField('medication_id', String(medication.id));
                                }}
                            />
                            <InputError message={errors['prescription.medication_id']} />
                        </div>

                        <div className="grid gap-4 sm:grid-cols-3">
                            <div className="grid gap-1.5">
                                <Label htmlFor="prescription_strength">Strength</Label>
                                <Input
                                    id="prescription_strength"
                                    value={data.prescription.strength}
                                    onChange={(e) => setPrescriptionField('strength', e.target.value)}
                                    placeholder="e.g. 500mg"
                                />
                                <InputError message={errors['prescription.strength']} />
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor="prescription_dose">Dose</Label>
                                <Input
                                    id="prescription_dose"
                                    value={data.prescription.dose}
                                    onChange={(e) => setPrescriptionField('dose', e.target.value)}
                                    placeholder="e.g. 1 tablet"
                                />
                                <InputError message={errors['prescription.dose']} />
                            </div>
                            <div className="grid gap-1.5">
                                <Label>Route</Label>
                                <Select value={data.prescription.route} onValueChange={(value) => setPrescriptionField('route', value)}>
                                    <SelectTrigger>
                                        <SelectValue placeholder="Select a route" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {ROUTES.map((route) => (
                                            <SelectItem key={route} value={route}>
                                                {route}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <InputError message={errors['prescription.route']} />
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor="prescription_frequency">Frequency</Label>
                                <Input
                                    id="prescription_frequency"
                                    value={data.prescription.frequency}
                                    onChange={(e) => setPrescriptionField('frequency', e.target.value)}
                                    placeholder="e.g. twice daily"
                                />
                                <InputError message={errors['prescription.frequency']} />
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor="prescription_duration">Duration</Label>
                                <Input
                                    id="prescription_duration"
                                    value={data.prescription.duration}
                                    onChange={(e) => setPrescriptionField('duration', e.target.value)}
                                    placeholder="e.g. 5 days"
                                />
                                <InputError message={errors['prescription.duration']} />
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor="prescription_quantity">Quantity</Label>
                                <Input
                                    id="prescription_quantity"
                                    value={data.prescription.quantity}
                                    onChange={(e) => setPrescriptionField('quantity', e.target.value)}
                                    placeholder="e.g. 10 tablets"
                                />
                                <InputError message={errors['prescription.quantity']} />
                            </div>
                        </div>

                        <div className="grid gap-1.5">
                            <Label htmlFor="prescription_instructions">Prescription Instructions</Label>
                            <Textarea
                                id="prescription_instructions"
                                rows={2}
                                value={data.prescription.instructions}
                                onChange={(e) => setPrescriptionField('instructions', e.target.value)}
                                placeholder="e.g. Take after meals"
                            />
                            <InputError message={errors['prescription.instructions']} />
                        </div>
                    </div>
                )}
            </div>

            <div className="flex justify-end">
                <Button type="submit" size="sm" disabled={processing || !data.type || !data.description}>
                    {processing ? <LoaderCircle className="h-4 w-4 animate-spin" /> : <ClipboardPlus className="h-4 w-4" />}
                    Add Item
                </Button>
            </div>
        </form>
    );
}
