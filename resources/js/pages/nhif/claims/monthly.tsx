import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { useState, useEffect } from 'react';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Badge } from '@/components/ui/badge';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { 
    Calendar, 
    Building2, 
    FileText, 
    Banknote, 
    CheckCircle2, 
    AlertCircle, 
    RefreshCw, 
    Send, 
    History, 
    ChevronRight, 
    ShieldCheck, 
    Info, 
    ArrowLeft,
    Clock
} from 'lucide-react';
import axios from 'axios';

interface HospitalOption {
    id: number;
    name: string;
    code: string;
}

interface FolioSample {
    id: number;
    claim_number: string;
    folio_no: number;
    patient_name: string;
    card_no: string;
    amount: number;
    status: string;
    submission_no: string | null;
}

interface PreviewData {
    facility_code: string;
    hospital_name: string;
    claim_year: number;
    claim_month: number;
    folios_submitted: number;
    total_amount_claimed: number;
    formatted_total: string;
    can_submit: boolean;
    is_already_submitted: boolean;
    existing_submission: {
        id: number;
        status: string;
        folios_submitted: number;
        total_amount_claimed: number;
        submitted_at: string | null;
        error_message: string | null;
        retry_count: number;
    } | null;
    folios_sample: FolioSample[];
}

interface SubmissionRecord {
    id: number;
    claim_year: number;
    claim_month: number;
    facility_code: string;
    folios_submitted: number;
    total_amount_claimed: number;
    status: string;
    submission_remarks: string | null;
    error_message: string | null;
    retry_count: number;
    nhif_response: any;
    nhif_request_payload: any;
    submitted_at: string | null;
    submitted_by: string | null;
    hospital: {
        id: number;
        name: string;
        facility_code: string;
    } | null;
}

interface Props {
    submissions: {
        data: SubmissionRecord[];
        current_page: number;
        last_page: number;
        total: number;
    };
    hospitals: HospitalOption[];
    selectedHospitalId: number;
    selectedYear: number;
    selectedMonth: number;
    preview: PreviewData | null;
}

const MONTHS = [
    { value: 1, label: 'January' },
    { value: 2, label: 'February' },
    { value: 3, label: 'March' },
    { value: 4, label: 'April' },
    { value: 5, label: 'May' },
    { value: 6, label: 'June' },
    { value: 7, label: 'July' },
    { value: 8, label: 'August' },
    { value: 9, label: 'September' },
    { value: 10, label: 'October' },
    { value: 11, label: 'November' },
    { value: 12, label: 'December' },
];

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'NHIF', href: '/nhif/dashboard' },
    { title: 'Claims', href: '/nhif/claims' },
    { title: 'Monthly Claims', href: '/nhif/claims/monthly' },
];

export default function MonthlyClaims({
    submissions,
    hospitals,
    selectedHospitalId,
    selectedYear,
    selectedMonth,
    preview: initialPreview,
}: Props) {
    const [hospitalId, setHospitalId] = useState<number>(selectedHospitalId || hospitals[0]?.id || 1);
    const [claimYear, setClaimYear] = useState<number>(selectedYear || new Date().getFullYear());
    const [claimMonth, setClaimMonth] = useState<number>(selectedMonth || new Date().getMonth() + 1);
    const [remarks, setRemarks] = useState<string>('');
    const [preview, setPreview] = useState<PreviewData | null>(initialPreview);
    const [isCalculating, setIsCalculating] = useState<boolean>(false);
    const [isSubmitting, setIsSubmitting] = useState<boolean>(false);
    const [errorMessage, setErrorMessage] = useState<string | null>(null);
    const [successMessage, setSuccessMessage] = useState<string | null>(null);
    const [selectedSubmission, setSelectedSubmission] = useState<SubmissionRecord | null>(null);
    const [isConfirmOpen, setIsConfirmOpen] = useState<boolean>(false);
    const [retryingId, setRetryingId] = useState<number | null>(null);

    const activeHospital = hospitals.find(h => h.id === hospitalId) || hospitals[0];

    const fetchCalculation = async (hId = hospitalId, yr = claimYear, mo = claimMonth) => {
        setIsCalculating(true);
        setErrorMessage(null);
        try {
            const res = await axios.get(route('nhif.claims.monthly.calculate'), {
                params: {
                    hospital_id: hId,
                    claim_year: yr,
                    claim_month: mo,
                },
            });
            if (res.data?.success) {
                setPreview(res.data.data);
            }
        } catch (err: any) {
            setErrorMessage(err.response?.data?.message || 'Failed to calculate monthly claim totals.');
        } finally {
            setIsCalculating(false);
        }
    };

    const handleHospitalChange = (val: string) => {
        const id = parseInt(val, 10);
        setHospitalId(id);
        fetchCalculation(id, claimYear, claimMonth);
    };

    const handleYearChange = (val: string) => {
        const yr = parseInt(val, 10);
        setClaimYear(yr);
        fetchCalculation(hospitalId, yr, claimMonth);
    };

    const handleMonthChange = (val: string) => {
        const mo = parseInt(val, 10);
        setClaimMonth(mo);
        fetchCalculation(hospitalId, claimYear, mo);
    };

    const handleSubmitMonthly = async () => {
        setIsSubmitting(true);
        setErrorMessage(null);
        setSuccessMessage(null);
        setIsConfirmOpen(false);

        try {
            const res = await axios.post(route('nhif.claims.monthly.submit'), {
                hospital_id: hospitalId,
                claim_year: claimYear,
                claim_month: claimMonth,
                submission_remarks: remarks || undefined,
            });

            if (res.data?.success) {
                setSuccessMessage(res.data.message || 'Monthly claim successfully submitted to NHIF.');
                fetchCalculation(hospitalId, claimYear, claimMonth);
                router.reload({ only: ['submissions'] });
            } else {
                setErrorMessage(res.data?.message || 'Monthly claim submission failed at NHIF.');
            }
        } catch (err: any) {
            setErrorMessage(err.response?.data?.message || 'Submission failed. Please check NHIF connection.');
        } finally {
            setIsSubmitting(false);
        }
    };

    const handleRetry = async (submission: SubmissionRecord) => {
        setRetryingId(submission.id);
        setErrorMessage(null);
        setSuccessMessage(null);
        try {
            const res = await axios.post(route('nhif.claims.monthly.retry', submission.id));
            if (res.data?.success) {
                setSuccessMessage(res.data.message || 'Monthly claim retried successfully.');
                router.reload({ only: ['submissions'] });
                if (submission.claim_year === claimYear && submission.claim_month === claimMonth) {
                    fetchCalculation();
                }
            } else {
                setErrorMessage(res.data?.message || 'Monthly claim retry failed at NHIF.');
            }
        } catch (err: any) {
            setErrorMessage(err.response?.data?.message || 'Retry request failed.');
        } finally {
            setRetryingId(null);
        }
    };

    const monthName = MONTHS.find(m => m.value === claimMonth)?.label || `Month ${claimMonth}`;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="NHIF Monthly Claim Submission" />

            <div className="flex flex-col gap-6 p-6 max-w-7xl mx-auto w-full">
                {/* Header */}
                <div className="flex flex-col md:flex-row md:items-center justify-between gap-4">
                    <div>
                        <div className="flex items-center gap-2">
                            <Link href={route('nhif.claims.index')} className="text-muted-foreground hover:text-foreground">
                                <ArrowLeft className="w-5 h-5" />
                            </Link>
                            <h1 className="text-2xl font-bold tracking-tight">NHIF Monthly Claim Submission</h1>
                            <Badge variant="outline" className="bg-blue-50 text-blue-700 border-blue-200">
                                OCS API /SubmitMonthlyClaim
                            </Badge>
                        </div>
                        <p className="text-sm text-muted-foreground mt-1">
                            Finalize and submit consolidated end-of-month claims containing all authoritative, successfully submitted patient folios.
                        </p>
                    </div>

                    <div className="flex items-center gap-3">
                        <Link href={route('nhif.claims.index')}>
                            <Button variant="outline" size="sm" className="gap-2">
                                <FileText className="w-4 h-4" />
                                View Individual Claims
                            </Button>
                        </Link>
                    </div>
                </div>

                {/* Status Messages */}
                {successMessage && (
                    <div className="p-4 bg-emerald-50 border border-emerald-200 rounded-lg flex items-start gap-3 text-emerald-800">
                        <CheckCircle2 className="w-5 h-5 text-emerald-600 mt-0.5 shrink-0" />
                        <div className="flex-1">
                            <p className="font-semibold text-sm">Submission Successful</p>
                            <p className="text-sm">{successMessage}</p>
                        </div>
                        <Button variant="ghost" size="sm" onClick={() => setSuccessMessage(null)} className="h-7 text-xs">
                            Dismiss
                        </Button>
                    </div>
                )}

                {errorMessage && (
                    <div className="p-4 bg-rose-50 border border-rose-200 rounded-lg flex items-start gap-3 text-rose-800">
                        <AlertCircle className="w-5 h-5 text-rose-600 mt-0.5 shrink-0" />
                        <div className="flex-1">
                            <p className="font-semibold text-sm">Submission / Calculation Issue</p>
                            <p className="text-sm">{errorMessage}</p>
                        </div>
                        <Button variant="ghost" size="sm" onClick={() => setErrorMessage(null)} className="h-7 text-xs">
                            Dismiss
                        </Button>
                    </div>
                )}

                {/* Period & Facility Controls */}
                <Card className="border shadow-sm">
                    <CardHeader className="pb-4">
                        <CardTitle className="text-lg flex items-center gap-2">
                            <Calendar className="w-5 h-5 text-primary" />
                            Claim Period & Facility Selection
                        </CardTitle>
                        <CardDescription>
                            Select the target facility, year, and month to calculate and preview verified claim totals.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-4">
                            {/* Facility */}
                            <div>
                                <Label className="text-xs font-semibold uppercase tracking-wider text-muted-foreground mb-1 block">
                                    Facility Context
                                </Label>
                                <Select value={hospitalId.toString()} onValueChange={handleHospitalChange}>
                                    <SelectTrigger className="w-full">
                                        <SelectValue placeholder="Select Facility" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {hospitals.map(h => (
                                            <SelectItem key={h.id} value={h.id.toString()}>
                                                {h.name} {h.code ? `(${h.code})` : ''}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>

                            {/* Year */}
                            <div>
                                <Label className="text-xs font-semibold uppercase tracking-wider text-muted-foreground mb-1 block">
                                    Claim Year
                                </Label>
                                <Select value={claimYear.toString()} onValueChange={handleYearChange}>
                                    <SelectTrigger className="w-full">
                                        <SelectValue placeholder="Year" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {[2024, 2025, 2026, 2027, 2028].map(yr => (
                                            <SelectItem key={yr} value={yr.toString()}>
                                                {yr}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>

                            {/* Month */}
                            <div>
                                <Label className="text-xs font-semibold uppercase tracking-wider text-muted-foreground mb-1 block">
                                    Claim Month
                                </Label>
                                <Select value={claimMonth.toString()} onValueChange={handleMonthChange}>
                                    <SelectTrigger className="w-full">
                                        <SelectValue placeholder="Month" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {MONTHS.map(m => (
                                            <SelectItem key={m.value} value={m.value.toString()}>
                                                {m.label} ({m.value})
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>

                            {/* Action */}
                            <div className="flex items-end">
                                <Button 
                                    variant="outline" 
                                    className="w-full gap-2 border-dashed"
                                    onClick={() => fetchCalculation()}
                                    disabled={isCalculating}
                                >
                                    <RefreshCw className={`w-4 h-4 ${isCalculating ? 'animate-spin text-primary' : ''}`} />
                                    {isCalculating ? 'Calculating...' : 'Calculate Monthly Claim'}
                                </Button>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                {/* Calculation Dashboard & Preview Section */}
                {preview && (
                    <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
                        {/* Summary Metrics Cards */}
                        <div className="lg:col-span-2 space-y-6">
                            <Card className="border shadow-sm bg-gradient-to-br from-card to-muted/20">
                                <CardHeader className="pb-3 border-b">
                                    <div className="flex items-center justify-between">
                                        <div>
                                            <CardTitle className="text-base font-semibold">
                                                {monthName} {preview.claim_year} NHIF Monthly Claim Summary
                                            </CardTitle>
                                            <CardDescription className="text-xs mt-0.5">
                                                Facility: <span className="font-semibold text-foreground">{preview.hospital_name}</span> (Code: {preview.facility_code || 'Not Set'})
                                            </CardDescription>
                                        </div>
                                        {preview.is_already_submitted ? (
                                            <Badge className="bg-emerald-600 text-white gap-1 py-1">
                                                <CheckCircle2 className="w-3.5 h-3.5" />
                                                Already Submitted
                                            </Badge>
                                        ) : preview.folios_submitted > 0 ? (
                                            <Badge className="bg-blue-600 text-white gap-1 py-1">
                                                <ShieldCheck className="w-3.5 h-3.5" />
                                                Ready for Submission
                                            </Badge>
                                        ) : (
                                            <Badge variant="secondary" className="gap-1 py-1 text-amber-700 bg-amber-50 border-amber-200">
                                                <Info className="w-3.5 h-3.5" />
                                                No Submitted Folios
                                            </Badge>
                                        )}
                                    </div>
                                </CardHeader>
                                <CardContent className="pt-6 space-y-6">
                                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                        {/* Submitted Folios Count */}
                                        <div className="p-4 rounded-xl bg-background border flex items-center justify-between">
                                            <div>
                                                <p className="text-xs font-semibold text-muted-foreground uppercase tracking-wider">
                                                    Submitted Folios Count
                                                </p>
                                                <p className="text-3xl font-extrabold mt-1 text-foreground">
                                                    {preview.folios_submitted.toLocaleString()}
                                                </p>
                                                <p className="text-xs text-muted-foreground mt-1">
                                                    Authoritative, successfully signed & submitted
                                                </p>
                                            </div>
                                            <div className="w-12 h-12 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center shrink-0">
                                                <FileText className="w-6 h-6" />
                                            </div>
                                        </div>

                                        {/* Total Amount Claimed */}
                                        <div className="p-4 rounded-xl bg-background border flex items-center justify-between">
                                            <div>
                                                <p className="text-xs font-semibold text-muted-foreground uppercase tracking-wider">
                                                    Total Amount Claimed
                                                </p>
                                                <p className="text-3xl font-extrabold mt-1 text-primary">
                                                    TZS {preview.formatted_total}
                                                </p>
                                                <p className="text-xs text-muted-foreground mt-1">
                                                    Sum of allowable tariffs for this period
                                                </p>
                                            </div>
                                            <div className="w-12 h-12 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center shrink-0">
                                                <Banknote className="w-6 h-6" />
                                            </div>
                                        </div>
                                    </div>

                                    {/* Sample Included Folios */}
                                    {preview.folios_sample && preview.folios_sample.length > 0 && (
                                        <div className="space-y-2">
                                            <div className="flex items-center justify-between">
                                                <p className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                                                    Included Submitted Folios (Sample)
                                                </p>
                                                <span className="text-xs text-muted-foreground">
                                                    Showing {preview.folios_sample.length} of {preview.folios_submitted}
                                                </span>
                                            </div>
                                            <div className="rounded-lg border overflow-hidden bg-background">
                                                <table className="w-full text-xs">
                                                    <thead className="bg-muted/40 border-b">
                                                        <tr>
                                                            <th className="px-3 py-2 text-left font-medium">Claim #</th>
                                                            <th className="px-3 py-2 text-left font-medium">Patient</th>
                                                            <th className="px-3 py-2 text-left font-medium">Card No</th>
                                                            <th className="px-3 py-2 text-right font-medium">Amount (TZS)</th>
                                                            <th className="px-3 py-2 text-center font-medium">Status</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody className="divide-y">
                                                        {preview.folios_sample.map(f => (
                                                            <tr key={f.id} className="hover:bg-muted/30">
                                                                <td className="px-3 py-2 font-mono font-medium">{f.claim_number}</td>
                                                                <td className="px-3 py-2">{f.patient_name}</td>
                                                                <td className="px-3 py-2 font-mono text-muted-foreground">{f.card_no}</td>
                                                                <td className="px-3 py-2 text-right font-semibold">{f.amount.toLocaleString()}</td>
                                                                <td className="px-3 py-2 text-center">
                                                                    <Badge variant="outline" className="text-[10px] py-0 bg-emerald-50 text-emerald-700 border-emerald-200">
                                                                        {f.status}
                                                                    </Badge>
                                                                </td>
                                                            </tr>
                                                        ))}
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                    )}
                                </CardContent>
                            </Card>
                        </div>

                        {/* Submission Action Card */}
                        <div className="space-y-6">
                            <Card className="border shadow-sm h-full flex flex-col justify-between">
                                <CardHeader>
                                    <CardTitle className="text-base flex items-center gap-2">
                                        <Send className="w-4 h-4 text-primary" />
                                        Submit Monthly Claim
                                    </CardTitle>
                                    <CardDescription className="text-xs">
                                        Sends the consolidated payload directly to NHIF OCS endpoint: <code className="text-[11px] bg-muted px-1 rounded">/ocs/api/Claims/SubmitMonthlyClaim</code>
                                    </CardDescription>
                                </CardHeader>
                                <CardContent className="space-y-4">
                                    <div>
                                        <Label className="text-xs font-semibold mb-1 block">
                                            Submission Remarks (Optional)
                                        </Label>
                                        <Textarea
                                            placeholder={`Monthly NHIF claim submission for ${monthName} ${claimYear}...`}
                                            value={remarks}
                                            onChange={e => setRemarks(e.target.value)}
                                            rows={3}
                                            className="text-xs"
                                        />
                                        <p className="text-[11px] text-muted-foreground mt-1">
                                            Included in the OCS <code className="font-mono">SubmissionRemarks</code> field.
                                        </p>
                                    </div>

                                    <div className="p-3 bg-muted/40 rounded-lg text-xs space-y-1.5 border">
                                        <div className="flex justify-between">
                                            <span className="text-muted-foreground">Facility Code:</span>
                                            <span className="font-mono font-medium">{preview.facility_code || 'N/A'}</span>
                                        </div>
                                        <div className="flex justify-between">
                                            <span className="text-muted-foreground">Claim Period:</span>
                                            <span className="font-medium">{claimYear}-{String(claimMonth).padStart(2, '0')}</span>
                                        </div>
                                        <div className="flex justify-between">
                                            <span className="text-muted-foreground">Folios to Submit:</span>
                                            <span className="font-semibold">{preview.folios_submitted}</span>
                                        </div>
                                        <div className="flex justify-between">
                                            <span className="text-muted-foreground">Calculated Claim Total:</span>
                                            <span className="font-bold text-primary">TZS {preview.formatted_total}</span>
                                        </div>
                                    </div>

                                    {preview.is_already_submitted && (
                                        <div className="p-3 bg-emerald-50 border border-emerald-200 rounded-lg text-xs text-emerald-800 flex items-center gap-2">
                                            <CheckCircle2 className="w-4 h-4 text-emerald-600 shrink-0" />
                                            <span>This period was already successfully submitted to NHIF on {preview.existing_submission?.submitted_at}.</span>
                                        </div>
                                    )}

                                    {preview.folios_submitted === 0 && (
                                        <div className="p-3 bg-amber-50 border border-amber-200 rounded-lg text-xs text-amber-800 flex items-center gap-2">
                                            <AlertCircle className="w-4 h-4 text-amber-600 shrink-0" />
                                            <span>No submitted folios found for this month. Individual folios must be signed and submitted first.</span>
                                        </div>
                                    )}

                                    <Button
                                        className="w-full gap-2 bg-blue-600 hover:bg-blue-700 text-white font-medium"
                                        size="lg"
                                        disabled={!preview.can_submit || isSubmitting}
                                        onClick={() => setIsConfirmOpen(true)}
                                    >
                                        <Send className={`w-4 h-4 ${isSubmitting ? 'animate-spin' : ''}`} />
                                        {isSubmitting ? 'Submitting to NHIF...' : 'Submit Monthly Claim'}
                                    </Button>
                                    <p className="text-[11px] text-center text-muted-foreground">
                                        Authoritative values are calculated strictly server-side before transmission.
                                    </p>
                                </CardContent>
                            </Card>
                        </div>
                    </div>
                )}

                {/* Submissions History Table */}
                <Card className="border shadow-sm">
                    <CardHeader className="pb-3 border-b">
                        <div className="flex items-center justify-between">
                            <div className="flex items-center gap-2">
                                <History className="w-5 h-5 text-primary" />
                                <CardTitle className="text-base font-semibold">
                                    Monthly Claims Submission History & Audit Log
                                </CardTitle>
                            </div>
                            <span className="text-xs text-muted-foreground">
                                Total Submissions: {submissions.total}
                            </span>
                        </div>
                        <CardDescription>
                            Detailed record of all previous NHIF monthly submissions, responses, and audit tracking.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="p-0">
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead className="bg-muted/40 border-b">
                                    <tr>
                                        <th className="px-4 py-3 text-left font-semibold text-xs text-muted-foreground uppercase">Period</th>
                                        <th className="px-4 py-3 text-left font-semibold text-xs text-muted-foreground uppercase">Facility</th>
                                        <th className="px-4 py-3 text-right font-semibold text-xs text-muted-foreground uppercase">Folios</th>
                                        <th className="px-4 py-3 text-right font-semibold text-xs text-muted-foreground uppercase">Total Claimed</th>
                                        <th className="px-4 py-3 text-left font-semibold text-xs text-muted-foreground uppercase">Status</th>
                                        <th className="px-4 py-3 text-left font-semibold text-xs text-muted-foreground uppercase">Submitted At / By</th>
                                        <th className="px-4 py-3 text-right font-semibold text-xs text-muted-foreground uppercase">Actions</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {submissions.data.length === 0 ? (
                                        <tr>
                                            <td colSpan={7} className="px-4 py-8 text-center text-muted-foreground text-sm">
                                                No monthly claim submissions recorded yet. Select a period above to calculate and submit.
                                            </td>
                                        </tr>
                                    ) : (
                                        submissions.data.map(sub => (
                                            <tr key={sub.id} className="hover:bg-muted/30 transition-colors">
                                                <td className="px-4 py-3 font-semibold font-mono">
                                                    {sub.claim_year}-{String(sub.claim_month).padStart(2, '0')}
                                                </td>
                                                <td className="px-4 py-3">
                                                    <div className="font-medium text-xs text-foreground">{sub.hospital?.name || 'Facility'}</div>
                                                    <div className="text-[11px] font-mono text-muted-foreground">{sub.facility_code}</div>
                                                </td>
                                                <td className="px-4 py-3 text-right font-semibold">
                                                    {sub.folios_submitted.toLocaleString()}
                                                </td>
                                                <td className="px-4 py-3 text-right font-bold text-primary">
                                                    TZS {Number(sub.total_amount_claimed).toLocaleString()}
                                                </td>
                                                <td className="px-4 py-3">
                                                    {sub.status === 'submitted' ? (
                                                        <Badge className="bg-emerald-100 text-emerald-800 border-emerald-300 gap-1 hover:bg-emerald-100">
                                                            <CheckCircle2 className="w-3 h-3" />
                                                            Submitted
                                                        </Badge>
                                                    ) : sub.status === 'failed' ? (
                                                        <Badge variant="destructive" className="gap-1">
                                                            <AlertCircle className="w-3 h-3" />
                                                            Failed {sub.retry_count > 1 ? `(${sub.retry_count} tries)` : ''}
                                                        </Badge>
                                                    ) : (
                                                        <Badge variant="secondary" className="gap-1">
                                                            <Clock className="w-3 h-3" />
                                                            {sub.status}
                                                        </Badge>
                                                    )}
                                                </td>
                                                <td className="px-4 py-3 text-xs text-muted-foreground">
                                                    <div>{sub.submitted_at || '—'}</div>
                                                    {sub.submitted_by && (
                                                        <div className="text-[11px] text-foreground font-medium">{sub.submitted_by}</div>
                                                    )}
                                                </td>
                                                <td className="px-4 py-3 text-right">
                                                    <div className="flex items-center justify-end gap-2">
                                                        <Button
                                                            variant="ghost"
                                                            size="sm"
                                                            className="h-8 px-2 text-xs"
                                                            onClick={() => setSelectedSubmission(sub)}
                                                        >
                                                            Details
                                                        </Button>

                                                        {sub.status === 'failed' && (
                                                            <Button
                                                                variant="outline"
                                                                size="sm"
                                                                className="h-8 px-2 text-xs text-rose-700 border-rose-200 hover:bg-rose-50 gap-1"
                                                                onClick={() => handleRetry(sub)}
                                                                disabled={retryingId === sub.id}
                                                            >
                                                                <RefreshCw className={`w-3.5 h-3.5 ${retryingId === sub.id ? 'animate-spin' : ''}`} />
                                                                Retry
                                                            </Button>
                                                        )}
                                                    </div>
                                                </td>
                                            </tr>
                                        ))
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </CardContent>
                </Card>
            </div>

            {/* Confirmation Dialog */}
            <Dialog open={isConfirmOpen} onOpenChange={setIsConfirmOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle className="flex items-center gap-2">
                            <ShieldCheck className="w-5 h-5 text-primary" />
                            Confirm NHIF Monthly Claim Submission
                        </DialogTitle>
                        <DialogDescription>
                            Please confirm the authoritative claim totals before submitting to the NHIF OCS portal.
                        </DialogDescription>
                    </DialogHeader>

                    {preview && (
                        <div className="space-y-4 py-2">
                            <div className="p-4 bg-muted/40 rounded-lg space-y-2 text-sm border">
                                <div className="flex justify-between">
                                    <span className="text-muted-foreground">Target Period:</span>
                                    <span className="font-bold">{monthName} {claimYear} ({claimYear}-{String(claimMonth).padStart(2, '0')})</span>
                                </div>
                                <div className="flex justify-between">
                                    <span className="text-muted-foreground">Facility:</span>
                                    <span className="font-semibold">{preview.hospital_name} ({preview.facility_code})</span>
                                </div>
                                <div className="flex justify-between">
                                    <span className="text-muted-foreground">Submitted Folios Count:</span>
                                    <span className="font-bold text-base text-foreground">{preview.folios_submitted} folios</span>
                                </div>
                                <div className="flex justify-between border-t pt-2">
                                    <span className="text-muted-foreground">Total Claim Amount:</span>
                                    <span className="font-extrabold text-base text-primary">TZS {preview.formatted_total}</span>
                                </div>
                            </div>
                            <p className="text-xs text-muted-foreground">
                                This submission will register the monthly claim with NHIF. Ensure all folios for this period have been finalized.
                            </p>
                        </div>
                    )}

                    <DialogFooter className="gap-2">
                        <Button variant="outline" onClick={() => setIsConfirmOpen(false)} disabled={isSubmitting}>
                            Cancel
                        </Button>
                        <Button onClick={handleSubmitMonthly} disabled={isSubmitting} className="bg-blue-600 hover:bg-blue-700 text-white gap-2">
                            <Send className={`w-4 h-4 ${isSubmitting ? 'animate-spin' : ''}`} />
                            {isSubmitting ? 'Submitting...' : 'Confirm & Submit to NHIF'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* Submission Details Modal */}
            <Dialog open={!!selectedSubmission} onOpenChange={open => !open && setSelectedSubmission(null)}>
                <DialogContent className="max-w-2xl">
                    <DialogHeader>
                        <DialogTitle className="flex items-center gap-2">
                            <FileText className="w-5 h-5 text-primary" />
                            Monthly Submission Details: {selectedSubmission?.claim_year}-{String(selectedSubmission?.claim_month).padStart(2, '0')}
                        </DialogTitle>
                        <DialogDescription>
                            Audit information and raw NHIF response data.
                        </DialogDescription>
                    </DialogHeader>

                    {selectedSubmission && (
                        <div className="space-y-4 py-2 text-xs">
                            <div className="grid grid-cols-2 gap-3 p-3 bg-muted/40 rounded-lg border">
                                <div>
                                    <span className="text-muted-foreground block">Facility:</span>
                                    <span className="font-semibold text-foreground">{selectedSubmission.hospital?.name} ({selectedSubmission.facility_code})</span>
                                </div>
                                <div>
                                    <span className="text-muted-foreground block">Status:</span>
                                    <span className="font-semibold uppercase">{selectedSubmission.status}</span>
                                </div>
                                <div>
                                    <span className="text-muted-foreground block">Folios Count:</span>
                                    <span className="font-semibold text-foreground">{selectedSubmission.folios_submitted}</span>
                                </div>
                                <div>
                                    <span className="text-muted-foreground block">Total Amount:</span>
                                    <span className="font-bold text-primary">TZS {Number(selectedSubmission.total_amount_claimed).toLocaleString()}</span>
                                </div>
                                <div>
                                    <span className="text-muted-foreground block">Submitted At:</span>
                                    <span>{selectedSubmission.submitted_at || '—'}</span>
                                </div>
                                <div>
                                    <span className="text-muted-foreground block">Submitted By:</span>
                                    <span>{selectedSubmission.submitted_by || '—'}</span>
                                </div>
                            </div>

                            {selectedSubmission.submission_remarks && (
                                <div>
                                    <Label className="text-xs font-semibold mb-1 block">Submission Remarks</Label>
                                    <div className="p-2 bg-muted rounded border text-xs">{selectedSubmission.submission_remarks}</div>
                                </div>
                            )}

                            {selectedSubmission.error_message && (
                                <div>
                                    <Label className="text-xs font-semibold text-rose-700 mb-1 block">Error Message</Label>
                                    <div className="p-2 bg-rose-50 border border-rose-200 text-rose-800 rounded text-xs">
                                        {selectedSubmission.error_message}
                                    </div>
                                </div>
                            )}

                            <div>
                                <Label className="text-xs font-semibold mb-1 block">NHIF API Response</Label>
                                <pre className="p-3 bg-muted/80 rounded border overflow-x-auto text-[11px] font-mono max-h-48">
                                    {JSON.stringify(selectedSubmission.nhif_response, null, 2)}
                                </pre>
                            </div>

                            {selectedSubmission.nhif_request_payload && (
                                <div>
                                    <Label className="text-xs font-semibold mb-1 block">NHIF Request Payload</Label>
                                    <pre className="p-3 bg-muted/80 rounded border overflow-x-auto text-[11px] font-mono max-h-48">
                                        {JSON.stringify(selectedSubmission.nhif_request_payload, null, 2)}
                                    </pre>
                                </div>
                            )}
                        </div>
                    )}

                    <DialogFooter>
                        <Button variant="outline" onClick={() => setSelectedSubmission(null)}>
                            Close
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
