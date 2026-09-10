import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { type Hospital, type HospitalFormData } from '@/types/hospital';
import { Link, useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { type FormEventHandler } from 'react';

interface HospitalFormProps {
    hospital?: Hospital;
}

export function HospitalForm({ hospital }: HospitalFormProps) {
    const isEditing = Boolean(hospital);

    const { data, setData, post, put, processing, errors } = useForm<HospitalFormData>({
        name: hospital?.name ?? '',
        code: hospital?.code ?? '',
        registration_number: hospital?.registration_number ?? '',
        phone: hospital?.phone ?? '',
        email: hospital?.email ?? '',
        address: hospital?.address ?? '',
        region: hospital?.region ?? '',
        district: hospital?.district ?? '',
        ward: hospital?.ward ?? '',
        status: hospital?.status ?? 'active',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        if (hospital) {
            put(route('hospitals.update', hospital.id));
        } else {
            post(route('hospitals.store'));
        }
    };

    return (
        <form onSubmit={submit} className="space-y-6">
            <div className="grid gap-4 sm:grid-cols-2">
                <div className="grid gap-2">
                    <Label htmlFor="name">Hospital Name *</Label>
                    <Input id="name" value={data.name} onChange={(e) => setData('name', e.target.value)} required autoFocus />
                    <InputError message={errors.name} />
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="code">Hospital Code *</Label>
                    <Input id="code" value={data.code} onChange={(e) => setData('code', e.target.value.toUpperCase())} required />
                    <InputError message={errors.code} />
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="registration_number">Registration Number</Label>
                    <Input
                        id="registration_number"
                        value={data.registration_number}
                        onChange={(e) => setData('registration_number', e.target.value)}
                    />
                    <InputError message={errors.registration_number} />
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="status">Status *</Label>
                    <Select value={data.status} onValueChange={(value) => setData('status', value as HospitalFormData['status'])}>
                        <SelectTrigger id="status">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="active">Active</SelectItem>
                            <SelectItem value="inactive">Inactive</SelectItem>
                        </SelectContent>
                    </Select>
                    <InputError message={errors.status} />
                </div>

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

            <div className="grid gap-2">
                <Label htmlFor="address">Address</Label>
                <Textarea id="address" value={data.address} onChange={(e) => setData('address', e.target.value)} rows={2} />
                <InputError message={errors.address} />
            </div>

            <div className="grid gap-4 sm:grid-cols-3">
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

            <div className="flex justify-end gap-2">
                <Button type="button" variant="outline" asChild>
                    <Link href={route('hospitals.index')}>Cancel</Link>
                </Button>
                <Button type="submit" disabled={processing}>
                    {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                    {isEditing ? 'Save changes' : 'Create hospital'}
                </Button>
            </div>
        </form>
    );
}
