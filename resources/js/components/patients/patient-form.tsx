import InputError from '@/components/input-error';
import { DuplicatePatientWarning } from '@/components/patients/duplicate-patient-warning';
import { PatientContactForm } from '@/components/patients/patient-contact-form';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { type Patient, type PatientFormData, type PossibleDuplicatePatient } from '@/types/patient';
import { Link, useForm } from '@inertiajs/react';
import { LoaderCircle, ShieldAlert } from 'lucide-react';
import { type FormEventHandler } from 'react';

interface PatientFormProps {
    patient?: Patient;
    possibleDuplicates?: PossibleDuplicatePatient[];
    confirmedDuplicate?: PossibleDuplicatePatient | null;
    old?: Partial<PatientFormData>;
}

export function PatientForm({ patient, possibleDuplicates = [], confirmedDuplicate = null, old = {} }: PatientFormProps) {
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

            {!isEditing && (
                <DuplicatePatientWarning duplicates={possibleDuplicates} onContinue={continueRegistration} processing={processing} />
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
                        <Input
                            id="date_of_birth"
                            type="date"
                            value={data.date_of_birth}
                            onChange={(e) => setData('date_of_birth', e.target.value)}
                        />
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
                        <Label htmlFor="national_id">National ID</Label>
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

            <div className="flex justify-end gap-2">
                <Button type="button" variant="outline" asChild>
                    <Link href={route('patients.index')}>Cancel</Link>
                </Button>
                <Button type="submit" disabled={processing}>
                    {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                    {isEditing ? 'Save changes' : 'Register Patient'}
                </Button>
            </div>
        </form>
    );
}
