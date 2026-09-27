import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { type ConsultationServiceOption, type Practitioner, type PractitionerFormData, type PractitionerStaff } from '@/types/practitioner';
import { Link, useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { type FormEventHandler } from 'react';

interface PractitionerFormProps {
    practitioner?: Practitioner;
    eligibleStaff?: PractitionerStaff[];
    practitionerTypes: string[];
    services?: ConsultationServiceOption[];
}

export function PractitionerForm({ practitioner, eligibleStaff, practitionerTypes, services }: PractitionerFormProps) {
    const isEditing = Boolean(practitioner);

    const { data, setData, post, put, processing, errors, transform } = useForm<PractitionerFormData>({
        staff_id: practitioner ? String(practitioner.staff_id) : '',
        practitioner_type: practitioner?.practitioner_type ?? '',
        professional_registration_number: practitioner?.professional_registration_number ?? '',
        specialization: practitioner?.specialization ?? '',
        license_number: practitioner?.license_number ?? '',
        license_expiry_date: practitioner?.license_expiry_date ?? '',
        status: practitioner?.status ?? 'active',
        nhif_practitioner_no: practitioner?.nhif_practitioner_no ?? '',
        consultation_service_id: practitioner?.consultation_service_id ? String(practitioner.consultation_service_id) : '',
    });

    transform((data) => ({
        ...data,
        consultation_service_id: data.consultation_service_id === '' ? null : data.consultation_service_id,
    }));

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        if (practitioner) {
            put(route('practitioners.update', practitioner.id));
        } else {
            post(route('practitioners.store'));
        }
    };

    return (
        <form onSubmit={submit} className="space-y-6">
            <div className="grid gap-4 sm:grid-cols-2">
                <div className="grid gap-2 sm:col-span-2">
                    <Label htmlFor="staff_id">Staff *</Label>
                    {isEditing && practitioner?.staff ? (
                        <Input
                            disabled
                            value={`${practitioner.staff.first_name} ${practitioner.staff.last_name} (${practitioner.staff.staff_number})`}
                        />
                    ) : (
                        <Select value={data.staff_id} onValueChange={(value) => setData('staff_id', value)}>
                            <SelectTrigger id="staff_id">
                                <SelectValue placeholder="Select an eligible staff member" />
                            </SelectTrigger>
                            <SelectContent>
                                {eligibleStaff?.map((staff) => (
                                    <SelectItem key={staff.id} value={String(staff.id)}>
                                        {staff.first_name} {staff.last_name} — {staff.job_title} ({staff.staff_number})
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    )}
                    <InputError message={errors.staff_id} />
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="practitioner_type">Practitioner Type *</Label>
                    <Select value={data.practitioner_type} onValueChange={(value) => setData('practitioner_type', value)}>
                        <SelectTrigger id="practitioner_type">
                            <SelectValue placeholder="Select a type" />
                        </SelectTrigger>
                        <SelectContent>
                            {practitionerTypes.map((type) => (
                                <SelectItem key={type} value={type}>
                                    {type.replace('-', ' ')}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <InputError message={errors.practitioner_type} />
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="status">Status *</Label>
                    <Select value={data.status} onValueChange={(value) => setData('status', value as PractitionerFormData['status'])}>
                        <SelectTrigger id="status">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="active">Active</SelectItem>
                            <SelectItem value="inactive">Inactive</SelectItem>
                            <SelectItem value="suspended">Suspended</SelectItem>
                        </SelectContent>
                    </Select>
                    <InputError message={errors.status} />
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="professional_registration_number">Professional Registration Number</Label>
                    <Input
                        id="professional_registration_number"
                        value={data.professional_registration_number}
                        onChange={(e) => setData('professional_registration_number', e.target.value)}
                    />
                    <InputError message={errors.professional_registration_number} />
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="specialization">Specialization</Label>
                    <Input id="specialization" value={data.specialization} onChange={(e) => setData('specialization', e.target.value)} />
                    <InputError message={errors.specialization} />
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="license_number">License Number</Label>
                    <Input id="license_number" value={data.license_number} onChange={(e) => setData('license_number', e.target.value)} />
                    <InputError message={errors.license_number} />
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="license_expiry_date">License Expiry Date</Label>
                    <Input
                        id="license_expiry_date"
                        type="date"
                        value={data.license_expiry_date}
                        onChange={(e) => setData('license_expiry_date', e.target.value)}
                    />
                    <InputError message={errors.license_expiry_date} />
                </div>

                <div className="grid gap-2 sm:col-span-2">
                    <Label htmlFor="nhif_practitioner_no">NHIF Practitioner Number</Label>
                    <Input
                        id="nhif_practitioner_no"
                        value={data.nhif_practitioner_no}
                        onChange={(e) => setData('nhif_practitioner_no', e.target.value)}
                        placeholder="Leave blank if not yet registered with NHIF"
                    />
                    <InputError message={errors.nhif_practitioner_no} />
                </div>

                <div className="grid gap-2 sm:col-span-2">
                    <Label htmlFor="consultation_service_id">Consultation Service</Label>
                    <Select
                        value={data.consultation_service_id || 'none'}
                        onValueChange={(value) => setData('consultation_service_id', value === 'none' ? '' : value)}
                    >
                        <SelectTrigger id="consultation_service_id">
                            <SelectValue placeholder="Use facility default" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="none">Use facility default</SelectItem>
                            {services?.map((service) => (
                                <SelectItem key={service.id} value={String(service.id)}>
                                    {service.code} — {service.name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <InputError message={errors.consultation_service_id} />
                </div>
            </div>

            <div className="flex justify-end gap-2">
                <Button type="button" variant="outline" asChild>
                    <Link href={route('practitioners.index')}>Cancel</Link>
                </Button>
                <Button type="submit" disabled={processing}>
                    {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                    {isEditing ? 'Save changes' : 'Create practitioner profile'}
                </Button>
            </div>
        </form>
    );
}
