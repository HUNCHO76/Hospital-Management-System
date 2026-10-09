import { router } from '@inertiajs/react';
import { AlertCircle, CheckCircle2, Loader2, ShieldCheck } from 'lucide-react';
import React, { useEffect, useState } from 'react';

import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import type { Patient } from '@/types/patient';
import type {
    NhifCardType,
    NhifMemberVerification,
    NhifVerifier,
    NhifVisitType,
    PatientInsurance,
} from '@/types/patient-insurance';

interface NhifVerificationDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    patient: Patient;
    insurance: PatientInsurance;
    latestVerification?: NhifMemberVerification | null;
}

function readXsrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : '';
}

function getVerifierId(v: NhifVerifier): string {
    return v.verifierID ?? v.VerifierID ?? '';
}

function getVerifierName(v: NhifVerifier): string {
    return v.verifierName ?? v.VerifierName ?? getVerifierId(v);
}

function getCardTypeId(ct: NhifCardType): string {
    return ct.cardTypeID ?? ct.CardTypeID ?? '';
}

function getCardTypeName(ct: NhifCardType): string {
    return ct.cardTypeName ?? ct.CardTypeName ?? getCardTypeId(ct);
}

function getVisitTypeId(vt: NhifVisitType): string {
    return String(vt.visitTypeID ?? vt.VisitTypeID ?? '');
}

function getVisitTypeName(vt: NhifVisitType): string {
    return vt.visitTypeName ?? vt.VisitTypeName ?? getVisitTypeId(vt);
}

export function NhifVerificationDialog({
    open,
    onOpenChange,
    patient,
    insurance,
    latestVerification,
}: NhifVerificationDialogProps) {
    const cardNo =
        insurance.card_number ||
        insurance.membership_number ||
        latestVerification?.nhif_card_no ||
        '';
    const patientName = [patient.first_name, patient.middle_name, patient.last_name]
        .filter(Boolean)
        .join(' ');

    const [verifiers, setVerifiers] = useState<NhifVerifier[]>([]);
    const [visitTypes, setVisitTypes] = useState<NhifVisitType[]>([]);
    const [loadingConfig, setLoadingConfig] = useState(false);
    const [configError, setConfigError] = useState<string | null>(null);

    const [selectedVerifierId, setSelectedVerifierId] = useState<string>('');
    const [selectedCardTypeId, setSelectedCardTypeId] = useState<string>('');
    const [selectedVisitTypeId, setSelectedVisitTypeId] = useState<string>('1');
    const [remarks, setRemarks] = useState<string>('');
    const [referralNo, setReferralNo] = useState<string>('');
    const [submitting, setSubmitting] = useState(false);
    const [formError, setFormError] = useState<string | null>(null);

    // Prior successful request values
    const priorReq = (latestVerification?.verify_card_request ?? {}) as Record<string, any>;

    // Fetch verifiers & visit types whenever dialog is opened
    useEffect(() => {
        if (!open) {
            return;
        }

        let isMounted = true;
        setLoadingConfig(true);
        setConfigError(null);
        setFormError(null);

        const fetchConfig = async () => {
            try {
                const [verifiersRes, visitTypesRes] = await Promise.all([
                    fetch(route('patients.nhif-verifiers'), {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            Accept: 'application/json',
                            'X-XSRF-TOKEN': readXsrfToken(),
                        },
                        body: JSON.stringify({ card_no: cardNo || patient.national_id || '' }),
                    }),
                    fetch(route('patients.nhif-visit-types'), {
                        headers: {
                            Accept: 'application/json',
                            'X-XSRF-TOKEN': readXsrfToken(),
                        },
                    }),
                ]);

                if (!isMounted) return;

                const verifiersData = verifiersRes.ok ? await verifiersRes.json() : null;
                const visitTypesData = visitTypesRes.ok ? await visitTypesRes.json() : null;

                const loadedVerifiers: NhifVerifier[] = verifiersData?.data ?? [];
                const loadedVisitTypes: NhifVisitType[] = visitTypesData?.data ?? [];

                setVerifiers(loadedVerifiers);
                setVisitTypes(loadedVisitTypes);

                // Initial verifier selection
                const prevVerifierId = priorReq.verifierID;
                const matchedVerifier =
                    loadedVerifiers.find((v) => getVerifierId(v) === prevVerifierId) ||
                    loadedVerifiers.find((v) => getVerifierId(v).toUpperCase() === 'NHIF') ||
                    loadedVerifiers[0];

                const initialVerifierId = matchedVerifier ? getVerifierId(matchedVerifier) : (prevVerifierId || 'NHIF');
                setSelectedVerifierId(initialVerifierId);

                // Initial card type selection
                const cardTypes = matchedVerifier?.cardTypes ?? matchedVerifier?.CardTypes ?? [];
                const prevCardTypeId = priorReq.cardTypeID;
                const matchedCardType =
                    cardTypes.find((ct) => getCardTypeId(ct) === prevCardTypeId) ||
                    cardTypes[0];

                const initialCardTypeId = matchedCardType ? getCardTypeId(matchedCardType) : (prevCardTypeId || '100000001');
                setSelectedCardTypeId(initialCardTypeId);

                // Initial visit type selection
                const prevVisitTypeId = String(priorReq.visitTypeID ?? '1');
                setSelectedVisitTypeId(prevVisitTypeId);

                // Remarks & Referral
                if (priorReq.remarks) {
                    setRemarks(String(priorReq.remarks));
                }
                if (priorReq.referralNo && priorReq.referralNo !== 'NULL') {
                    setReferralNo(String(priorReq.referralNo));
                }
            } catch (err) {
                if (isMounted) {
                    setConfigError('Could not load NHIF configuration. Default options will be used.');
                    setSelectedVerifierId(priorReq.verifierID || 'NHIF');
                    setSelectedCardTypeId(priorReq.cardTypeID || '100000001');
                    setSelectedVisitTypeId('1');
                }
            } finally {
                if (isMounted) {
                    setLoadingConfig(false);
                }
            }
        };

        void fetchConfig();

        return () => {
            isMounted = false;
        };
    }, [open, cardNo]);

    // Update card types list when verifier changes
    const currentVerifier = verifiers.find((v) => getVerifierId(v) === selectedVerifierId);
    const availableCardTypes = currentVerifier?.cardTypes ?? currentVerifier?.CardTypes ?? [];

    const handleVerifierChange = (verifierId: string) => {
        setSelectedVerifierId(verifierId);
        const v = verifiers.find((item) => getVerifierId(item) === verifierId);
        const types = v?.cardTypes ?? v?.CardTypes ?? [];
        if (types.length > 0) {
            setSelectedCardTypeId(getCardTypeId(types[0]));
        } else {
            setSelectedCardTypeId('100000001');
        }
    };

    // Determine requirements of selected visit type
    const currentVisitType = visitTypes.find(
        (vt) => getVisitTypeId(vt) === selectedVisitTypeId,
    );
    const requiresRemarks = Boolean(
        currentVisitType?.RequiresRemarks ?? currentVisitType?.requiresRemarks,
    );
    const requiresReferral = Boolean(
        currentVisitType?.RequiresReferralNo ?? currentVisitType?.requiresReferralNo,
    );

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        setFormError(null);

        if (!selectedCardTypeId) {
            setFormError('Please select a Card Type ID.');
            return;
        }

        if (requiresRemarks && !remarks.trim()) {
            setFormError('Remarks are required for this visit type (e.g. emergency condition description).');
            return;
        }

        if (requiresReferral && !referralNo.trim()) {
            setFormError('A referral number is required for this visit type.');
            return;
        }

        setSubmitting(true);

        const identifierType = cardNo ? 'CardNo' : 'nin';
        const identifierValue = cardNo || patient.national_id || '';

        router.post(
            route('patients.insurance.nhif-verify', [patient.id, insurance.id]),
            {
                identifier_type: identifierType,
                identifier_value: identifierValue,
                verifier_id: selectedVerifierId || 'NHIF',
                card_type_id: selectedCardTypeId || '100000001',
                visit_type_id: parseInt(selectedVisitTypeId, 10) || 1,
                remarks: remarks.trim() || null,
                referral_no: referralNo.trim() || null,
                biometric_method: 'NONE',
            },
            {
                onSuccess: () => {
                    onOpenChange(false);
                },
                onError: (err) => {
                    const firstErr = Object.values(err)[0];
                    setFormError(firstErr ? String(firstErr) : 'Verification failed. Please review the inputs.');
                },
                onFinish: () => {
                    setSubmitting(false);
                },
            },
        );
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-w-md sm:max-w-lg">
                <form onSubmit={handleSubmit}>
                    <DialogHeader>
                        <DialogTitle className="flex items-center gap-2">
                            <ShieldCheck className="h-5 w-5 text-primary" />
                            Verify NHIF Card
                        </DialogTitle>
                        <DialogDescription>
                            Configure the verification request payload for {patientName}.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="space-y-4 py-4">
                        {/* Member & Card Summary */}
                        <div className="rounded-lg border bg-muted/40 p-3 text-sm space-y-1">
                            <div className="flex justify-between">
                                <span className="text-muted-foreground">Card / ID Number:</span>
                                <span className="font-mono font-medium">{cardNo || patient.national_id || 'N/A'}</span>
                            </div>
                            <div className="flex justify-between">
                                <span className="text-muted-foreground">Patient:</span>
                                <span className="font-medium">{patientName}</span>
                            </div>
                        </div>

                        {loadingConfig && (
                            <div className="flex items-center justify-center gap-2 py-4 text-sm text-muted-foreground">
                                <Loader2 className="h-4 w-4 animate-spin" />
                                Loading NHIF verifiers and visit types…
                            </div>
                        )}

                        {configError && (
                            <Alert variant="destructive">
                                <AlertCircle className="h-4 w-4" />
                                <AlertDescription>{configError}</AlertDescription>
                            </Alert>
                        )}

                        {formError && (
                            <Alert variant="destructive">
                                <AlertCircle className="h-4 w-4" />
                                <AlertDescription>{formError}</AlertDescription>
                            </Alert>
                        )}

                        {!loadingConfig && (
                            <div className="space-y-4">
                                {/* Verifier Selector */}
                                <div className="space-y-1.5">
                                    <Label htmlFor="verifier_id">Verifier *</Label>
                                    <Select
                                        value={selectedVerifierId}
                                        onValueChange={handleVerifierChange}
                                    >
                                        <SelectTrigger id="verifier_id">
                                            <SelectValue placeholder="Select Verifier" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {verifiers.length > 0 ? (
                                                verifiers.map((v) => (
                                                    <SelectItem key={getVerifierId(v)} value={getVerifierId(v)}>
                                                        {getVerifierName(v)} ({getVerifierId(v)})
                                                    </SelectItem>
                                                ))
                                            ) : (
                                                <>
                                                    <SelectItem value="NHIF">National Health Insurance Fund (NHIF)</SelectItem>
                                                    <SelectItem value="ZHSF">Zanzibar Health Services Fund (ZHSF)</SelectItem>
                                                </>
                                            )}
                                        </SelectContent>
                                    </Select>
                                </div>

                                {/* Card Type Selector */}
                                <div className="space-y-1.5">
                                    <Label htmlFor="card_type_id">Card Type ID *</Label>
                                    <Select
                                        value={selectedCardTypeId}
                                        onValueChange={setSelectedCardTypeId}
                                    >
                                        <SelectTrigger id="card_type_id">
                                            <SelectValue placeholder="Select Card Type" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {availableCardTypes.length > 0 ? (
                                                availableCardTypes.map((ct) => (
                                                    <SelectItem key={getCardTypeId(ct)} value={getCardTypeId(ct)}>
                                                        {getCardTypeName(ct)} ({getCardTypeId(ct)})
                                                    </SelectItem>
                                                ))
                                            ) : (
                                                <>
                                                    <SelectItem value="100000001">100000001 (ZAN / Standard)</SelectItem>
                                                    <SelectItem value="NHIFCard">NHIFCard (NHIF Beneficiary Card)</SelectItem>
                                                    <SelectItem value="NationalID">NationalID (NIDA)</SelectItem>
                                                </>
                                            )}
                                        </SelectContent>
                                    </Select>
                                </div>

                                {/* Visit Type Selector */}
                                <div className="space-y-1.5">
                                    <Label htmlFor="visit_type_id">Visit Type *</Label>
                                    <Select
                                        value={selectedVisitTypeId}
                                        onValueChange={setSelectedVisitTypeId}
                                    >
                                        <SelectTrigger id="visit_type_id">
                                            <SelectValue placeholder="Select Visit Type" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {visitTypes.length > 0 ? (
                                                visitTypes.map((vt) => (
                                                    <SelectItem key={getVisitTypeId(vt)} value={getVisitTypeId(vt)}>
                                                        {getVisitTypeName(vt)}
                                                    </SelectItem>
                                                ))
                                            ) : (
                                                <>
                                                    <SelectItem value="1">Normal Visit</SelectItem>
                                                    <SelectItem value="2">Emergency Visit</SelectItem>
                                                    <SelectItem value="3">Referral Visit</SelectItem>
                                                </>
                                            )}
                                        </SelectContent>
                                    </Select>
                                </div>

                                {/* Remarks (mandatory if Emergency or visit type requires it) */}
                                <div className="space-y-1.5">
                                    <Label htmlFor="remarks">
                                        Remarks {requiresRemarks && <span className="text-destructive">*</span>}
                                    </Label>
                                    <Textarea
                                        id="remarks"
                                        placeholder={
                                            requiresRemarks
                                                ? 'e.g. mgonjwa amekuja akiwa maututi'
                                                : 'Optional clinical notes or remarks'
                                        }
                                        value={remarks}
                                        onChange={(e) => setRemarks(e.target.value)}
                                        rows={2}
                                    />
                                    {requiresRemarks && (
                                        <p className="text-xs text-muted-foreground">
                                            NHIF requires remarks for this visit type.
                                        </p>
                                    )}
                                </div>

                                {/* Referral Number (mandatory if referral visit type) */}
                                {(requiresReferral || selectedVisitTypeId === '3') && (
                                    <div className="space-y-1.5">
                                        <Label htmlFor="referral_no">
                                            Referral Number <span className="text-destructive">*</span>
                                        </Label>
                                        <Input
                                            id="referral_no"
                                            placeholder="Enter NHIF referral number"
                                            value={referralNo}
                                            onChange={(e) => setReferralNo(e.target.value)}
                                        />
                                    </div>
                                )}
                            </div>
                        )}
                    </div>

                    <DialogFooter className="gap-2 sm:gap-0">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => onOpenChange(false)}
                            disabled={submitting}
                        >
                            Cancel
                        </Button>
                        <Button type="submit" disabled={submitting || loadingConfig}>
                            {submitting ? (
                                <>
                                    <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                                    Verifying…
                                </>
                            ) : (
                                <>
                                    <CheckCircle2 className="mr-2 h-4 w-4" />
                                    Send Verification
                                </>
                            )}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
