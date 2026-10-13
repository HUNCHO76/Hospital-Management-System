import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { SignaturePad } from '@/components/nhif/signature-pad';
import { captureFingerprint, MorFinError } from '@/lib/morfin-auth';
import { Link, router, usePage } from '@inertiajs/react';
import axios from 'axios';
import { AlertCircle, CheckCircle2, FileCheck, Fingerprint, Loader2, RotateCcw, ShieldAlert, ShieldCheck, XCircle } from 'lucide-react';
import { useEffect, useState } from 'react';

export const FINGER_CODES = ['R1', 'R2', 'R3', 'R4', 'RTH', 'L1', 'L2', 'L3', 'L4', 'LTH'] as const;

export const FINGER_LABELS: Record<(typeof FINGER_CODES)[number], string> = {
    R1: 'R1 — Right index',
    R2: 'R2 — Right middle',
    R3: 'R3 — Right ring',
    R4: 'R4 — Right little',
    RTH: 'RTH — Right thumb',
    L1: 'L1 — Left index',
    L2: 'L2 — Left middle',
    L3: 'L3 — Left ring',
    L4: 'L4 — Left little',
    LTH: 'LTH — Left thumb',
};

export const SIGNED_BY_OPTIONS = [
    { value: 'PATIENT', label: 'PATIENT (Self)' },
    { value: 'SPOUSE', label: 'SPOUSE' },
    { value: 'PARENT', label: 'PARENT' },
    { value: 'CHILD', label: 'CHILD' },
    { value: 'BROTHER', label: 'BROTHER' },
    { value: 'SISTER', label: 'SISTER' },
    { value: 'RELATIVE', label: 'RELATIVE' },
    { value: 'FRIEND', label: 'FRIEND' },
    { value: 'OTHER', label: 'OTHER' },
] as const;

interface ChecklistItem {
    key: string;
    label: string;
    passed: boolean;
    detail: string;
}

interface ClaimItem {
    nhif_item_code: string;
    nhif_item_name: string;
    item_type_id: number;
    unit_price: number;
    quantity: number;
    amount_claimed: number;
    approval_ref_no: string | null;
    source_type: string | null;
    source_id: number | null;
    other_details: string | null;
}

interface ClaimReadinessData {
    is_ready: boolean;
    errors: string[];
    checklist: ChecklistItem[];
    items: ClaimItem[];
    total_amount: number;
    primary_diagnosis: { code: string | null; description: string | null } | null;
    attending_practitioner: { id: number | null; name: string | null; practitioner_no: string | null } | null;
    existing_claim: { id: number; claim_number: string; status: string; folio_no?: string | number; nhif_submission_no?: string | null } | null;
    patient: { id: number; name: string; card_no: string | null; file_no: string | null };
    facility_code: string | null;
}

interface NhifClaimFinalizeDialogProps {
    clinicalEncounterId?: number;
    admissionId?: number;
    patientName?: string;
    patientCardNo?: string;
    trigger?: React.ReactNode;
    onFinalized?: (claim: any) => void;
}

export function NhifClaimFinalizeDialog({
    clinicalEncounterId,
    admissionId,
    patientName,
    patientCardNo,
    trigger,
    onFinalized,
}: NhifClaimFinalizeDialogProps) {
    const { auth } = usePage().props as any;
    const [open, setOpen] = useState(false);
    const [loading, setLoading] = useState(false);
    const [submitting, setSubmitting] = useState(false);
    const [readiness, setReadiness] = useState<ClaimReadinessData | null>(null);

    // Signature state
    const [signatureMethod, setSignatureMethod] = useState<'FINGERPRINT' | 'SIGNATURE' | 'FACIAL' | 'Biometric' | 'Manual'>('FINGERPRINT');
    const [signedBy, setSignedBy] = useState<string>('PATIENT');
    const [signedByName, setSignedByName] = useState(patientName || '');
    const [remarks, setRemarks] = useState('');

    // Biometric / Mantra state
    const [fpCode, setFpCode] = useState<string>('R1');
    const [imageData, setImageData] = useState<string | null>(null);
    const [previewSrc, setPreviewSrc] = useState<string | null>(null);
    const [capturing, setCapturing] = useState(false);
    const [captureError, setCaptureError] = useState<string | null>(null);
    const [captureInfo, setCaptureInfo] = useState<string | null>(null);

    // Manual signature state (raw base64 JPEG from the Topaz / on-screen pad)
    const [manualSignature, setManualSignature] = useState<string | null>(null);

    const [errorResult, setErrorResult] = useState<{ step: string; error: string } | null>(null);
    const [successResult, setSuccessResult] = useState<any | null>(null);

    const loadPreview = async () => {
        setLoading(true);
        setErrorResult(null);
        try {
            const res = await axios.post(route('nhif.claims.preview'), {
                clinical_encounter_id: clinicalEncounterId,
                admission_id: admissionId,
            });
            setReadiness(res.data);
            if (res.data?.patient?.name && !signedByName) {
                setSignedByName(res.data.patient.name);
            }
        } catch (err: any) {
            setErrorResult({
                step: 'preview',
                error: err.response?.data?.message || 'Failed to check NHIF claim readiness.',
            });
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        if (open) {
            loadPreview();
        } else {
            setErrorResult(null);
            setSuccessResult(null);
            setImageData(null);
            setManualSignature(null);
            setPreviewSrc(null);
            setCaptureError(null);
            setCaptureInfo(null);
            setCapturing(false);
        }
    }, [open, clinicalEncounterId, admissionId]);

    // Handle Mantra / MorFin fingerprint capture
    const handleCaptureFingerprint = async () => {
        setCapturing(true);
        setCaptureError(null);
        setCaptureInfo(null);

        try {
            const result = await captureFingerprint();
            setImageData(result.base64);
            setPreviewSrc(result.previewSrc);
            setCaptureInfo(`Captured successfully (${result.format}${result.quality !== null ? `, quality ${result.quality}` : ''})`);
        } catch (error) {
            setImageData(null);
            setPreviewSrc(null);
            setCaptureError(
                error instanceof MorFinError
                    ? error.message
                    : 'Fingerprint capture failed. Ensure Mantra / MorFin device service is running.'
            );
        } finally {
            setCapturing(false);
        }
    };

    const handleClearCapture = () => {
        setImageData(null);
        setPreviewSrc(null);
        setCaptureError(null);
        setCaptureInfo(null);
    };

    const isBiometricMethod = signatureMethod === 'FINGERPRINT' || signatureMethod === 'Biometric';
    const isManualMethod = signatureMethod === 'SIGNATURE' || signatureMethod === 'Manual';

    const handleFinalize = async () => {
        if (!readiness?.is_ready) return;

        // Biometric validation
        if (isBiometricMethod && !imageData) {
            setErrorResult({
                step: 'biometric',
                error: 'Please capture the patient fingerprint using the connected Mantra scanner before finalizing.',
            });
            return;
        }

        // Manual signature validation
        if (isManualMethod && !manualSignature) {
            setErrorResult({
                step: 'signature',
                error: "Please capture the signatory's signature before finalizing.",
            });
            return;
        }

        setSubmitting(true);
        setErrorResult(null);

        const methodCode = isBiometricMethod ? 'FINGERPRINT' : signatureMethod === 'Manual' ? 'SIGNATURE' : signatureMethod;
        const capturedSignature = isBiometricMethod ? imageData : isManualMethod ? manualSignature : null;

        try {
            const res = await axios.post(route('nhif.claims.finalize-from-context'), {
                clinical_encounter_id: clinicalEncounterId,
                admission_id: admissionId,
                signature_method: methodCode,
                signature_data: capturedSignature,
                imageData: capturedSignature,
                fp_code: isBiometricMethod ? fpCode : null,
                fpCode: isBiometricMethod ? fpCode : null,
                signed_by: signedBy,
                signed_by_name: signedByName || readiness?.patient?.name || 'Patient',
                remarks: remarks || null,
            });

            if (res.data?.success) {
                setSuccessResult(res.data);
                if (onFinalized) {
                    onFinalized(res.data.claim);
                }
            } else {
                setErrorResult({
                    step: res.data?.step || 'unknown',
                    error: res.data?.error || 'Claim finalization failed.',
                });
            }
        } catch (err: any) {
            const data = err.response?.data;
            let errorMessage = data?.error || data?.message || 'Failed to finalize NHIF claim.';
            if (data?.errors && typeof data.errors === 'object') {
                const details = Object.values(data.errors).flat().join(' ');
                if (details && !errorMessage.includes(details)) {
                    errorMessage = `${errorMessage}: ${details}`;
                }
            }
            setErrorResult({
                step: data?.step || 'server',
                error: errorMessage,
            });
        } finally {
            setSubmitting(false);
        }
    };

    const isSubmittedAlready =
        readiness?.existing_claim &&
        ['submitted', 'confirmed', 'finalized', 'accepted', 'reconciled'].includes(readiness.existing_claim.status);

    const isBiometricReady = !isBiometricMethod || Boolean(imageData && fpCode);
    const isManualSignatureReady = !isManualMethod || Boolean(manualSignature);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                {trigger || (
                    <Button variant="default" className="gap-2 bg-emerald-600 hover:bg-emerald-700 text-white">
                        <FileCheck className="h-4 w-4" />
                        Finalize NHIF Claim
                    </Button>
                )}
            </DialogTrigger>
            <DialogContent className="max-w-3xl max-h-[90vh] overflow-y-auto">
                <DialogHeader>
                    <DialogTitle className="flex items-center gap-2">
                        <ShieldCheck className="h-5 w-5 text-emerald-600" />
                        NHIF Patient Claim Finalization
                    </DialogTitle>
                    <DialogDescription>
                        Validates issued hospital services, calculates authoritative claim totals, signs, submits the folio to NHIF OCS, and requests bill confirmation.
                    </DialogDescription>
                </DialogHeader>

                {loading ? (
                    <div className="flex flex-col items-center justify-center py-12 gap-3 text-muted-foreground">
                        <Loader2 className="h-8 w-8 animate-spin text-primary" />
                        <span>Verifying NHIF claim readiness and eligible issued services...</span>
                    </div>
                ) : (
                    <div className="space-y-6 py-2">
                        {/* Patient & Context Banner */}
                        <div className="rounded-lg border bg-muted/30 p-4 text-sm grid grid-cols-2 sm:grid-cols-4 gap-3">
                            <div>
                                <span className="text-xs text-muted-foreground block">Patient</span>
                                <span className="font-semibold">{readiness?.patient.name || patientName || '—'}</span>
                            </div>
                            <div>
                                <span className="text-xs text-muted-foreground block">NHIF Card No</span>
                                <span className="font-mono">{readiness?.patient.card_no || patientCardNo || '—'}</span>
                            </div>
                            <div>
                                <span className="text-xs text-muted-foreground block">Facility Code</span>
                                <span className="font-mono">{readiness?.facility_code || '—'}</span>
                            </div>
                            <div>
                                <span className="text-xs text-muted-foreground block">Attending Practitioner</span>
                                <span className="truncate block font-medium">
                                    {readiness?.attending_practitioner?.name
                                        ? `${readiness.attending_practitioner.name} (${readiness.attending_practitioner.practitioner_no || '—'})`
                                        : '—'}
                                </span>
                            </div>
                        </div>

                        {/* Existing Claim Notification */}
                        {readiness?.existing_claim && (
                            <Alert className={isSubmittedAlready ? 'border-amber-300 bg-amber-50 dark:bg-amber-950/20' : 'border-blue-300 bg-blue-50 dark:bg-blue-950/20'}>
                                <AlertCircle className="h-4 w-4 text-amber-600" />
                                <AlertTitle>Existing Folio Record Found</AlertTitle>
                                <AlertDescription className="text-xs space-y-1">
                                    <div>
                                        Claim #{readiness.existing_claim.claim_number} is currently in status{' '}
                                        <Badge variant="outline" className="font-mono uppercase font-bold">
                                            {readiness.existing_claim.status}
                                        </Badge>
                                        . Folio No: <strong>{readiness.existing_claim.folio_no || 'Pending'}</strong>
                                    </div>
                                    {isSubmittedAlready && (
                                        <div className="text-amber-800 dark:text-amber-300">
                                            This claim has already been submitted to NHIF. You can view its full details and reconciliation status in the claims registry.
                                        </div>
                                    )}
                                </AlertDescription>
                            </Alert>
                        )}

                        {/* Success Workflow Outcome */}
                        {successResult && (
                            <Alert className="border-emerald-300 bg-emerald-50 dark:bg-emerald-950/30 text-emerald-900 dark:text-emerald-100">
                                <CheckCircle2 className="h-5 w-5 text-emerald-600 dark:text-emerald-400 mt-0.5" />
                                <AlertTitle className="text-base font-bold">Claim Finalized & Submitted Successfully</AlertTitle>
                                <AlertDescription className="mt-2 space-y-2 text-xs">
                                    <p>
                                        The folio has been authoritatively computed, signed via SignFolio, submitted to NHIF OCS, and bill confirmation requested.
                                    </p>
                                    <div className="grid grid-cols-2 gap-2 bg-emerald-100/50 dark:bg-emerald-900/40 p-3 rounded font-mono">
                                        <div>Claim Number: <strong>{successResult.claim?.claim_number}</strong></div>
                                        <div>Folio Number: <strong>{successResult.claim?.folio_no}</strong></div>
                                        <div>Submission No: <strong>{successResult.claim?.nhif_submission_no || 'Assigned by NHIF'}</strong></div>
                                        <div>Total Claimed: <strong>TZS {Number(successResult.claim?.total_amount || 0).toLocaleString()}</strong></div>
                                        <div>Status: <Badge className="bg-emerald-600 text-white font-mono uppercase">{successResult.claim?.status}</Badge></div>
                                    </div>
                                    <div className="pt-2 flex gap-2">
                                        <Link href={route('nhif.claims.show', successResult.claim?.id)} className="text-xs underline font-semibold text-emerald-700 dark:text-emerald-300">
                                            View Full Claim Record →
                                        </Link>
                                    </div>
                                </AlertDescription>
                            </Alert>
                        )}

                        {/* Error Alert */}
                        {errorResult && (
                            <Alert variant="destructive">
                                <ShieldAlert className="h-4 w-4" />
                                <AlertTitle>Claim Finalization Failed (Step: {errorResult.step})</AlertTitle>
                                <AlertDescription className="text-xs mt-1 space-y-2">
                                    <p>{errorResult.error}</p>
                                    {(errorResult.error?.toLowerCase().includes('authorization number already exists') ||
                                      errorResult.error?.toLowerCase().includes('similar folio no. already exists')) && (
                                        <div className="bg-destructive/20 border border-destructive/40 p-2.5 rounded text-destructive-foreground mt-2 space-y-1">
                                            <p className="font-semibold">📌 NHIF Statutory Rule Notice:</p>
                                            <p>
                                                NHIF permanently locks an Authorization Number to the first Folio Number under which it was submitted. Once registered, NHIF rejects submitting this Authorization Number under any different Folio Number.
                                            </p>
                                            <p className="font-semibold text-amber-500 dark:text-amber-300">Recommended Action:</p>
                                            <ul className="list-disc list-inside space-y-0.5">
                                                <li>Re-verify the patient&apos;s insurance card to generate a fresh 24-hour Authorization Number.</li>
                                                <li>Or, if the original folio was cancelled by mistake, reopen the original folio instead of creating a new one.</li>
                                            </ul>
                                        </div>
                                    )}
                                    {(errorResult.error?.toLowerCase().includes('inpatient visit') ||
                                      errorResult.error?.toLowerCase().includes('visittypeid 2') ||
                                      errorResult.error?.toLowerCase().includes('same-day visits cannot be claimed')) && (
                                        <div className="bg-destructive/20 border border-destructive/40 p-2.5 rounded text-destructive-foreground mt-2 space-y-1">
                                            <p className="font-semibold">🏥 NHIF Inpatient Rule Mismatch:</p>
                                            <p>
                                                This authorization was issued as an Inpatient visit (VisitTypeID 2), which requires an admission date and a later discharge date (at least 1 overnight stay). Same-day visits cannot be claimed under an Inpatient authorization.
                                            </p>
                                            <p className="font-semibold text-amber-500 dark:text-amber-300">How to Fix:</p>
                                            <ul className="list-disc list-inside space-y-0.5">
                                                <li>If the patient was not admitted (outpatient / walk-in): Cancel this draft claim, re-verify the card with Visit Type <strong>&quot;Normal Visit&quot; (1)</strong> to issue a valid OPD authorization, and finalize again.</li>
                                                <li>If the patient was actually admitted: Submit this claim only after the patient is discharged on a subsequent date.</li>
                                            </ul>
                                        </div>
                                    )}
                                </AlertDescription>
                            </Alert>
                        )}

                        {/* Readiness Checklist */}
                        {!successResult && (
                            <div className="space-y-2">
                                <h3 className="text-sm font-semibold text-foreground flex items-center justify-between">
                                    <span>Claim Readiness Verification</span>
                                    {readiness?.is_ready ? (
                                        <Badge className="bg-emerald-600 text-white">READY FOR SUBMISSION</Badge>
                                    ) : (
                                        <Badge variant="destructive">NOT READY</Badge>
                                    )}
                                </h3>
                                <div className="grid grid-cols-1 sm:grid-cols-2 gap-2 text-xs">
                                    {readiness?.checklist.map((item) => (
                                        <div
                                            key={item.key}
                                            className={`flex items-start gap-2 p-2 rounded-md border ${
                                                item.passed ? 'bg-emerald-50/50 border-emerald-200 dark:bg-emerald-950/20 dark:border-emerald-900' : 'bg-rose-50/50 border-rose-200 dark:bg-rose-950/20 dark:border-rose-900'
                                            }`}
                                        >
                                            {item.passed ? (
                                                <CheckCircle2 className="h-4 w-4 text-emerald-600 shrink-0 mt-0.5" />
                                            ) : (
                                                <XCircle className="h-4 w-4 text-rose-600 shrink-0 mt-0.5" />
                                            )}
                                            <div>
                                                <div className="font-medium text-foreground">{item.label}</div>
                                                <div className="text-muted-foreground">{item.detail}</div>
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            </div>
                        )}

                        {/* Claim Items Preview */}
                        {!successResult && (
                            <div className="space-y-2">
                                <div className="flex items-center justify-between">
                                    <h3 className="text-sm font-semibold text-foreground">
                                        Eligible Issued Services ({readiness?.items.length || 0})
                                    </h3>
                                    <div className="text-xs text-muted-foreground">
                                        Primary Diagnosis:{' '}
                                        <span className="font-semibold text-foreground">
                                            {readiness?.primary_diagnosis?.code
                                                ? `${readiness.primary_diagnosis.code} — ${readiness.primary_diagnosis.description || ''}`
                                                : 'None'}
                                        </span>
                                    </div>
                                </div>

                                <div className="rounded-md border overflow-hidden">
                                    <Table>
                                        <TableHeader className="bg-muted/40">
                                            <TableRow>
                                                <TableHead className="w-[100px]">Item Code</TableHead>
                                                <TableHead>Service / Medication Name</TableHead>
                                                <TableHead className="text-right w-[60px]">Qty</TableHead>
                                                <TableHead className="text-right w-[110px]">Unit Price</TableHead>
                                                <TableHead className="text-right w-[110px]">Amount (TZS)</TableHead>
                                                <TableHead className="w-[120px]">Approval Ref</TableHead>
                                            </TableRow>
                                        </TableHeader>
                                        <TableBody>
                                            {readiness?.items.length === 0 ? (
                                                <TableRow>
                                                    <TableCell colSpan={6} className="text-center py-6 text-muted-foreground">
                                                        No issued claimable services found.
                                                    </TableCell>
                                                </TableRow>
                                            ) : (
                                                readiness?.items.map((item, idx) => (
                                                    <TableRow key={idx}>
                                                        <TableCell className="font-mono text-xs">{item.nhif_item_code}</TableCell>
                                                        <TableCell className="font-medium text-xs">{item.nhif_item_name}</TableCell>
                                                        <TableCell className="text-right text-xs">{item.quantity}</TableCell>
                                                        <TableCell className="text-right font-mono text-xs">
                                                            {Number(item.unit_price).toLocaleString()}
                                                        </TableCell>
                                                        <TableCell className="text-right font-mono text-xs font-semibold">
                                                            {Number(item.amount_claimed).toLocaleString()}
                                                        </TableCell>
                                                        <TableCell className="text-xs text-muted-foreground font-mono truncate max-w-[120px]">
                                                            {item.approval_ref_no || '—'}
                                                        </TableCell>
                                                    </TableRow>
                                                ))
                                            )}
                                            <TableRow className="bg-muted/30 font-bold border-t">
                                                <TableCell colSpan={4} className="text-right">
                                                    TOTAL CLAIM AMOUNT:
                                                </TableCell>
                                                <TableCell className="text-right font-mono text-sm text-emerald-600 dark:text-emerald-400">
                                                    TZS {Number(readiness?.total_amount || 0).toLocaleString()}
                                                </TableCell>
                                                <TableCell />
                                            </TableRow>
                                        </TableBody>
                                    </Table>
                                </div>
                            </div>
                        )}

                        {/* SignFolio Parameters & Biometric Capture */}
                        {!successResult && !isSubmittedAlready && readiness?.is_ready && (
                            <div className="rounded-lg border p-4 space-y-4 bg-muted/10">
                                <div className="flex items-center justify-between">
                                    <h4 className="text-xs font-bold uppercase tracking-wider text-muted-foreground">
                                        SignFolio & Authorized Signatory
                                    </h4>
                                    {isBiometricMethod && (
                                        <Badge variant="outline" className="text-xs gap-1 border-emerald-500 text-emerald-700 dark:text-emerald-300">
                                            <Fingerprint className="h-3.5 w-3.5" />
                                            Mantra Device Ready
                                        </Badge>
                                    )}
                                </div>

                                <div className="grid grid-cols-1 sm:grid-cols-3 gap-4 text-xs">
                                    <div className="space-y-1">
                                        <Label htmlFor="sig-method">Signature Method</Label>
                                        <select
                                            id="sig-method"
                                            className="w-full rounded-md border bg-background px-3 py-1.5 text-xs shadow-sm"
                                            value={signatureMethod}
                                            onChange={(e) => {
                                                const next = e.target.value as any;
                                                setSignatureMethod(next);
                                                if (next !== 'FINGERPRINT' && next !== 'Biometric') {
                                                    handleClearCapture();
                                                }
                                                if (next !== 'SIGNATURE' && next !== 'Manual') {
                                                    setManualSignature(null);
                                                }
                                            }}
                                        >
                                            <option value="FINGERPRINT">Biometric / Fingerprint (Mantra)</option>
                                            <option value="SIGNATURE">Manual Signature</option>
                                            <option value="FACIAL">Facial Verification</option>
                                        </select>
                                    </div>

                                    <div className="space-y-1">
                                        <Label htmlFor="sig-by">Signed By (Relationship)</Label>
                                        <Select value={signedBy} onValueChange={setSignedBy}>
                                            <SelectTrigger id="sig-by" className="h-8 text-xs">
                                                <SelectValue placeholder="Select signatory relationship" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {SIGNED_BY_OPTIONS.map((opt) => (
                                                    <SelectItem key={opt.value} value={opt.value} className="text-xs">
                                                        {opt.label}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </div>

                                    <div className="space-y-1">
                                        <Label htmlFor="sig-name">Signatory Full Name</Label>
                                        <Input
                                            id="sig-name"
                                            className="h-8 text-xs"
                                            value={signedByName}
                                            placeholder="Patient or signatory name"
                                            onChange={(e) => setSignedByName(e.target.value)}
                                        />
                                    </div>

                                    {/* Dedicated Biometric (Fingerprint / Mantra) Capture Section */}
                                    {isBiometricMethod && (
                                        <div className="sm:col-span-3 border rounded-lg p-4 bg-background shadow-xs space-y-3">
                                            <div className="flex items-center justify-between">
                                                <div className="flex items-center gap-2">
                                                    <Fingerprint className="h-4 w-4 text-emerald-600" />
                                                    <span className="font-semibold text-xs text-foreground">
                                                        Mantra Fingerprint Device Scanner
                                                    </span>
                                                </div>
                                                <span className="text-[11px] text-muted-foreground">
                                                    Captures Base64 WSQ/BMP data for NHIF SignFolio
                                                </span>
                                            </div>

                                            {captureError && (
                                                <Alert variant="destructive" className="py-2 text-xs">
                                                    <AlertCircle className="h-4 w-4" />
                                                    <AlertDescription>{captureError}</AlertDescription>
                                                </Alert>
                                            )}

                                            <div className="grid grid-cols-1 sm:grid-cols-3 gap-4 items-center">
                                                <div className="space-y-1 sm:col-span-1">
                                                    <Label htmlFor="fp-finger-select" className="text-xs">Finger Scanned (FP Code)</Label>
                                                    <Select
                                                        value={fpCode}
                                                        onValueChange={(val) => setFpCode(val)}
                                                        disabled={capturing || submitting}
                                                    >
                                                        <SelectTrigger id="fp-finger-select" className="h-8 text-xs">
                                                            <SelectValue placeholder="Select finger" />
                                                        </SelectTrigger>
                                                        <SelectContent>
                                                            {FINGER_CODES.map((code) => (
                                                                <SelectItem key={code} value={code} className="text-xs">
                                                                    {FINGER_LABELS[code]}
                                                                </SelectItem>
                                                            ))}
                                                        </SelectContent>
                                                    </Select>
                                                </div>

                                                <div className="sm:col-span-2 flex items-center gap-4">
                                                    {/* Scanner Preview thumbnail */}
                                                    <div className="flex size-20 shrink-0 items-center justify-center overflow-hidden rounded-md border bg-muted/30">
                                                        {previewSrc ? (
                                                            <img
                                                                src={previewSrc}
                                                                alt="Captured fingerprint preview"
                                                                className="size-full object-contain p-1"
                                                            />
                                                        ) : (
                                                            <Fingerprint className="h-8 w-8 text-muted-foreground/40" />
                                                        )}
                                                    </div>

                                                    <div className="space-y-1.5 flex-1">
                                                        <div className="flex items-center gap-2">
                                                            <Button
                                                                type="button"
                                                                variant={imageData ? 'outline' : 'default'}
                                                                size="sm"
                                                                className={imageData ? 'h-8 text-xs' : 'h-8 text-xs bg-emerald-600 hover:bg-emerald-700 text-white'}
                                                                onClick={handleCaptureFingerprint}
                                                                disabled={capturing || submitting}
                                                            >
                                                                {capturing ? (
                                                                    <>
                                                                        <Loader2 className="mr-1.5 h-3.5 w-3.5 animate-spin" />
                                                                        Place finger on scanner...
                                                                    </>
                                                                ) : imageData ? (
                                                                    <>
                                                                        <RotateCcw className="mr-1.5 h-3.5 w-3.5" />
                                                                        Recapture Fingerprint
                                                                    </>
                                                                ) : (
                                                                    <>
                                                                        <Fingerprint className="mr-1.5 h-3.5 w-3.5" />
                                                                        Capture Fingerprint
                                                                    </>
                                                                )}
                                                            </Button>

                                                            {imageData && (
                                                                <Button
                                                                    type="button"
                                                                    variant="ghost"
                                                                    size="sm"
                                                                    className="h-8 text-xs text-muted-foreground hover:text-destructive"
                                                                    onClick={handleClearCapture}
                                                                    disabled={capturing || submitting}
                                                                >
                                                                    Clear
                                                                </Button>
                                                            )}
                                                        </div>

                                                        {captureInfo && (
                                                            <div className="flex items-center gap-1.5 text-xs text-emerald-600 dark:text-emerald-400 font-medium">
                                                                <CheckCircle2 className="h-3.5 w-3.5 shrink-0" />
                                                                <span>{captureInfo}</span>
                                                            </div>
                                                        )}
                                                        {!imageData && !captureError && !capturing && (
                                                            <p className="text-[11px] text-muted-foreground">
                                                                Click Capture to connect with the local Mantra scanner client.
                                                            </p>
                                                        )}
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    )}

                                    {/* Manual signature: Topaz pad with on-screen fallback */}
                                    {isManualMethod && (
                                        <div className="sm:col-span-3 border rounded-lg p-4 bg-background shadow-xs">
                                            <SignaturePad value={manualSignature} onChange={setManualSignature} disabled={submitting} />
                                        </div>
                                    )}

                                    <div className="space-y-1 sm:col-span-3">
                                        <Label htmlFor="sig-remarks">Remarks (Optional)</Label>
                                        <Input
                                            id="sig-remarks"
                                            className="h-8 text-xs"
                                            placeholder="Optional remarks for this claim submission..."
                                            value={remarks}
                                            onChange={(e) => setRemarks(e.target.value)}
                                        />
                                    </div>
                                </div>
                            </div>
                        )}
                    </div>
                )}

                <DialogFooter className="gap-2">
                    <Button variant="outline" onClick={() => setOpen(false)}>
                        {successResult ? 'Close' : 'Cancel'}
                    </Button>
                    {!successResult && !isSubmittedAlready && (
                        <Button
                            variant="default"
                            className="bg-emerald-600 hover:bg-emerald-700 text-white"
                            disabled={!readiness?.is_ready || !isBiometricReady || !isManualSignatureReady || submitting || loading}
                            onClick={handleFinalize}
                        >
                            {submitting ? (
                                <>
                                    <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                                    Signing & Submitting Folio...
                                </>
                            ) : (
                                <>
                                    <ShieldCheck className="mr-2 h-4 w-4" />
                                    Sign & Submit NHIF Claim (TZS {Number(readiness?.total_amount || 0).toLocaleString()})
                                </>
                            )}
                        </Button>
                    )}
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
