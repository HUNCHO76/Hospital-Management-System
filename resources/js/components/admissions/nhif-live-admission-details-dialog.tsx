import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { router } from '@inertiajs/react';
import axios from 'axios';
import {
    AlertTriangle,
    Check,
    CheckCircle2,
    Copy,
    ExternalLink,
    Loader2,
    RefreshCw,
    ShieldCheck,
    User,
} from 'lucide-react';
import { type ReactNode, useState } from 'react';

interface NhifLiveAdmissionDetailsDialogProps {
    authorizationNo: string;
    admissionId?: number;
    trigger?: ReactNode;
}

export function NhifLiveAdmissionDetailsDialog({
    authorizationNo,
    admissionId,
    trigger,
}: NhifLiveAdmissionDetailsDialogProps) {
    const [open, setOpen] = useState(false);
    const [loading, setLoading] = useState(false);
    const [syncing, setSyncing] = useState(false);
    const [details, setDetails] = useState<Record<string, any> | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [copied, setCopied] = useState(false);
    const [synced, setSynced] = useState(false);

    const fetchDetails = async () => {
        if (!authorizationNo) return;
        setLoading(true);
        setError(null);
        setSynced(false);

        try {
            const res = await axios.get(route('nhif.admissions.lookup'), {
                params: { authorization_no: authorizationNo },
            });
            setDetails(res.data);
        } catch (err: any) {
            setError(err.response?.data?.error || 'Failed to fetch admission details from NHIF.');
        } finally {
            setLoading(false);
        }
    };

    const handleOpenChange = (isOpen: boolean) => {
        setOpen(isOpen);
        if (isOpen && !details) {
            fetchDetails();
        }
    };

    const handleCopy = (text: string) => {
        navigator.clipboard.writeText(text);
        setCopied(true);
        setTimeout(() => setCopied(false), 2000);
    };

    const handleSync = async () => {
        if (!admissionId || !authorizationNo) return;
        setSyncing(true);
        try {
            await axios.post(route('nhif.admissions.sync'), {
                admission_id: admissionId,
                authorization_no: authorizationNo,
            });
            setSynced(true);
            router.reload({ preserveScroll: true });
        } catch (err: any) {
            setError(err.response?.data?.error || 'Failed to sync with local admission record.');
        } finally {
            setSyncing(false);
        }
    };

    const patientName =
        details?.FullName ||
        [details?.FirstName, details?.LastName].filter(Boolean).join(' ') ||
        '—';

    return (
        <Dialog open={open} onOpenChange={handleOpenChange}>
            <DialogTrigger asChild>
                {trigger || (
                    <Button size="sm" variant="outline" className="gap-1.5">
                        <ShieldCheck className="h-4 w-4 text-primary" />
                        View NHIF Live Details
                    </Button>
                )}
            </DialogTrigger>
            <DialogContent className="max-w-2xl max-h-[85vh] overflow-y-auto">
                <DialogHeader>
                    <DialogTitle className="flex items-center justify-between gap-2 pr-4">
                        <span className="flex items-center gap-2">
                            <ShieldCheck className="h-5 w-5 text-primary" />
                            Live NHIF Admission Details
                        </span>
                        {details?.AdmissionNo && (
                            <Badge variant="default" className="font-mono text-sm px-2.5 py-0.5">
                                #{details.AdmissionNo}
                            </Badge>
                        )}
                    </DialogTitle>
                    <DialogDescription>
                        Direct response from NHIF ServiceHub (
                        <span className="font-mono text-xs">/api/Admissions/GetAdmissionDetailsByAuthorizationNo</span>)
                    </DialogDescription>
                </DialogHeader>

                <div className="py-2 space-y-4">
                    {loading && (
                        <div className="flex flex-col items-center justify-center py-12 gap-2 text-muted-foreground text-sm">
                            <Loader2 className="h-8 w-8 animate-spin text-primary" />
                            Fetching live details for Authorization #{authorizationNo}...
                        </div>
                    )}

                    {error && (
                        <Alert variant="destructive">
                            <AlertTriangle className="h-4 w-4" />
                            <AlertDescription className="flex items-center justify-between text-xs">
                                <span>{error}</span>
                                <Button size="sm" variant="outline" onClick={fetchDetails} className="h-7 text-xs">
                                    Retry
                                </Button>
                            </AlertDescription>
                        </Alert>
                    )}

                    {synced && (
                        <Alert className="border-green-500/50 bg-green-500/10 text-green-900 dark:text-green-200">
                            <CheckCircle2 className="h-4 w-4 text-green-600" />
                            <AlertDescription className="text-xs">
                                Local admission record successfully synchronized with live NHIF data!
                            </AlertDescription>
                        </Alert>
                    )}

                    {!loading && details && (
                        <div className="space-y-4">
                            {/* Key Highlights Card */}
                            <div className="rounded-lg border bg-card p-4 shadow-sm grid grid-cols-2 sm:grid-cols-3 gap-4 text-sm">
                                <div>
                                    <span className="text-xs text-muted-foreground block">Admission Number</span>
                                    <div className="flex items-center gap-1.5 mt-0.5">
                                        <span className="font-mono font-bold text-base text-foreground">
                                            {details.AdmissionNo || '—'}
                                        </span>
                                        {details.AdmissionNo && (
                                            <button
                                                type="button"
                                                onClick={() => handleCopy(details.AdmissionNo)}
                                                className="text-muted-foreground hover:text-foreground p-0.5 rounded"
                                                title="Copy Admission Number"
                                            >
                                                {copied ? <Check className="h-3.5 w-3.5 text-green-600" /> : <Copy className="h-3.5 w-3.5" />}
                                            </button>
                                        )}
                                    </div>
                                </div>
                                <div>
                                    <span className="text-xs text-muted-foreground block">Authorization Number</span>
                                    <span className="font-mono font-medium text-foreground block mt-0.5">
                                        {details.AuthorizationNo || authorizationNo}
                                    </span>
                                </div>
                                <div>
                                    <span className="text-xs text-muted-foreground block">Admission Status</span>
                                    <Badge variant={details.IsDischarged ? 'outline' : 'default'} className="mt-0.5">
                                        {details.IsDischarged ? 'Discharged' : 'Active / Admitted'}
                                    </Badge>
                                </div>
                            </div>

                            {/* Patient & Admission Information */}
                            <div className="rounded-lg border bg-muted/20 p-4 text-xs space-y-3">
                                <h4 className="font-semibold text-foreground text-sm flex items-center gap-1.5">
                                    <User className="h-4 w-4 text-muted-foreground" />
                                    Patient Information
                                </h4>
                                <div className="grid grid-cols-2 sm:grid-cols-3 gap-3">
                                    <div>
                                        <span className="text-muted-foreground block">Patient Name:</span>
                                        <span className="font-medium text-foreground text-sm">{patientName}</span>
                                    </div>
                                    <div>
                                        <span className="text-muted-foreground block">Card No:</span>
                                        <span className="font-mono font-medium text-foreground">{details.CardNo || '—'}</span>
                                    </div>
                                    <div>
                                        <span className="text-muted-foreground block">Scheme ID:</span>
                                        <span className="text-foreground">{details.SchemeID ?? '—'}</span>
                                    </div>
                                    <div>
                                        <span className="text-muted-foreground block">Gender:</span>
                                        <span className="capitalize text-foreground">{details.Gender || '—'}</span>
                                    </div>
                                    <div>
                                        <span className="text-muted-foreground block">Date of Birth:</span>
                                        <span className="text-foreground">
                                            {details.DateOfBirth ? new Date(details.DateOfBirth).toLocaleDateString() : '—'}
                                        </span>
                                    </div>
                                    <div>
                                        <span className="text-muted-foreground block">Facility Code:</span>
                                        <span className="font-mono text-foreground">{details.FacilityCode || '—'}</span>
                                    </div>
                                </div>
                            </div>

                            {/* Admission & Clinical Details */}
                            <div className="rounded-lg border bg-muted/20 p-4 text-xs space-y-3">
                                <h4 className="font-semibold text-foreground text-sm">Admission & Clinical Record</h4>
                                <div className="grid grid-cols-2 sm:grid-cols-3 gap-3">
                                    <div>
                                        <span className="text-muted-foreground block">Date Admitted:</span>
                                        <span className="font-medium text-foreground">
                                            {details.DateAdmitted ? new Date(details.DateAdmitted).toLocaleString() : '—'}
                                        </span>
                                    </div>
                                    <div>
                                        <span className="text-muted-foreground block">Admitting Practitioner No:</span>
                                        <span className="font-mono text-foreground font-medium">
                                            {details.AdmittingPractitionerNo || '—'}
                                        </span>
                                    </div>
                                    <div>
                                        <span className="text-muted-foreground block">Admission Type ID:</span>
                                        <span className="text-foreground">{details.AdmissionTypeID ?? '—'}</span>
                                    </div>
                                    <div>
                                        <span className="text-muted-foreground block">Ward / Room Type ID:</span>
                                        <span className="text-foreground">
                                            {details.WardTypeID ?? '—'} / {details.RoomTypeID ?? '—'}
                                        </span>
                                    </div>
                                    <div>
                                        <span className="text-muted-foreground block">Created By:</span>
                                        <span className="text-foreground">{details.CreatedBy || '—'}</span>
                                    </div>
                                    <div>
                                        <span className="text-muted-foreground block">Date Created:</span>
                                        <span className="text-foreground">
                                            {details.DateCreated ? new Date(details.DateCreated).toLocaleString() : '—'}
                                        </span>
                                    </div>
                                </div>

                                <div className="pt-2 border-t space-y-2">
                                    <div>
                                        <span className="text-muted-foreground block font-medium">Diagnosis at Admission:</span>
                                        <span className="text-foreground">{details.DiagnosisAtAdmission || '—'}</span>
                                    </div>
                                    <div>
                                        <span className="text-muted-foreground block font-medium">Admitting Practitioner's Remarks:</span>
                                        <span className="text-foreground">{details.AdmittingPractitionersRemarks || '—'}</span>
                                    </div>
                                    {details.AdmissionID && (
                                        <div className="text-[11px] text-muted-foreground pt-1">
                                            NHIF Admission UUID: <span className="font-mono">{details.AdmissionID}</span>
                                        </div>
                                    )}
                                </div>
                            </div>
                        </div>
                    )}
                </div>

                <DialogFooter className="flex flex-row justify-between sm:justify-between items-center w-full gap-2">
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        onClick={fetchDetails}
                        disabled={loading}
                        className="gap-1.5 text-xs"
                    >
                        <RefreshCw className={`h-3.5 w-3.5 ${loading ? 'animate-spin' : ''}`} />
                        Refresh from NHIF
                    </Button>

                    <div className="flex items-center gap-2">
                        {admissionId && details?.AdmissionNo && (
                            <Button
                                type="button"
                                variant="secondary"
                                size="sm"
                                onClick={handleSync}
                                disabled={syncing || loading}
                                className="gap-1.5 text-xs"
                            >
                                {syncing ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : <CheckCircle2 className="h-3.5 w-3.5 text-primary" />}
                                Sync to Admission Record
                            </Button>
                        )}
                        <Button type="button" variant="outline" size="sm" onClick={() => setOpen(false)}>
                            Close
                        </Button>
                    </div>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
