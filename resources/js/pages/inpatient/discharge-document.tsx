import { PageHeader } from '@/components/page-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { type Admission } from '@/types/admission';
import { Head, useForm } from '@inertiajs/react';
import { AlertCircle, ClipboardList, FileText, Printer } from 'lucide-react';
import { useState } from 'react';

// ---------------------------------------------------------------------------
// Types
// ---------------------------------------------------------------------------

interface DischargeData {
    admission: {
        admission_number: string;
        admission_type: string | null;
        ward_type: string | null;
        room_type: string | null;
        admitted_at: string | null;
        discharged_at: string | null;
        discharge_type: string | null;
        discharge_reason: string | null;
        diagnosis_at_admission: string | null;
        clinical_status: string | null;
        status: string;
        hospital_name: string | null;
        department_name: string | null;
        practitioner_name: string | null;
    };
    patient: {
        patient_number: string;
        full_name: string;
        date_of_birth: string | null;
        gender: string | null;
        phone_number: string | null;
    };
    diagnoses: Array<{
        code: string | null;
        description: string | null;
        status: string | null;
        is_primary: boolean;
    }>;
    clinical_notes: Array<{
        note_type: string | null;
        content: string | null;
        status: string;
        signed_at: string | null;
        author: string | null;
    }>;
    discharge_medications: Array<{
        medication_name: string | null;
        dose: string | null;
        dose_unit: string | null;
        route: string | null;
        frequency: string | null;
        duration: number | null;
        duration_unit: string | null;
        quantity: number | null;
    }>;
    laboratory_findings: Array<{
        report_number: string | null;
        released_at: string | null;
        results_snapshot: unknown;
    }>;
    radiology_findings: Array<{
        findings: string | null;
        impression: string | null;
        recommendations: string | null;
        released_at: string | null;
    }>;
    care_plans: Array<{
        description: string | null;
        status: string | null;
    }>;
    vitals_at_discharge: {
        recorded_at: string | null;
        temperature_c: number | null;
        systolic_mmhg: number | null;
        diastolic_mmhg: number | null;
        pulse_bpm: number | null;
        respiratory_rate_bpm: number | null;
        oxygen_saturation_pct: number | null;
        weight_kg: number | null;
    } | null;
    financial_summary: {
        payer_type: string;
        total_charges: number;
        nhif_covered: number;
        patient_responsibility: number;
        cash_paid: number;
        outstanding: number;
        status: string;
        currency: string;
    };
    insurance: {
        membership_number: string | null;
        nhif_authorization_no: string | null;
        nhif_status: string | null;
        provider_name: string | null;
        scheme_name: string | null;
    } | null;
    generated_at: string;
}

interface DischargeDocument {
    id: number;
    document_number: string;
    version: number;
    status: 'active' | 'superseded';
    data_snapshot: DischargeData;
    generated_at: string;
    amendment_reason: string | null;
    generated_by: { id: number; UserName: string } | null;
    amended_by: { id: number; UserName: string } | null;
    amended_at: string | null;
}

interface Props {
    admission: Admission;
    document: DischargeDocument | null;
    history: DischargeDocument[];
    canGenerate: boolean;
    canViewBilling: boolean;
    canViewNhif: boolean;
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function fmt(iso: string | null | undefined): string {
    if (!iso) return '—';
    return new Date(iso).toLocaleString('en-KE', {
        dateStyle: 'medium',
        timeStyle: 'short',
    });
}

function fmtDate(iso: string | null | undefined): string {
    if (!iso) return '—';
    return new Date(iso).toLocaleDateString('en-KE', { dateStyle: 'medium' });
}

function currency(amount: number, code: string): string {
    return `${code} ${amount.toLocaleString('en-KE', { minimumFractionDigits: 2 })}`;
}

// ---------------------------------------------------------------------------
// Sub-sections
// ---------------------------------------------------------------------------

function PatientSection({ data }: { data: DischargeData }) {
    const adm = data.admission;
    const pat = data.patient;

    return (
        <Card className="print:shadow-none">
            <CardHeader>
                <CardTitle className="flex items-center gap-2 text-base">
                    <FileText className="h-4 w-4" />
                    Patient &amp; Admission Details
                </CardTitle>
            </CardHeader>
            <CardContent>
                <div className="grid grid-cols-2 gap-x-8 gap-y-2 text-sm md:grid-cols-3">
                    <div>
                        <Label className="text-muted-foreground">Patient Name</Label>
                        <p className="font-medium">{pat.full_name || '—'}</p>
                    </div>
                    <div>
                        <Label className="text-muted-foreground">Patient No.</Label>
                        <p>{pat.patient_number}</p>
                    </div>
                    <div>
                        <Label className="text-muted-foreground">Date of Birth</Label>
                        <p>{fmtDate(pat.date_of_birth)}</p>
                    </div>
                    <div>
                        <Label className="text-muted-foreground">Gender</Label>
                        <p className="capitalize">{pat.gender || '—'}</p>
                    </div>
                    <div>
                        <Label className="text-muted-foreground">Phone</Label>
                        <p>{pat.phone_number || '—'}</p>
                    </div>
                    <div>
                        <Label className="text-muted-foreground">Admission No.</Label>
                        <p className="font-medium">{adm.admission_number}</p>
                    </div>
                    <div>
                        <Label className="text-muted-foreground">Admitted</Label>
                        <p>{fmt(adm.admitted_at)}</p>
                    </div>
                    <div>
                        <Label className="text-muted-foreground">Discharged</Label>
                        <p>{fmt(adm.discharged_at)}</p>
                    </div>
                    <div>
                        <Label className="text-muted-foreground">Ward / Room</Label>
                        <p>{[adm.ward_type, adm.room_type].filter(Boolean).join(' / ') || '—'}</p>
                    </div>
                    <div>
                        <Label className="text-muted-foreground">Discharge Type</Label>
                        <p className="capitalize">{adm.discharge_type?.replace(/_/g, ' ') || '—'}</p>
                    </div>
                    <div>
                        <Label className="text-muted-foreground">Attending Clinician</Label>
                        <p>{adm.practitioner_name || '—'}</p>
                    </div>
                    <div>
                        <Label className="text-muted-foreground">Department</Label>
                        <p>{adm.department_name || '—'}</p>
                    </div>
                    {adm.diagnosis_at_admission && (
                        <div className="col-span-2 md:col-span-3">
                            <Label className="text-muted-foreground">Diagnosis at Admission</Label>
                            <p>{adm.diagnosis_at_admission}</p>
                        </div>
                    )}
                    {adm.discharge_reason && (
                        <div className="col-span-2 md:col-span-3">
                            <Label className="text-muted-foreground">Discharge Reason</Label>
                            <p>{adm.discharge_reason}</p>
                        </div>
                    )}
                </div>
            </CardContent>
        </Card>
    );
}

function DiagnosesSection({ diagnoses }: { diagnoses: DischargeData['diagnoses'] }) {
    if (!diagnoses.length) return null;

    return (
        <Card className="print:shadow-none">
            <CardHeader>
                <CardTitle className="text-base">Diagnoses</CardTitle>
            </CardHeader>
            <CardContent>
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Code</TableHead>
                            <TableHead>Description</TableHead>
                            <TableHead>Type</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {diagnoses.map((d, i) => (
                            <TableRow key={i}>
                                <TableCell className="font-mono text-xs">{d.code || '—'}</TableCell>
                                <TableCell>{d.description || '—'}</TableCell>
                                <TableCell>
                                    {d.is_primary && <Badge variant="default">Primary</Badge>}
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </CardContent>
        </Card>
    );
}

function MedicationsSection({ medications }: { medications: DischargeData['discharge_medications'] }) {
    if (!medications.length) return null;

    return (
        <Card className="print:shadow-none">
            <CardHeader>
                <CardTitle className="text-base">Discharge Medications</CardTitle>
            </CardHeader>
            <CardContent>
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Medication</TableHead>
                            <TableHead>Dose</TableHead>
                            <TableHead>Route</TableHead>
                            <TableHead>Frequency</TableHead>
                            <TableHead>Duration</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {medications.map((m, i) => (
                            <TableRow key={i}>
                                <TableCell className="font-medium">{m.medication_name || '—'}</TableCell>
                                <TableCell>{m.dose ? `${m.dose} ${m.dose_unit ?? ''}`.trim() : '—'}</TableCell>
                                <TableCell className="capitalize">{m.route?.replace(/_/g, ' ') || '—'}</TableCell>
                                <TableCell>{m.frequency || '—'}</TableCell>
                                <TableCell>
                                    {m.duration ? `${m.duration} ${m.duration_unit ?? ''}`.trim() : '—'}
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </CardContent>
        </Card>
    );
}

function VitalsSection({ vitals }: { vitals: DischargeData['vitals_at_discharge'] }) {
    if (!vitals) return null;

    return (
        <Card className="print:shadow-none">
            <CardHeader>
                <CardTitle className="text-base">Condition at Discharge (Latest Vitals)</CardTitle>
            </CardHeader>
            <CardContent>
                <p className="text-muted-foreground mb-3 text-xs">Recorded: {fmt(vitals.recorded_at)}</p>
                <div className="grid grid-cols-2 gap-x-8 gap-y-2 text-sm md:grid-cols-4">
                    <div>
                        <Label className="text-muted-foreground">Temperature</Label>
                        <p>{vitals.temperature_c != null ? `${vitals.temperature_c} °C` : '—'}</p>
                    </div>
                    <div>
                        <Label className="text-muted-foreground">Blood Pressure</Label>
                        <p>
                            {vitals.systolic_mmhg != null && vitals.diastolic_mmhg != null
                                ? `${vitals.systolic_mmhg}/${vitals.diastolic_mmhg} mmHg`
                                : '—'}
                        </p>
                    </div>
                    <div>
                        <Label className="text-muted-foreground">Pulse</Label>
                        <p>{vitals.pulse_bpm != null ? `${vitals.pulse_bpm} bpm` : '—'}</p>
                    </div>
                    <div>
                        <Label className="text-muted-foreground">Resp. Rate</Label>
                        <p>{vitals.respiratory_rate_bpm != null ? `${vitals.respiratory_rate_bpm} /min` : '—'}</p>
                    </div>
                    <div>
                        <Label className="text-muted-foreground">SpO₂</Label>
                        <p>
                            {vitals.oxygen_saturation_pct != null ? `${vitals.oxygen_saturation_pct}%` : '—'}
                        </p>
                    </div>
                    <div>
                        <Label className="text-muted-foreground">Weight</Label>
                        <p>{vitals.weight_kg != null ? `${vitals.weight_kg} kg` : '—'}</p>
                    </div>
                </div>
            </CardContent>
        </Card>
    );
}

function LabSection({ findings }: { findings: DischargeData['laboratory_findings'] }) {
    if (!findings.length) return null;

    return (
        <Card className="print:shadow-none">
            <CardHeader>
                <CardTitle className="text-base">Laboratory Findings</CardTitle>
            </CardHeader>
            <CardContent className="space-y-4">
                {findings.map((r, i) => (
                    <div key={i} className="rounded border p-3 text-sm">
                        <div className="text-muted-foreground mb-1 flex justify-between text-xs">
                            <span>Report: {r.report_number || '—'}</span>
                            <span>Released: {fmt(r.released_at)}</span>
                        </div>
                        <pre className="whitespace-pre-wrap break-words text-xs">
                            {typeof r.results_snapshot === 'string'
                                ? r.results_snapshot
                                : JSON.stringify(r.results_snapshot, null, 2)}
                        </pre>
                    </div>
                ))}
            </CardContent>
        </Card>
    );
}

function RadiologySection({ findings }: { findings: DischargeData['radiology_findings'] }) {
    if (!findings.length) return null;

    return (
        <Card className="print:shadow-none">
            <CardHeader>
                <CardTitle className="text-base">Radiology Findings</CardTitle>
            </CardHeader>
            <CardContent className="space-y-4">
                {findings.map((r, i) => (
                    <div key={i} className="rounded border p-3 text-sm">
                        <div className="text-muted-foreground mb-1 text-xs">
                            Released: {fmt(r.released_at)}
                        </div>
                        {r.findings && (
                            <div className="mb-2">
                                <Label className="text-muted-foreground">Findings</Label>
                                <p className="whitespace-pre-wrap">{r.findings}</p>
                            </div>
                        )}
                        {r.impression && (
                            <div className="mb-2">
                                <Label className="text-muted-foreground">Impression</Label>
                                <p className="whitespace-pre-wrap">{r.impression}</p>
                            </div>
                        )}
                        {r.recommendations && (
                            <div>
                                <Label className="text-muted-foreground">Recommendations</Label>
                                <p className="whitespace-pre-wrap">{r.recommendations}</p>
                            </div>
                        )}
                    </div>
                ))}
            </CardContent>
        </Card>
    );
}

function FinancialSection({
    data,
    canViewBilling,
}: {
    data: DischargeData;
    canViewBilling: boolean;
}) {
    if (!canViewBilling) return null;

    const fin = data.financial_summary;
    const statusVariant: Record<string, 'default' | 'secondary' | 'destructive' | 'outline'> = {
        paid: 'default',
        partial: 'secondary',
        outstanding: 'destructive',
        unbilled: 'outline',
    };

    return (
        <Card className="print:shadow-none">
            <CardHeader>
                <CardTitle className="flex items-center justify-between text-base">
                    Financial Summary
                    <Badge variant={statusVariant[fin.status] ?? 'outline'} className="capitalize">
                        {fin.status}
                    </Badge>
                </CardTitle>
            </CardHeader>
            <CardContent>
                <div className="grid grid-cols-2 gap-x-8 gap-y-2 text-sm md:grid-cols-3">
                    <div>
                        <Label className="text-muted-foreground">Payer Type</Label>
                        <p className="capitalize">{fin.payer_type}</p>
                    </div>
                    <div>
                        <Label className="text-muted-foreground">Total Charges</Label>
                        <p className="font-medium">{currency(fin.total_charges, fin.currency)}</p>
                    </div>
                    {fin.payer_type === 'nhif' && (
                        <div>
                            <Label className="text-muted-foreground">NHIF Covered</Label>
                            <p>{currency(fin.nhif_covered, fin.currency)}</p>
                        </div>
                    )}
                    <div>
                        <Label className="text-muted-foreground">Patient Responsibility</Label>
                        <p>{currency(fin.patient_responsibility, fin.currency)}</p>
                    </div>
                    <div>
                        <Label className="text-muted-foreground">Amount Paid</Label>
                        <p>{currency(fin.cash_paid, fin.currency)}</p>
                    </div>
                    <div>
                        <Label className="text-muted-foreground">Outstanding</Label>
                        <p className={fin.outstanding > 0 ? 'text-destructive font-semibold' : ''}>
                            {currency(fin.outstanding, fin.currency)}
                        </p>
                    </div>
                </div>
                {data.insurance && (
                    <div className="mt-4 border-t pt-4">
                        <p className="text-muted-foreground mb-2 text-xs font-medium uppercase tracking-wide">
                            Insurance
                        </p>
                        <div className="grid grid-cols-2 gap-x-8 gap-y-2 text-sm md:grid-cols-3">
                            <div>
                                <Label className="text-muted-foreground">Provider</Label>
                                <p>{data.insurance.provider_name || '—'}</p>
                            </div>
                            <div>
                                <Label className="text-muted-foreground">Scheme</Label>
                                <p>{data.insurance.scheme_name || '—'}</p>
                            </div>
                            <div>
                                <Label className="text-muted-foreground">Membership No.</Label>
                                <p>{data.insurance.membership_number || '—'}</p>
                            </div>
                        </div>
                    </div>
                )}
            </CardContent>
        </Card>
    );
}

function NhifSection({
    data,
    canViewNhif,
}: {
    data: DischargeData;
    canViewNhif: boolean;
}) {
    if (!canViewNhif || !data.insurance?.nhif_authorization_no) return null;

    return (
        <Card className="print:shadow-none">
            <CardHeader>
                <CardTitle className="text-base">NHIF Details</CardTitle>
            </CardHeader>
            <CardContent>
                <div className="grid grid-cols-2 gap-x-8 gap-y-2 text-sm md:grid-cols-3">
                    <div>
                        <Label className="text-muted-foreground">Authorization No.</Label>
                        <p className="font-mono">{data.insurance.nhif_authorization_no}</p>
                    </div>
                    <div>
                        <Label className="text-muted-foreground">Submission Status</Label>
                        <p className="capitalize">{data.insurance.nhif_status?.replace(/_/g, ' ') || '—'}</p>
                    </div>
                </div>
            </CardContent>
        </Card>
    );
}

function VersionHistory({ history }: { history: DischargeDocument[] }) {
    if (history.length <= 1) return null;

    return (
        <Card className="print:hidden">
            <CardHeader>
                <CardTitle className="text-base">Version History</CardTitle>
            </CardHeader>
            <CardContent>
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Version</TableHead>
                            <TableHead>Status</TableHead>
                            <TableHead>Generated</TableHead>
                            <TableHead>By</TableHead>
                            <TableHead>Amendment Reason</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {history.map((doc) => (
                            <TableRow key={doc.id}>
                                <TableCell>v{doc.version}</TableCell>
                                <TableCell>
                                    <Badge
                                        variant={doc.status === 'active' ? 'default' : 'secondary'}
                                        className="capitalize"
                                    >
                                        {doc.status}
                                    </Badge>
                                </TableCell>
                                <TableCell className="text-sm">{fmt(doc.generated_at)}</TableCell>
                                <TableCell>{doc.generated_by?.UserName || '—'}</TableCell>
                                <TableCell className="text-muted-foreground max-w-xs truncate text-xs">
                                    {doc.amendment_reason || '—'}
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </CardContent>
        </Card>
    );
}

// ---------------------------------------------------------------------------
// Generate / Amend form
// ---------------------------------------------------------------------------

function GenerateForm({
    admissionId,
    isAmendment,
    onCancel,
}: {
    admissionId: number;
    isAmendment: boolean;
    onCancel: () => void;
}) {
    const { data, setData, post, processing, errors } = useForm({
        amendment_reason: '',
    });

    return (
        <Card className="print:hidden">
            <CardHeader>
                <CardTitle className="text-base">
                    {isAmendment ? 'Amend Discharge Document' : 'Generate Discharge Document'}
                </CardTitle>
            </CardHeader>
            <CardContent className="space-y-4">
                {isAmendment && (
                    <div className="space-y-1">
                        <Label htmlFor="amendment_reason">
                            Amendment Reason <span className="text-destructive">*</span>
                        </Label>
                        <Textarea
                            id="amendment_reason"
                            value={data.amendment_reason}
                            onChange={(e) => setData('amendment_reason', e.target.value)}
                            placeholder="Describe why this document is being amended…"
                            rows={3}
                        />
                        {errors.amendment_reason && (
                            <p className="text-destructive flex items-center gap-1 text-sm">
                                <AlertCircle className="h-3 w-3" />
                                {errors.amendment_reason}
                            </p>
                        )}
                    </div>
                )}
                <div className="flex gap-2">
                    <Button
                        onClick={() =>
                            post(route('inpatient.discharge-document.generate', admissionId))
                        }
                        disabled={processing}
                    >
                        {isAmendment ? 'Create New Version' : 'Generate Document'}
                    </Button>
                    <Button variant="outline" onClick={onCancel} disabled={processing}>
                        Cancel
                    </Button>
                </div>
            </CardContent>
        </Card>
    );
}

// ---------------------------------------------------------------------------
// Page
// ---------------------------------------------------------------------------

export default function DischargeDocumentPage({
    admission,
    document,
    history,
    canGenerate,
    canViewBilling,
    canViewNhif,
}: Props) {
    const [showGenerateForm, setShowGenerateForm] = useState(false);

    const patientName = admission.patient
        ? [admission.patient.first_name, admission.patient.last_name].filter(Boolean).join(' ')
        : `Admission ${admission.admission_number}`;

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Inpatient', href: route('inpatient.dashboard') },
        {
            title: patientName,
            href: route('inpatient.clinical-profile', admission.id),
        },
        { title: 'Discharge Document' },
    ];

    const data = document?.data_snapshot ?? null;
    const isAmendment = !!document;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Discharge Document — ${admission.admission_number}`} />

            {/* Print-only header */}
            <div className="hidden print:block print:mb-4">
                <h1 className="text-xl font-bold">Discharge Summary</h1>
                {document && (
                    <p className="text-sm text-gray-600">
                        {document.document_number} · v{document.version} · Generated:{' '}
                        {fmt(document.generated_at)}
                    </p>
                )}
            </div>

            <div className="space-y-6 px-4 py-6">
                <div className="print:hidden">
                    <PageHeader
                        title="Discharge Document"
                        description={
                            document
                                ? `${document.document_number} · v${document.version} · ${fmt(document.generated_at)}`
                                : 'No document generated yet'
                        }
                        actions={
                            <div className="flex gap-2">
                                {canGenerate && !showGenerateForm && (
                                    <Button
                                        variant={isAmendment ? 'outline' : 'default'}
                                        onClick={() => setShowGenerateForm(true)}
                                    >
                                        <ClipboardList className="mr-2 h-4 w-4" />
                                        {isAmendment ? 'Amend Document' : 'Generate Document'}
                                    </Button>
                                )}
                                {document && (
                                    <Button variant="outline" onClick={() => window.print()}>
                                        <Printer className="mr-2 h-4 w-4" />
                                        Print
                                    </Button>
                                )}
                            </div>
                        }
                    />
                </div>

                {showGenerateForm && (
                    <GenerateForm
                        admissionId={admission.id}
                        isAmendment={isAmendment}
                        onCancel={() => setShowGenerateForm(false)}
                    />
                )}

                {data ? (
                    <div className="space-y-4" id="discharge-document-content">
                        <PatientSection data={data} />
                        <DiagnosesSection diagnoses={data.diagnoses} />
                        <VitalsSection vitals={data.vitals_at_discharge} />
                        <MedicationsSection medications={data.discharge_medications} />
                        <LabSection findings={data.laboratory_findings} />
                        <RadiologySection findings={data.radiology_findings} />
                        <FinancialSection data={data} canViewBilling={canViewBilling} />
                        <NhifSection data={data} canViewNhif={canViewNhif} />
                    </div>
                ) : (
                    <Card>
                        <CardContent className="flex flex-col items-center gap-3 py-12 text-center">
                            <FileText className="text-muted-foreground h-10 w-10" />
                            <p className="text-muted-foreground text-sm">
                                No discharge document has been generated for this admission yet.
                            </p>
                            {canGenerate && !showGenerateForm && (
                                <Button onClick={() => setShowGenerateForm(true)}>
                                    Generate Document
                                </Button>
                            )}
                        </CardContent>
                    </Card>
                )}

                <VersionHistory history={history} />
            </div>

            {/* Print CSS */}
            <style>{`
                @media print {
                    body * { visibility: hidden; }
                    #discharge-document-content,
                    #discharge-document-content * { visibility: visible; }
                    #discharge-document-content { position: absolute; inset: 0; }
                    .print\\:hidden { display: none !important; }
                    .print\\:shadow-none { box-shadow: none !important; border: 1px solid #e5e7eb !important; }
                }
            `}</style>
        </AppLayout>
    );
}
