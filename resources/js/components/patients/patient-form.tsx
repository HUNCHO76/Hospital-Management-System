import InputError from '@/components/input-error';
import { DuplicatePatientWarning } from '@/components/patients/duplicate-patient-warning';
import { NHIFMemberPreview } from '@/components/patients/nhif-member-preview';
import { PatientContactForm } from '@/components/patients/patient-contact-form';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Radio, RadioGroup } from '@/components/ui/radio-group';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { type InsuranceProviderSummary } from '@/types/insurance-provider';
import { type Patient, type PatientFormData, type PossibleDuplicatePatient } from '@/types/patient';
import {
    type InsuranceSchemeSummary,
    type NhifCardType,
    type NhifLookupExistingPatient,
    type NhifLookupMember,
    type NhifLookupResponse,
    type NhifSchemeService,
    type NhifSchemeServicesResponse,
    type NhifVerifier,
    type NhifVisitType,
} from '@/types/patient-insurance';
import { Link, useForm } from '@inertiajs/react';
import { CheckCircle, LoaderCircle, ShieldAlert, TriangleAlert } from 'lucide-react';
import { type FormEventHandler, useState } from 'react';

type NhifLookupState =
    | 'idle'
    | 'fetching'
    | 'found'
    | 'duplicate_patient'
    | 'not_found'
    | 'invalid_identifier'
    | 'api_error'
    | 'member_inactive'
    | 'verifiers_loading'
    | 'verification_ready'
    | 'verifiers_error';

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

/** Reads the XSRF-TOKEN cookie Laravel's `web` middleware always sets, for the
 *  one plain fetch() POST this form makes outside Inertia's own request cycle. */
function readXsrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : '';
}

interface PatientFormProps {
    patient?: Patient;
    possibleDuplicates?: PossibleDuplicatePatient[];
    confirmedDuplicate?: PossibleDuplicatePatient | null;
    old?: Partial<PatientFormData>;
    nhifProvider?: InsuranceProviderSummary | null;
    nhifSchemes?: InsuranceSchemeSummary[];
}

export function PatientForm({
    patient,
    possibleDuplicates = [],
    confirmedDuplicate = null,
    old = {},
    nhifProvider = null,
    nhifSchemes = [],
}: PatientFormProps) {
    const isEditing = Boolean(patient);

    const { data, setData, post, put, processing, errors } = useForm<PatientFormData>({
        first_name: patient?.first_name ?? old.first_name ?? '',
        middle_name: patient?.middle_name ?? old.middle_name ?? '',
        last_name: patient?.last_name ?? old.last_name ?? '',
        gender: patient?.gender ?? old.gender ?? '',
        date_of_birth: patient?.date_of_birth ?? old.date_of_birth ?? '',
        phone: patient?.phone ?? old.phone ?? '',
        email: patient?.email ?? old.email ?? '',
        national_id: patient?.national_id ?? old.national_id ?? '',
        passport_number: patient?.passport_number ?? old.passport_number ?? '',
        other_identification: patient?.other_identification ?? old.other_identification ?? '',
        occupation: patient?.occupation ?? old.occupation ?? '',
        marital_status: patient?.marital_status ?? old.marital_status ?? '',
        blood_group: patient?.blood_group ?? old.blood_group ?? '',
        address: patient?.address ?? old.address ?? '',
        region: patient?.region ?? old.region ?? '',
        district: patient?.district ?? old.district ?? '',
        ward: patient?.ward ?? old.ward ?? '',
        status: patient?.status ?? 'active',
        confirm_duplicate: false,
        contacts:
            patient?.contacts?.map((contact) => ({
                id: contact.id,
                name: contact.name,
                relationship: contact.relationship,
                phone: contact.phone,
                alternative_phone: contact.alternative_phone ?? '',
                address: contact.address ?? '',
                is_primary: contact.is_primary,
            })) ??
            old.contacts ??
            [],
        payment_type: old.payment_type ?? 'cash',
        nhif_provider_id: old.nhif_provider_id ?? (nhifProvider ? String(nhifProvider.id) : ''),
        nhif_scheme_id: old.nhif_scheme_id ?? '',
        nhif_membership_number: old.nhif_membership_number ?? '',
        nhif_card_number: old.nhif_card_number ?? '',
        nhif_identifier_type: old.nhif_identifier_type ?? 'nin',
        nhif_visit_type_id: old.nhif_visit_type_id ?? '',
        nhif_verifier_id: old.nhif_verifier_id ?? '',
        nhif_card_type_id: old.nhif_card_type_id ?? '',
        nhif_remarks: old.nhif_remarks ?? '',
        nhif_referral_no: old.nhif_referral_no ?? '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        if (patient) {
            put(route('patients.update', patient.id));
        } else {
            post(route('patients.store'));
        }
    };

    const continueRegistration = () => {
        setData('confirm_duplicate', true);
        post(route('patients.store'));
    };

    const isInsurance = data.payment_type === 'insurance';
    const usingCardNumber = data.nhif_identifier_type === 'CardNo';

    const [lookupState, setLookupState] = useState<NhifLookupState>('idle');
    const [lookupMessage, setLookupMessage] = useState<string | null>(null);
    const [lookupMember, setLookupMember] = useState<NhifLookupMember | null>(null);
    const [lookupExistingPatient, setLookupExistingPatient] = useState<NhifLookupExistingPatient | null>(null);
    const [verifiers, setVerifiers] = useState<NhifVerifier[]>([]);
    const [visitTypes, setVisitTypes] = useState<NhifVisitType[]>([]);
    const [verificationConfigError, setVerificationConfigError] = useState<string | null>(null);
    const [schemeServices, setSchemeServices] = useState<NhifSchemeService[]>([]);
    const [schemeServicesExpanded, setSchemeServicesExpanded] = useState(false);

    const identifierValue = usingCardNumber ? data.nhif_card_number : data.national_id;
    const setIdentifierValue = (value: string) => setData(usingCardNumber ? 'nhif_card_number' : 'national_id', value);

    const selectedVerifier = verifiers.find((v) => getVerifierId(v) === data.nhif_verifier_id) ?? null;
    const cardTypes: NhifCardType[] = selectedVerifier?.cardTypes ?? selectedVerifier?.CardTypes ?? [];
    const selectedVisitType = visitTypes.find((vt) => getVisitTypeId(vt) === data.nhif_visit_type_id) ?? null;
    const requiresRemarks = selectedVisitType?.RequiresRemarks ?? selectedVisitType?.requiresRemarks ?? false;
    const requiresReferralNo = selectedVisitType?.RequiresReferralNo ?? selectedVisitType?.requiresReferralNo ?? false;

    const loadVerificationConfig = async (cardNo: string) => {
        setLookupState('verifiers_loading');
        setVerificationConfigError(null);
        setSchemeServices([]);

        try {
            const schemeId = lookupMember?.nhif_scheme_id ?? null;
            const schemeServicesUrl = schemeId
                ? `${route('patients.nhif-scheme-services')}?scheme_id=${schemeId}`
                : null;

            const [verifiersRes, visitTypesRes] = await Promise.all([
                fetch(route('patients.nhif-verifiers'), {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': readXsrfToken() },
                    body: JSON.stringify({ card_no: cardNo }),
                }),
                fetch(route('patients.nhif-visit-types'), {
                    headers: { Accept: 'application/json', 'X-XSRF-TOKEN': readXsrfToken() },
                }),
            ]);

            if (!verifiersRes.ok || !visitTypesRes.ok) {
                setLookupState('verifiers_error');
                setVerificationConfigError('Failed to load verification configuration from NHIF. Please try again.');
                return;
            }

            const verifiersData = (await verifiersRes.json()) as { data: NhifVerifier[] };
            const visitTypesData = (await visitTypesRes.json()) as { data: NhifVisitType[] };

            setVerifiers(verifiersData.data ?? []);
            setVisitTypes(visitTypesData.data ?? []);

            if (schemeServicesUrl) {
                const schemeServicesRes = await fetch(schemeServicesUrl, {
                    headers: { Accept: 'application/json', 'X-XSRF-TOKEN': readXsrfToken() },
                });
                if (schemeServicesRes.ok) {
                    const servicesData = (await schemeServicesRes.json()) as NhifSchemeServicesResponse;
                    setSchemeServices(servicesData.data ?? []);
                }
            }

            setLookupState('verification_ready');
        } catch {
            setLookupState('verifiers_error');
            setVerificationConfigError('Failed to load verification configuration. Please try again.');
        }
    };

    const fetchNhifDetails = async () => {
        if (!identifierValue.trim()) {
            return;
        }

        setLookupState('fetching');
        setLookupMessage(null);
        setLookupMember(null);
        setLookupExistingPatient(null);
        setVerifiers([]);
        setVisitTypes([]);
        setVerificationConfigError(null);
        setData('nhif_verifier_id', '');
        setData('nhif_card_type_id', '');
        setData('nhif_visit_type_id', '');
        setData('nhif_remarks', '');
        setData('nhif_referral_no', '');

        try {
            const response = await fetch(route('patients.nhif-lookup'), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': readXsrfToken(),
                },
                body: JSON.stringify({
                    identifier_type: data.nhif_identifier_type,
                    identifier_value: identifierValue,
                }),
            });

            if (!response.ok) {
                setLookupState('api_error');
                setLookupMessage('Something went wrong while contacting NHIF. Please try again.');
                return;
            }

            const result = (await response.json()) as NhifLookupResponse;
            setLookupMessage(result.message);
            setLookupMember(result.member);
            setLookupExistingPatient(result.existing_patient);

            if (result.status === 'found' && result.member) {
                const member = result.member;
                if (member.first_name) {
                    setData('first_name', member.first_name);
                }
                if (member.middle_name) {
                    setData('middle_name', member.middle_name);
                }
                if (member.last_name) {
                    setData('last_name', member.last_name);
                }
                if (member.gender) {
                    setData('gender', member.gender);
                }
                if (member.date_of_birth) {
                    setData('date_of_birth', member.date_of_birth);
                }
                if (member.membership_number) {
                    setData('nhif_membership_number', member.membership_number);
                }
                if (member.card_number) {
                    setData('nhif_card_number', member.card_number);
                }

                // Auto-load verifiers and visit types for the VerifyCard step.
                const cardNo = member.card_number ?? identifierValue;
                void loadVerificationConfig(cardNo);
            } else {
                setLookupState(result.status);
            }
        } catch {
            setLookupState('api_error');
            setLookupMessage('Could not reach the NHIF lookup service. Please try again.');
        }
    };

    return (
        <form onSubmit={submit} className="space-y-8">
            {!isEditing && confirmedDuplicate && (
                <Alert variant="destructive">
                    <ShieldAlert className="h-4 w-4" />
                    <AlertTitle>A patient with this identification already exists</AlertTitle>
                    <AlertDescription>
                        This national ID or passport already belongs to{' '}
                        <Link href={route('patients.show', confirmedDuplicate.id)} className="font-medium underline">
                            {confirmedDuplicate.first_name} {confirmedDuplicate.last_name} ({confirmedDuplicate.patient_number})
                        </Link>
                        . Registration is blocked — open the existing record instead of creating a duplicate.
                    </AlertDescription>
                </Alert>
            )}

            {!isEditing && <DuplicatePatientWarning duplicates={possibleDuplicates} onContinue={continueRegistration} processing={processing} />}

            {/* Payment Classification — shown only on registration (not edit) */}
            {!isEditing && (
                <section className="space-y-4">
                    <h3 className="text-sm font-semibold">Payment Classification</h3>
                    <RadioGroup className="flex gap-6">
                        <Radio
                            name="payment_type"
                            value="cash"
                            id="payment_cash"
                            label="Cash / Self-Pay"
                            checked={data.payment_type === 'cash'}
                            onChange={() => setData('payment_type', 'cash')}
                        />
                        {nhifProvider && (
                            <Radio
                                name="payment_type"
                                value="insurance"
                                id="payment_insurance"
                                label="Insurance (NHIF)"
                                checked={data.payment_type === 'insurance'}
                                onChange={() => setData('payment_type', 'insurance')}
                            />
                        )}
                    </RadioGroup>
                    <InputError message={errors.payment_type} />
                </section>
            )}

            {/* NHIF Insurance Section — NHIF-first: choose identifier, fetch member
                details from NHIF, then review the auto-filled result before the
                rest of the form is completed (Phase 8). */}
            {!isEditing && isInsurance && nhifProvider && (
                <section className="space-y-4 rounded-lg border border-blue-200 bg-blue-50 p-4 dark:border-blue-900 dark:bg-blue-950/30">
                    <h3 className="text-sm font-semibold">NHIF Membership Details</h3>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2 sm:col-span-2">
                            <Label>Insurance Provider</Label>
                            <p className="text-sm font-medium">{nhifProvider.name}</p>
                        </div>

                        <div className="grid gap-2 sm:col-span-2">
                            <Label>NHIF Identification Method</Label>
                            <RadioGroup className="flex gap-6">
                                <Radio
                                    name="nhif_identifier_type"
                                    value="CardNo"
                                    id="identifier_card"
                                    label="NHIF Card Number"
                                    checked={usingCardNumber}
                                    onChange={() => {
                                        setData('nhif_identifier_type', 'CardNo');
                                        setLookupState('idle');
                                    }}
                                />
                                <Radio
                                    name="nhif_identifier_type"
                                    value="nin"
                                    id="identifier_nin"
                                    label="NIN"
                                    checked={data.nhif_identifier_type === 'nin'}
                                    onChange={() => {
                                        setData('nhif_identifier_type', 'nin');
                                        setLookupState('idle');
                                    }}
                                />
                            </RadioGroup>
                            <InputError message={errors.nhif_identifier_type} />
                        </div>

                        <div className="grid gap-2 sm:col-span-2">
                            <Label htmlFor="nhif_identifier_value">{usingCardNumber ? 'NHIF Card Number' : 'NIN'} *</Label>
                            <div className="flex gap-2">
                                <Input
                                    id="nhif_identifier_value"
                                    value={identifierValue}
                                    onChange={(e) => {
                                        setIdentifierValue(e.target.value);
                                        setLookupState('idle');
                                    }}
                                    placeholder={usingCardNumber ? 'e.g. 00060000XXXXXX' : 'e.g. 20000101-XXXXX-XXXXX-X'}
                                />
                                <Button
                                    type="button"
                                    variant="secondary"
                                    onClick={fetchNhifDetails}
                                    disabled={lookupState === 'fetching' || !identifierValue.trim()}
                                >
                                    {lookupState === 'fetching' && <LoaderCircle className="h-4 w-4 animate-spin" />}
                                    Fetch NHIF Details
                                </Button>
                            </div>
                            <InputError message={usingCardNumber ? errors.nhif_card_number : errors.national_id} />
                        </div>

                        {lookupState === 'fetching' && <p className="text-muted-foreground text-sm sm:col-span-2">Fetching NHIF details…</p>}

                        {(lookupState === 'verifiers_loading' || lookupState === 'verification_ready' || lookupState === 'verifiers_error') &&
                            lookupMember && (
                                <div className="sm:col-span-2">
                                    <NHIFMemberPreview member={lookupMember} />
                                </div>
                            )}

                        {lookupState === 'verifiers_loading' && (
                            <div className="sm:col-span-2 flex items-center gap-2 text-sm text-blue-700 dark:text-blue-300">
                                <LoaderCircle className="h-4 w-4 animate-spin" />
                                Loading verification options…
                            </div>
                        )}

                        {lookupState === 'verifiers_error' && (
                            <div className="sm:col-span-2">
                                <Alert variant="destructive">
                                    <TriangleAlert className="h-4 w-4" />
                                    <AlertTitle>Configuration error</AlertTitle>
                                    <AlertDescription>
                                        {verificationConfigError}
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            className="ml-2"
                                            onClick={() => lookupMember && void loadVerificationConfig(lookupMember.card_number ?? identifierValue)}
                                        >
                                            Retry
                                        </Button>
                                    </AlertDescription>
                                </Alert>
                            </div>
                        )}

                        {lookupState === 'verification_ready' && (
                            <div className="sm:col-span-2 space-y-4 rounded-md border border-green-200 bg-green-50 p-4 dark:border-green-900 dark:bg-green-950/30">
                                <div className="flex items-center gap-2 text-sm font-medium text-green-800 dark:text-green-300">
                                    <CheckCircle className="h-4 w-4" />
                                    Member found — select verification options
                                </div>

                                <div className="grid gap-4 sm:grid-cols-2">
                                    {verifiers.length > 0 && (
                                        <div className="grid gap-2">
                                            <Label htmlFor="nhif_verifier_id">Verifier *</Label>
                                            <Select
                                                value={data.nhif_verifier_id}
                                                onValueChange={(value) => {
                                                    setData('nhif_verifier_id', value);
                                                    setData('nhif_card_type_id', '');
                                                }}
                                            >
                                                <SelectTrigger id="nhif_verifier_id">
                                                    <SelectValue placeholder="Select verifier" />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    {verifiers.map((v) => (
                                                        <SelectItem key={getVerifierId(v)} value={getVerifierId(v)}>
                                                            {getVerifierName(v)}
                                                        </SelectItem>
                                                    ))}
                                                </SelectContent>
                                            </Select>
                                            <InputError message={errors.nhif_verifier_id} />
                                        </div>
                                    )}

                                    {cardTypes.length > 0 && (
                                        <div className="grid gap-2">
                                            <Label htmlFor="nhif_card_type_id">Card Type *</Label>
                                            <Select value={data.nhif_card_type_id} onValueChange={(value) => setData('nhif_card_type_id', value)}>
                                                <SelectTrigger id="nhif_card_type_id">
                                                    <SelectValue placeholder="Select card type" />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    {cardTypes.map((ct) => (
                                                        <SelectItem key={getCardTypeId(ct)} value={getCardTypeId(ct)}>
                                                            {getCardTypeName(ct)}
                                                        </SelectItem>
                                                    ))}
                                                </SelectContent>
                                            </Select>
                                            <InputError message={errors.nhif_card_type_id} />
                                        </div>
                                    )}

                                    {visitTypes.length > 0 && (
                                        <div className="grid gap-2">
                                            <Label htmlFor="nhif_visit_type_id">Visit Type *</Label>
                                            <Select
                                                value={data.nhif_visit_type_id}
                                                onValueChange={(value) => {
                                                    setData('nhif_visit_type_id', value);
                                                    setData('nhif_remarks', '');
                                                    setData('nhif_referral_no', '');
                                                }}
                                            >
                                                <SelectTrigger id="nhif_visit_type_id">
                                                    <SelectValue placeholder="Select visit type" />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    {visitTypes.map((vt) => (
                                                        <SelectItem key={getVisitTypeId(vt)} value={getVisitTypeId(vt)}>
                                                            {getVisitTypeName(vt)}
                                                        </SelectItem>
                                                    ))}
                                                </SelectContent>
                                            </Select>
                                            <InputError message={errors.nhif_visit_type_id} />
                                        </div>
                                    )}

                                    {requiresRemarks && (
                                        <div className="grid gap-2 sm:col-span-2">
                                            <Label htmlFor="nhif_remarks">Remarks *</Label>
                                            <Textarea
                                                id="nhif_remarks"
                                                value={data.nhif_remarks}
                                                onChange={(e) => setData('nhif_remarks', e.target.value)}
                                                rows={2}
                                                placeholder="Required for this visit type"
                                            />
                                            <InputError message={errors.nhif_remarks} />
                                        </div>
                                    )}

                                    {requiresReferralNo && (
                                        <div className="grid gap-2 sm:col-span-2">
                                            <Label htmlFor="nhif_referral_no">Referral Number *</Label>
                                            <Input
                                                id="nhif_referral_no"
                                                value={data.nhif_referral_no}
                                                onChange={(e) => setData('nhif_referral_no', e.target.value)}
                                                placeholder="Referral number from referring facility"
                                            />
                                            <InputError message={errors.nhif_referral_no} />
                                        </div>
                                    )}
                                </div>

                                {schemeServices.length > 0 && (
                                    <div className="border-t border-green-200 pt-3 dark:border-green-900">
                                        <button
                                            type="button"
                                            onClick={() => setSchemeServicesExpanded((v) => !v)}
                                            className="flex items-center gap-1 text-xs font-medium text-green-700 dark:text-green-400 hover:underline"
                                        >
                                            {schemeServicesExpanded ? '▲' : '▼'}{' '}
                                            {schemeServicesExpanded ? 'Hide' : 'Show'} covered services ({schemeServices.length})
                                        </button>
                                        {schemeServicesExpanded && (
                                            <div className="mt-2 max-h-64 overflow-y-auto rounded border border-green-200 bg-white p-2 dark:border-green-900 dark:bg-gray-900">
                                                {Object.entries(
                                                    schemeServices.reduce<Record<string, NhifSchemeService[]>>((acc, svc) => {
                                                        const group = svc.type_name ?? 'Other';
                                                        acc[group] = acc[group] ?? [];
                                                        acc[group].push(svc);
                                                        return acc;
                                                    }, {}),
                                                ).map(([typeName, items]) => (
                                                    <div key={typeName} className="mb-2">
                                                        <p className="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{typeName}</p>
                                                        <ul className="mt-1 space-y-0.5">
                                                            {items.map((svc) => (
                                                                <li key={svc.item_code} className="flex justify-between text-xs text-gray-700 dark:text-gray-300">
                                                                    <span>{svc.item_name}</span>
                                                                    {svc.unit_price && <span className="ml-2 text-gray-500">TZS {Number(svc.unit_price).toLocaleString()}</span>}
                                                                </li>
                                                            ))}
                                                        </ul>
                                                    </div>
                                                ))}
                                            </div>
                                        )}
                                    </div>
                                )}
                            </div>
                        )}

                        {lookupState === 'duplicate_patient' && (
                            <div className="sm:col-span-2">
                                <Alert className="border-amber-500/40 bg-amber-50 text-amber-900 dark:border-amber-500/30 dark:bg-amber-950 dark:text-amber-200">
                                    <TriangleAlert className="h-4 w-4" />
                                    <AlertTitle>NHIF member already registered</AlertTitle>
                                    <AlertDescription className="text-current">
                                        {lookupExistingPatient && (
                                            <p className="mb-2">
                                                {[
                                                    lookupExistingPatient.first_name,
                                                    lookupExistingPatient.middle_name,
                                                    lookupExistingPatient.last_name,
                                                ]
                                                    .filter(Boolean)
                                                    .join(' ')}{' '}
                                                — Patient No: {lookupExistingPatient.patient_number}
                                            </p>
                                        )}
                                        {lookupExistingPatient && (
                                            <Button size="sm" variant="outline" asChild>
                                                <Link href={route('patients.show', lookupExistingPatient.id)}>Open Existing Patient</Link>
                                            </Button>
                                        )}
                                    </AlertDescription>
                                </Alert>
                            </div>
                        )}

                        {(lookupState === 'not_found' ||
                            lookupState === 'invalid_identifier' ||
                            lookupState === 'api_error' ||
                            lookupState === 'member_inactive') && (
                            <div className="sm:col-span-2">
                                <Alert variant="destructive">
                                    <TriangleAlert className="h-4 w-4" />
                                    <AlertTitle>Unable to find NHIF member</AlertTitle>
                                    <AlertDescription>{lookupMessage ?? 'Please verify the Card Number/NIN and try again.'}</AlertDescription>
                                </Alert>
                            </div>
                        )}

                        {nhifSchemes.length > 0 && (
                            <div className="grid gap-2">
                                <Label htmlFor="nhif_scheme_id">Scheme</Label>
                                <Select value={data.nhif_scheme_id} onValueChange={(value) => setData('nhif_scheme_id', value)}>
                                    <SelectTrigger id="nhif_scheme_id">
                                        <SelectValue placeholder="Select scheme (optional)" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {nhifSchemes.map((scheme) => (
                                            <SelectItem key={scheme.id} value={String(scheme.id)}>
                                                {scheme.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <InputError message={errors.nhif_scheme_id} />
                            </div>
                        )}

                        <div className="grid gap-2">
                            <Label htmlFor="nhif_membership_number">Membership Number *</Label>
                            <Input
                                id="nhif_membership_number"
                                value={data.nhif_membership_number}
                                onChange={(e) => setData('nhif_membership_number', e.target.value)}
                                placeholder="e.g. 01141XXXXXX"
                            />
                            <InputError message={errors.nhif_membership_number} />
                        </div>
                    </div>
                </section>
            )}

            <section className="space-y-4">
                <h3 className="text-sm font-semibold">Personal Information</h3>
                <div className="grid gap-4 sm:grid-cols-2">
                    <div className="grid gap-2">
                        <Label htmlFor="first_name">First Name *</Label>
                        <Input id="first_name" value={data.first_name} onChange={(e) => setData('first_name', e.target.value)} required autoFocus />
                        <InputError message={errors.first_name} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="middle_name">Middle Name</Label>
                        <Input id="middle_name" value={data.middle_name} onChange={(e) => setData('middle_name', e.target.value)} />
                        <InputError message={errors.middle_name} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="last_name">Last Name *</Label>
                        <Input id="last_name" value={data.last_name} onChange={(e) => setData('last_name', e.target.value)} required />
                        <InputError message={errors.last_name} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="gender">Gender *</Label>
                        <Select value={data.gender} onValueChange={(value) => setData('gender', value as PatientFormData['gender'])}>
                            <SelectTrigger id="gender">
                                <SelectValue placeholder="Select gender" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="male">Male</SelectItem>
                                <SelectItem value="female">Female</SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError message={errors.gender} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="date_of_birth">Date of Birth</Label>
                        <Input id="date_of_birth" type="date" value={data.date_of_birth} onChange={(e) => setData('date_of_birth', e.target.value)} />
                        <InputError message={errors.date_of_birth} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="marital_status">Marital Status</Label>
                        <Select
                            value={data.marital_status || 'unspecified'}
                            onValueChange={(value) =>
                                setData('marital_status', value === 'unspecified' ? '' : (value as PatientFormData['marital_status']))
                            }
                        >
                            <SelectTrigger id="marital_status">
                                <SelectValue placeholder="Select marital status" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="unspecified">Not specified</SelectItem>
                                <SelectItem value="single">Single</SelectItem>
                                <SelectItem value="married">Married</SelectItem>
                                <SelectItem value="divorced">Divorced</SelectItem>
                                <SelectItem value="widowed">Widowed</SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError message={errors.marital_status} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="occupation">Occupation</Label>
                        <Input id="occupation" value={data.occupation} onChange={(e) => setData('occupation', e.target.value)} />
                        <InputError message={errors.occupation} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="blood_group">Blood Group</Label>
                        <Select
                            value={data.blood_group || 'unspecified'}
                            onValueChange={(value) =>
                                setData('blood_group', value === 'unspecified' ? '' : (value as PatientFormData['blood_group']))
                            }
                        >
                            <SelectTrigger id="blood_group">
                                <SelectValue placeholder="Select blood group" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="unspecified">Not specified</SelectItem>
                                {['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'].map((group) => (
                                    <SelectItem key={group} value={group}>
                                        {group}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <InputError message={errors.blood_group} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="status">Status *</Label>
                        <Select value={data.status} onValueChange={(value) => setData('status', value as PatientFormData['status'])}>
                            <SelectTrigger id="status">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="active">Active</SelectItem>
                                <SelectItem value="inactive">Inactive</SelectItem>
                                <SelectItem value="deceased">Deceased</SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError message={errors.status} />
                    </div>
                </div>
            </section>

            <section className="space-y-4">
                <h3 className="text-sm font-semibold">Contact Information</h3>
                <div className="grid gap-4 sm:grid-cols-2">
                    <div className="grid gap-2">
                        <Label htmlFor="phone">Phone</Label>
                        <Input id="phone" value={data.phone} onChange={(e) => setData('phone', e.target.value)} />
                        <InputError message={errors.phone} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="email">Email</Label>
                        <Input id="email" type="email" value={data.email} onChange={(e) => setData('email', e.target.value)} />
                        <InputError message={errors.email} />
                    </div>
                </div>
            </section>

            <section className="space-y-4">
                <h3 className="text-sm font-semibold">Identification</h3>
                <div className="grid gap-4 sm:grid-cols-2">
                    <div className="grid gap-2">
                        <Label htmlFor="national_id">National ID{isInsurance && !usingCardNumber && ' *'}</Label>
                        <Input id="national_id" value={data.national_id} onChange={(e) => setData('national_id', e.target.value)} />
                        <InputError message={errors.national_id} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="passport_number">Passport Number</Label>
                        <Input id="passport_number" value={data.passport_number} onChange={(e) => setData('passport_number', e.target.value)} />
                        <InputError message={errors.passport_number} />
                    </div>
                    <div className="grid gap-2 sm:col-span-2">
                        <Label htmlFor="other_identification">Other Identification</Label>
                        <Input
                            id="other_identification"
                            value={data.other_identification}
                            onChange={(e) => setData('other_identification', e.target.value)}
                        />
                        <InputError message={errors.other_identification} />
                    </div>
                </div>
            </section>

            <section className="space-y-4">
                <h3 className="text-sm font-semibold">Address</h3>
                <div className="grid gap-4 sm:grid-cols-2">
                    <div className="grid gap-2 sm:col-span-2">
                        <Label htmlFor="address">Address</Label>
                        <Textarea id="address" value={data.address} onChange={(e) => setData('address', e.target.value)} rows={2} />
                        <InputError message={errors.address} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="region">Region</Label>
                        <Input id="region" value={data.region} onChange={(e) => setData('region', e.target.value)} />
                        <InputError message={errors.region} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="district">District</Label>
                        <Input id="district" value={data.district} onChange={(e) => setData('district', e.target.value)} />
                        <InputError message={errors.district} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="ward">Ward</Label>
                        <Input id="ward" value={data.ward} onChange={(e) => setData('ward', e.target.value)} />
                        <InputError message={errors.ward} />
                    </div>
                </div>
            </section>

            <section className="space-y-4">
                <h3 className="text-sm font-semibold">Emergency / Next-of-Kin Contacts</h3>
                <PatientContactForm contacts={data.contacts} onChange={(contacts) => setData('contacts', contacts)} errors={errors} />
            </section>

            {errors.nhif_verification && (
                <Alert variant="destructive">
                    <ShieldAlert className="h-4 w-4" />
                    <AlertTitle>NHIF Verification Failed</AlertTitle>
                    <AlertDescription>{errors.nhif_verification}</AlertDescription>
                </Alert>
            )}

            <div className="flex justify-end gap-2">
                <Button type="button" variant="outline" asChild>
                    <Link href={route('patients.index')}>Cancel</Link>
                </Button>
                <Button
                    type="submit"
                    disabled={
                        processing ||
                        (!isEditing &&
                            isInsurance &&
                            (lookupState !== 'verification_ready' || !data.nhif_verifier_id || !data.nhif_visit_type_id))
                    }
                >
                    {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                    {isEditing ? 'Save changes' : 'Register Patient'}
                </Button>
            </div>
        </form>
    );
}
