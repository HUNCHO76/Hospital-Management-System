import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { type Department, type DepartmentFormData, type DepartmentHospital } from '@/types/department';
import { Link, useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { type FormEventHandler } from 'react';

interface DepartmentFormProps {
    department?: Department;
    hospitals: DepartmentHospital[];
}

export function DepartmentForm({ department, hospitals }: DepartmentFormProps) {
    const isEditing = Boolean(department);

    const { data, setData, post, put, processing, errors } = useForm<DepartmentFormData>({
        hospital_id: department ? String(department.hospital_id) : '',
        name: department?.name ?? '',
        code: department?.code ?? '',
        description: department?.description ?? '',
        status: department?.status ?? 'active',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        if (department) {
            put(route('departments.update', department.id));
        } else {
            post(route('departments.store'));
        }
    };

    return (
        <form onSubmit={submit} className="space-y-6">
            <div className="grid gap-4 sm:grid-cols-2">
                <div className="grid gap-2">
                    <Label htmlFor="hospital_id">Hospital *</Label>
                    <Select value={data.hospital_id} onValueChange={(value) => setData('hospital_id', value)}>
                        <SelectTrigger id="hospital_id">
                            <SelectValue placeholder="Select a hospital" />
                        </SelectTrigger>
                        <SelectContent>
                            {hospitals.map((hospital) => (
                                <SelectItem key={hospital.id} value={String(hospital.id)}>
                                    {hospital.name} ({hospital.code})
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <InputError message={errors.hospital_id} />
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="status">Status *</Label>
                    <Select value={data.status} onValueChange={(value) => setData('status', value as DepartmentFormData['status'])}>
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
                    <Label htmlFor="name">Department Name *</Label>
                    <Input id="name" value={data.name} onChange={(e) => setData('name', e.target.value)} required autoFocus />
                    <InputError message={errors.name} />
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="code">Department Code *</Label>
                    <Input id="code" value={data.code} onChange={(e) => setData('code', e.target.value.toUpperCase())} required />
                    <InputError message={errors.code} />
                </div>
            </div>

            <div className="grid gap-2">
                <Label htmlFor="description">Description</Label>
                <Textarea id="description" value={data.description} onChange={(e) => setData('description', e.target.value)} rows={3} />
                <InputError message={errors.description} />
            </div>

            <div className="flex justify-end gap-2">
                <Button type="button" variant="outline" asChild>
                    <Link href={route('departments.index')}>Cancel</Link>
                </Button>
                <Button type="submit" disabled={processing}>
                    {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                    {isEditing ? 'Save changes' : 'Create department'}
                </Button>
            </div>
        </form>
    );
}
