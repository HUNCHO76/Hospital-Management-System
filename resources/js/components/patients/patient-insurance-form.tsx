import InputError from '@/components/input-error';
import { DuplicateInsuranceWarning } from '@/components/patients/duplicate-insurance-warning';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { type InsuranceProviderSummary } from '@/types/insurance-provider';
import {
    type PatientInsurance,
    type PatientInsuranceFormData,
    type InsuranceSchemeSummary,
    type PossibleDuplicatePatientInsurance,
} from '@/types/patient-insurance';
import { Link, useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { type FormEventHandler, useMemo } from 'react';

interface PatientInsuranceFormProps {
    patientId: number;
    insurance?: PatientInsurance;
    providers: InsuranceProviderSummary[];
    schemes: InsuranceSchemeSummary[];
    possibleDuplicates?: PossibleDuplicatePatientInsurance[];
    old?: Partial<PatientInsuranceFormData>;
}

export function PatientInsuranceForm({ patientId, insurance, providers, schemes, possibleDuplicates = [], old = {} }: PatientInsuranceFormProps) {
    const isEditing = Boolean(insurance);

    const { data, setData, post, put, processing, errors } = useForm<PatientInsuranceFormData>({
        insurance_provider_id: insurance ? String(insurance.insurance_provider_id) : (old.insurance_provider_id ?? ''),
        insurance_scheme_id: insurance?.insurance_scheme_id ? String(insurance.insurance_scheme_id) : (old.insurance_scheme_id ?? ''),
        membership_number: insurance?.membership_number ?? old.membership_number ?? '',
        policy_number: insurance?.policy_number ?? old.policy_number ?? '',
        card_number: insurance?.card_number ?? old.card_number ?? '',
        principal_member_name: insurance?.principal_member_name ?? old.principal_member_name ?? '',
        relationship: insurance?.relationship ?? old.relationship ?? '',
        effective_from: insurance?.effective_from ?? old.effective_from ?? '',
        effective_to: insurance?.effective_to ?? old.effective_to ?? '',
        is_primary: insurance?.is_primary ?? false,
        status: insurance?.status ?? 'active',
        verification_status: insurance?.verification_status ?? 'pending',
        confirm_duplicate: false,
    });

    const schemesForProvider = useMemo(
        () => schemes.filter((scheme) => String(scheme.insurance_provider_id) === data.insurance_provider_id),
        [schemes, data.insurance_provider_id],
    );

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        if (insurance) {
            put(route('patients.insurance.update', [patientId, insurance.id]));
        } else {
            post(route('patients.insurance.store', patientId));
        }
    };

    const continueRegistration = () => {
        setData('confirm_duplicate', true);
        post(route('patients.insurance.store', patientId));
    };

    return (
        <form onSubmit={submit} className="space-y-6">
            {!isEditing && (
                <DuplicateInsuranceWarning duplicates={possibleDuplicates} onContinue={continueRegistration} processing={processing} />
            )}

            <div className="grid gap-4 sm:grid-cols-2">
                <div className="grid gap-2">
                    <Label htmlFor="insurance_provider_id">Insurance Provider *</Label>
                    <Select
                        value={data.insurance_provider_id}
                        onValueChange={(value) => {
                            setData('insurance_provider_id', value);
                            setData('insurance_scheme_id', '');
                        }}
                    >
                        <SelectTrigger id="insurance_provider_id">
                            <SelectValue placeholder="Select a provider" />
                        </SelectTrigger>
                        <SelectContent>
                            {providers.map((provider) => (
                                <SelectItem key={provider.id} value={String(provider.id)}>
                                    {provider.name} ({provider.code})
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <InputError message={errors.insurance_provider_id} />
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="insurance_scheme_id">Insurance Scheme</Label>
                    <Select
                        value={data.insurance_scheme_id}
                        onValueChange={(value) => setData('insurance_scheme_id', value)}
                        disabled={!data.insurance_provider_id}
                    >
                        <SelectTrigger id="insurance_scheme_id">
                            <SelectValue placeholder={data.insurance_provider_id ? 'Select a scheme' : 'Select a provider first'} />
                        </SelectTrigger>
                        <SelectContent>
                            {schemesForProvider.map((scheme) => (
                                <SelectItem key={scheme.id} value={String(scheme.id)}>
                                    {scheme.name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <InputError message={errors.insurance_scheme_id} />
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="membership_number">Membership Number *</Label>
                    <Input
                        id="membership_number"
                        value={data.membership_number}
                        onChange={(e) => setData('membership_number', e.target.value)}
                        required
                    />
                    <InputError message={errors.membership_number} />
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="policy_number">Policy Number</Label>
                    <Input id="policy_number" value={data.policy_number} onChange={(e) => setData('policy_number', e.target.value)} />
                    <InputError message={errors.policy_number} />
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="card_number">Card Number</Label>
                    <Input id="card_number" value={data.card_number} onChange={(e) => setData('card_number', e.target.value)} />
                    <InputError message={errors.card_number} />
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="principal_member_name">Principal Member Name</Label>
                    <Input
                        id="principal_member_name"
                        value={data.principal_member_name}
                        onChange={(e) => setData('principal_member_name', e.target.value)}
                        placeholder="If different from the patient"
                    />
                    <InputError message={errors.principal_member_name} />
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="relationship">Relationship to Principal Member</Label>
                    <Select
                        value={data.relationship || 'unspecified'}
                        onValueChange={(value) =>
                            setData('relationship', value === 'unspecified' ? '' : (value as PatientInsuranceFormData['relationship']))
                        }
                    >
                        <SelectTrigger id="relationship">
                            <SelectValue placeholder="Select relationship" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="unspecified">Not specified</SelectItem>
                            <SelectItem value="self">Self</SelectItem>
                            <SelectItem value="spouse">Spouse</SelectItem>
                            <SelectItem value="child">Child</SelectItem>
                            <SelectItem value="parent">Parent</SelectItem>
                            <SelectItem value="other">Other</SelectItem>
                        </SelectContent>
                    </Select>
                    <InputError message={errors.relationship} />
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="effective_from">Effective From</Label>
                    <Input
                        id="effective_from"
                        type="date"
                        value={data.effective_from}
                        onChange={(e) => setData('effective_from', e.target.value)}
                    />
                    <InputError message={errors.effective_from} />
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="effective_to">Effective To</Label>
                    <Input id="effective_to" type="date" value={data.effective_to} onChange={(e) => setData('effective_to', e.target.value)} />
                    <InputError message={errors.effective_to} />
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="status">Status *</Label>
                    <Select value={data.status} onValueChange={(value) => setData('status', value as PatientInsuranceFormData['status'])}>
                        <SelectTrigger id="status">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="active">Active</SelectItem>
                            <SelectItem value="inactive">Inactive</SelectItem>
                            <SelectItem value="expired">Expired</SelectItem>
                            <SelectItem value="suspended">Suspended</SelectItem>
                        </SelectContent>
                    </Select>
                    <InputError message={errors.status} />
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="verification_status">Verification Status *</Label>
                    <Select
                        value={data.verification_status}
                        onValueChange={(value) => setData('verification_status', value as PatientInsuranceFormData['verification_status'])}
                    >
                        <SelectTrigger id="verification_status">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="pending">Pending</SelectItem>
                            <SelectItem value="verified">Verified</SelectItem>
                            <SelectItem value="failed">Failed</SelectItem>
                            <SelectItem value="expired">Expired</SelectItem>
                        </SelectContent>
                    </Select>
                    <InputError message={errors.verification_status} />
                </div>
            </div>

            <div className="flex items-center gap-3">
                <Checkbox id="is_primary" checked={data.is_primary} onCheckedChange={(checked) => setData('is_primary', checked === true)} />
                <Label htmlFor="is_primary">Set as primary insurance</Label>
            </div>

            <div className="flex justify-end gap-2">
                <Button type="button" variant="outline" asChild>
                    <Link href={route('patients.show', patientId)}>Cancel</Link>
                </Button>
                <Button type="submit" disabled={processing}>
                    {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                    {isEditing ? 'Save changes' : 'Add insurance'}
                </Button>
            </div>
        </form>
    );
}
