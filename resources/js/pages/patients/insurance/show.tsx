import { Can } from '@/components/authorization/can';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { effectiveInsuranceStatus, insuranceStatusLabel } from '@/lib/insurance-status';
import { type BreadcrumbItem } from '@/types';
import {
    type NhifEligibilityRecord,
    type NhifEligibilityStatus,
    type NhifMemberVerification,
    type NhifVerificationStatus,
    type PatientInsurance,
    type PatientInsurancePatient,
} from '@/types/patient-insurance';
import { NhifVerificationDialog } from '@/components/patients/nhif-verification-dialog';
import { Head, Link, router } from '@inertiajs/react';
import { AlertTriangle, Pencil, RefreshCw, ShieldCheck } from 'lucide-react';
import { useState } from 'react';

interface InsuranceShowProps {
    patient: PatientInsurancePatient;
    insurance: PatientInsurance;
}

// ---- NHIF Member Verification ----

const nhifVerifStatusLabel: Record<NhifVerificationStatus, string> = {
    verified: 'Verified',
    lookup_found: 'Lookup Found',
    verification_failed: 'Verification Failed',
    not_found: 'Not Found',
    invalid: 'Invalid',
    expired: 'Expired',
    inactive: 'Inactive',
    error: 'Error',
};

function nhifVerifVariant(status: NhifVerificationStatus): 'default' | 'secondary' | 'destructive' {
    if (status === 'verified') return 'default';
    if (status === 'error' || status === 'invalid') return 'destructive';
    return 'secondary';
}

function NhifVerificationCard({
    verification,
    patient,
    insurance,
}: {
    verification: NhifMemberVerification;
    patient?: PatientInsurancePatient;
    insurance?: PatientInsurance;
}) {
    const [refreshing, setRefreshing] = useState(false);
    const hasPhoto = !!verification.member_picture;
    const pictureSrc = verification.member_picture || '/images/default-avatar.svg';

    const handleRefreshPicture = () => {
        if (!patient || !insurance) return;
        setRefreshing(true);
        router.post(
            route('patients.insurance.nhif-refresh-picture', [patient.id, insurance.id]),
            {},
            {
                onFinish: () => setRefreshing(false),
            },
        );
    };

    return (
        <Card className="max-w-2xl">
            <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                <CardTitle className="text-base">Latest NHIF Verification</CardTitle>
                {patient && insurance && verification.verification_status === 'verified' && (
                    <Can permission="patient-insurance.verify">
                        <Button
                            variant="ghost"
                            size="sm"
                            className="h-8 px-2 text-xs text-muted-foreground hover:text-foreground"
                            onClick={handleRefreshPicture}
                            disabled={refreshing}
                        >
                            <RefreshCw className={`mr-1 h-3.5 w-3.5 ${refreshing ? 'animate-spin' : ''}`} />
                            {refreshing ? 'Fetching Photo…' : 'Refresh Photo'}
                        </Button>
                    </Can>
                )}
            </CardHeader>
            <CardContent>
                <div className="flex flex-col sm:flex-row items-start gap-5 pt-2">
                    {/* Member Picture / Default Avatar */}
                    <div className="flex flex-col items-center gap-2 self-center sm:self-start shrink-0">
                        <div className="relative h-28 w-28 overflow-hidden rounded-xl border bg-muted/30 shadow-xs ring-1 ring-border">
                            <img
                                src={pictureSrc}
                                alt={verification.nhif_member_name ?? 'NHIF Member'}
                                className="h-full w-full object-cover object-top"
                                onError={(e) => {
                                    e.currentTarget.src = '/images/default-avatar.svg';
                                }}
                            />
                        </div>
                        <Badge
                            variant={hasPhoto ? 'default' : 'outline'}
                            className={`text-[10px] px-1.5 py-0 font-normal ${hasPhoto ? 'bg-emerald-600 hover:bg-emerald-700 text-white' : 'text-muted-foreground'}`}
                        >
                            {hasPhoto ? 'NHIF Photo' : 'Default Avatar'}
                        </Badge>
                    </div>

                    <dl className="flex-1 space-y-2 text-sm w-full">
                        <div className="flex justify-between gap-4">
                            <dt className="text-muted-foreground">Status</dt>
                            <dd>
                                <Badge variant={nhifVerifVariant(verification.verification_status)}>
                                    {nhifVerifStatusLabel[verification.verification_status]}
                                </Badge>
                            </dd>
                        </div>
                        {verification.nhif_member_name && (
                            <div className="flex justify-between gap-4">
                                <dt className="text-muted-foreground">Member Name</dt>
                                <dd className="font-medium">{verification.nhif_member_name}</dd>
                            </div>
                        )}
                        {verification.nhif_card_no && (
                            <div className="flex justify-between gap-4">
                                <dt className="text-muted-foreground">Card No.</dt>
                                <dd className="font-mono">{verification.nhif_card_no}</dd>
                            </div>
                        )}
                        {verification.nhif_membership_no && (
                            <div className="flex justify-between gap-4">
                                <dt className="text-muted-foreground">Membership No.</dt>
                                <dd className="font-mono">{verification.nhif_membership_no}</dd>
                            </div>
                        )}
                        {verification.nhif_plan_name && (
                            <div className="flex justify-between gap-4">
                                <dt className="text-muted-foreground">Plan</dt>
                                <dd>{verification.nhif_plan_name}</dd>
                            </div>
                        )}
                        {verification.nhif_expiry_date && (
                            <div className="flex justify-between gap-4">
                                <dt className="text-muted-foreground">Expiry Date</dt>
                                <dd>{verification.nhif_expiry_date}</dd>
                            </div>
                        )}
                        {verification.error_message && (
                            <div className="flex justify-between gap-4">
                                <dt className="text-muted-foreground">Error</dt>
                                <dd className="text-destructive">{verification.error_message}</dd>
                            </div>
                        )}
                        <div className="flex justify-between gap-4">
                            <dt className="text-muted-foreground">Checked At</dt>
                            <dd>{verification.verified_at ?? verification.created_at}</dd>
                        </div>
                    </dl>
                </div>
            </CardContent>
        </Card>
    );
}

// ---- NHIF Eligibility ----

const nhifEligStatusLabel: Record<NhifEligibilityStatus, string> = {
    eligible: 'Eligible',
    ineligible: 'Not Eligible',
    expired: 'Expired',
    suspended: 'Suspended',
    unknown: 'Unknown',
    error: 'Error',
};

function nhifEligVariant(status: NhifEligibilityStatus): 'default' | 'secondary' | 'destructive' {
    if (status === 'eligible') return 'default';
    if (status === 'error' || status === 'ineligible') return 'destructive';
    return 'secondary';
}

function NhifEligibilityCard({ eligibility }: { eligibility: NhifEligibilityRecord }) {
    const patientResp =
        eligibility.nhif_coverage_percent !== null
            ? Math.max(0, Math.round((100 - parseFloat(eligibility.nhif_coverage_percent ?? '0')) * 100) / 100)
            : null;

    return (
        <Card className="max-w-2xl">
            <CardHeader>
                <CardTitle className="text-base">Latest NHIF Eligibility</CardTitle>
            </CardHeader>
            <CardContent>
                <dl className="space-y-2 text-sm">
                    <div className="flex justify-between gap-4">
                        <dt className="text-muted-foreground">Status</dt>
                        <dd>
                            <Badge variant={nhifEligVariant(eligibility.eligibility_status)}>
                                {nhifEligStatusLabel[eligibility.eligibility_status]}
                            </Badge>
                        </dd>
                    </div>
                    {eligibility.nhif_coverage_percent !== null && (
                        <div className="flex justify-between gap-4">
                            <dt className="text-muted-foreground">NHIF Covers</dt>
                            <dd>{eligibility.nhif_coverage_percent}%</dd>
                        </div>
                    )}
                    {patientResp !== null && (
                        <div className="flex justify-between gap-4">
                            <dt className="text-muted-foreground">Patient Responsibility</dt>
                            <dd>{patientResp === 0 ? 'None (fully covered)' : `${patientResp}%`}</dd>
                        </div>
                    )}
                    {eligibility.nhif_valid_from && (
                        <div className="flex justify-between gap-4">
                            <dt className="text-muted-foreground">Valid From</dt>
                            <dd>{eligibility.nhif_valid_from}</dd>
                        </div>
                    )}
                    {eligibility.nhif_valid_to && (
                        <div className="flex justify-between gap-4">
                            <dt className="text-muted-foreground">Valid To</dt>
                            <dd>{eligibility.nhif_valid_to}</dd>
                        </div>
                    )}
                    {eligibility.nhif_remarks && (
                        <div className="flex justify-between gap-4">
                            <dt className="text-muted-foreground">Remarks</dt>
                            <dd>{eligibility.nhif_remarks}</dd>
                        </div>
                    )}
                    {eligibility.error_message && (
                        <div className="flex justify-between gap-4">
                            <dt className="text-muted-foreground">Error</dt>
                            <dd className="text-destructive">{eligibility.error_message}</dd>
                        </div>
                    )}
                    <div className="flex justify-between gap-4">
                        <dt className="text-muted-foreground">Checked At</dt>
                        <dd>{eligibility.eligibility_checked_at ?? eligibility.created_at}</dd>
                    </div>
                </dl>
            </CardContent>
        </Card>
    );
}

// ---- Main page ----

export default function InsuranceShow({ patient, insurance }: InsuranceShowProps) {
    const [verificationDialogOpen, setVerificationDialogOpen] = useState(false);
    const [checkingEligibility, setCheckingEligibility] = useState(false);

    const fullName = [patient.first_name, patient.middle_name, patient.last_name].filter(Boolean).join(' ');
    const displayStatus = effectiveInsuranceStatus(insurance.status, insurance.effective_to);

    const apiEnabled = insurance.insurance_provider?.api_enabled === true;
    const hasCardIdentifier = !!insurance.card_number || !!insurance.membership_number;

    const canLiveVerify = apiEnabled && (hasCardIdentifier || !!patient.national_id);
    const canCheckEligibility = apiEnabled && hasCardIdentifier;

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Patients', href: '/patients' },
        { title: fullName, href: `/patients/${patient.id}` },
        { title: 'Insurance', href: `/patients/${patient.id}/insurance/${insurance.id}` },
    ];

    function handleNhifVerify() {
        setVerificationDialogOpen(true);
    }

    function handleNhifEligibility() {
        setCheckingEligibility(true);
        router.post(route('patients.insurance.nhif-eligibility', [patient.id, insurance.id]), {}, {
            onFinish: () => setCheckingEligibility(false),
        });
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Insurance — ${fullName}`} />

            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-semibold">{insurance.insurance_provider?.name ?? 'Insurance'}</h1>
                        <p className="text-muted-foreground text-sm">
                            {fullName} · {patient.patient_number}
                        </p>
                    </div>

                    <div className="flex flex-wrap gap-2">
                        {canCheckEligibility && (
                            <Can permission="patient-insurance.verify">
                                <Button variant="outline" onClick={handleNhifEligibility} disabled={checkingEligibility}>
                                    <ShieldCheck className="h-4 w-4" />
                                    {checkingEligibility ? 'Checking…' : 'Check Eligibility'}
                                </Button>
                            </Can>
                        )}

                        {canLiveVerify && (
                            <Can permission="patient-insurance.verify">
                                <Button variant="outline" onClick={handleNhifVerify}>
                                    <ShieldCheck className="h-4 w-4" />
                                    Verify with NHIF
                                </Button>
                            </Can>
                        )}

                        <Can permission="patient-insurance.update">
                            <Button asChild variant="outline">
                                <Link href={route('patients.insurance.edit', [patient.id, insurance.id])}>
                                    <Pencil className="h-4 w-4" />
                                    Edit
                                </Link>
                            </Button>
                        </Can>
                    </div>
                </div>

                {((insurance.nhif_authorization_no && insurance.is_authorization_expired) || insurance.verification_status === 'failed') && (
                    <div className="max-w-2xl rounded-lg border border-amber-500/40 bg-amber-500/10 p-4 text-amber-900 dark:text-amber-200">
                        <div className="flex items-start gap-3">
                            <AlertTriangle className="h-5 w-5 text-amber-600 dark:text-amber-400 mt-0.5 shrink-0" />
                            <div className="flex-1">
                                <h3 className="font-semibold text-sm">
                                    {insurance.verification_status === 'failed' ? 'NHIF Card Verification Required' : 'NHIF 24-Hour Authorization Expired'}
                                </h3>
                                <p className="text-xs text-muted-foreground mt-1">
                                    {insurance.verification_status === 'failed'
                                        ? 'The latest NHIF card verification failed or was rejected by NHIF. A valid authorization must be obtained by re-verifying the patient card before creating visits, admissions, or requesting services.'
                                        : `The NHIF authorization number (${insurance.nhif_authorization_no}) was verified on ${insurance.verified_at ? new Date(insurance.verified_at).toLocaleString() : 'N/A'}. NHIF authorizations are only valid for 24 hours. A new authorization number must be obtained by re-verifying the patient's card before creating visits, admissions, or requesting services.`}
                                </p>
                                {canLiveVerify && (
                                    <div className="mt-3">
                                        <Can permission="patient-insurance.verify">
                                            <Button size="sm" variant="default" onClick={handleNhifVerify}>
                                                <ShieldCheck className="h-4 w-4 mr-1.5" />
                                                Re-verify Card with NHIF
                                            </Button>
                                        </Can>
                                    </div>
                                )}
                            </div>
                        </div>
                    </div>
                )}

                <Card className="max-w-2xl">
                    <CardHeader>
                        <CardTitle className="text-base">Insurance Details</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <dl className="space-y-2 text-sm">
                            <div className="flex justify-between gap-4">
                                <dt className="text-muted-foreground">Scheme</dt>
                                <dd>{insurance.insurance_scheme?.name ?? '—'}</dd>
                            </div>
                            <div className="flex justify-between gap-4">
                                <dt className="text-muted-foreground">Membership Number</dt>
                                <dd>{insurance.membership_number ?? '—'}</dd>
                            </div>
                            <div className="flex justify-between gap-4">
                                <dt className="text-muted-foreground">Policy Number</dt>
                                <dd>{insurance.policy_number ?? '—'}</dd>
                            </div>
                            <div className="flex justify-between gap-4">
                                <dt className="text-muted-foreground">Card Number</dt>
                                <dd>{insurance.card_number ?? '—'}</dd>
                            </div>
                            <div className="flex justify-between gap-4">
                                <dt className="text-muted-foreground">Principal Member</dt>
                                <dd>{insurance.principal_member_name ?? '—'}</dd>
                            </div>
                            <div className="flex justify-between gap-4">
                                <dt className="text-muted-foreground">Relationship</dt>
                                <dd className="capitalize">{insurance.relationship ?? '—'}</dd>
                            </div>
                            <div className="flex justify-between gap-4">
                                <dt className="text-muted-foreground">Effective</dt>
                                <dd>
                                    {insurance.effective_from ?? '—'} to {insurance.effective_to ?? '—'}
                                </dd>
                            </div>
                            <div className="flex justify-between gap-4">
                                <dt className="text-muted-foreground">Primary</dt>
                                <dd>{insurance.is_primary ? 'Yes' : 'No'}</dd>
                            </div>
                            <div className="flex justify-between gap-4">
                                <dt className="text-muted-foreground">Status</dt>
                                <dd>
                                    <Badge variant={displayStatus === 'active' ? 'default' : 'secondary'}>
                                        {insuranceStatusLabel[displayStatus]}
                                    </Badge>
                                </dd>
                            </div>
                            <div className="flex justify-between gap-4">
                                <dt className="text-muted-foreground">Verification</dt>
                                <dd className="capitalize">{insurance.verification_status}</dd>
                            </div>
                            {insurance.nhif_authorization_no && insurance.nhif_authorization_no !== 'N/A' && (
                                <>
                                    <div className="flex justify-between gap-4">
                                        <dt className="text-muted-foreground">NHIF Authorization No.</dt>
                                        <dd className="font-mono font-medium">{insurance.nhif_authorization_no}</dd>
                                    </div>
                                    <div className="flex justify-between gap-4">
                                        <dt className="text-muted-foreground">Authorization Validity</dt>
                                        <dd>
                                            {insurance.verification_status === 'verified' && !insurance.is_authorization_expired ? (
                                                <div className="flex items-center gap-2">
                                                    <Badge variant="default" className="bg-emerald-600 hover:bg-emerald-700">
                                                        Valid (24h Window)
                                                    </Badge>
                                                    {insurance.authorization_hours_remaining !== null && insurance.authorization_hours_remaining !== undefined && (
                                                        <span className="text-xs text-muted-foreground">
                                                            (~{insurance.authorization_hours_remaining}h remaining)
                                                        </span>
                                                    )}
                                                </div>
                                            ) : (
                                                <Badge variant="destructive">
                                                    {insurance.verification_status === 'failed' ? 'Verification Failed' : 'Expired (>24 Hours)'}
                                                </Badge>
                                            )}
                                        </dd>
                                    </div>
                                </>
                            )}
                            {insurance.nhif_eligibility_status && (
                                <div className="flex justify-between gap-4">
                                    <dt className="text-muted-foreground">NHIF Eligibility</dt>
                                    <dd>
                                        <Badge
                                            variant={nhifEligVariant(
                                                insurance.nhif_eligibility_status as NhifEligibilityStatus,
                                            )}
                                        >
                                            {nhifEligStatusLabel[insurance.nhif_eligibility_status as NhifEligibilityStatus]}
                                        </Badge>
                                        {insurance.nhif_coverage_percent !== null && insurance.nhif_eligibility_status === 'eligible' && (
                                            <span className="text-muted-foreground ml-2 text-xs">
                                                ({insurance.nhif_coverage_percent}% covered)
                                            </span>
                                        )}
                                    </dd>
                                </div>
                            )}
                        </dl>
                    </CardContent>
                </Card>

                {insurance.latest_nhif_eligibility && (
                    <NhifEligibilityCard eligibility={insurance.latest_nhif_eligibility} />
                )}

                {insurance.latest_nhif_verification && (
                    <NhifVerificationCard
                        verification={insurance.latest_nhif_verification}
                        patient={patient}
                        insurance={insurance}
                    />
                )}

                <NhifVerificationDialog
                    open={verificationDialogOpen}
                    onOpenChange={setVerificationDialogOpen}
                    patient={patient as any}
                    insurance={insurance}
                    latestVerification={insurance.latest_nhif_verification}
                />
            </div>
        </AppLayout>
    );
}
