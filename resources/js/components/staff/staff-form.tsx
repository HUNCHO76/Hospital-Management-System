import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { type DepartmentHospital } from '@/types/department';
import { type Staff, type StaffFormData } from '@/types/staff';
import { Link, useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { type FormEventHandler, useMemo } from 'react';

interface StaffFormDepartment {
    id: number;
    hospital_id: number;
    name: string;
    code: string;
}

export interface StaffAccountInfo {
    id: number;
    username: string;
    email: string;
    phone: string | null;
    status: string;
    role?: string;
}

interface StaffFormProps {
    staff?: Staff;
    hospitals: DepartmentHospital[];
    departments: StaffFormDepartment[];
    roles?: string[];
    employmentTypes: string[];
    account?: StaffAccountInfo | null;
}

export function StaffForm({ staff, hospitals, departments, roles, employmentTypes, account }: StaffFormProps) {
    const isEditing = Boolean(staff);

    const { data, setData, post, put, processing, errors } = useForm<StaffFormData>({
        hospital_id: staff ? String(staff.hospital_id) : '',
        department_id: staff?.department_id ? String(staff.department_id) : '',
        first_name: staff?.first_name ?? '',
        middle_name: staff?.middle_name ?? '',
        last_name: staff?.last_name ?? '',
        gender: staff?.gender ?? '',
        date_of_birth: staff?.date_of_birth ?? '',
        phone: staff?.phone ?? '',
        email: staff?.email ?? '',
        professional_registration_no: staff?.professional_registration_no ?? '',
        job_title: staff?.job_title ?? '',
        employment_type: staff?.employment_type ?? '',
        status: staff?.status ?? 'active',
        hired_at: staff?.hired_at ?? '',
        create_account: false,
        password: '',
        role: account?.role ?? '',
        user_status: account?.status ?? 'active',
    });

    const departmentsForHospital = useMemo(
        () => departments.filter((department) => String(department.hospital_id) === data.hospital_id),
        [departments, data.hospital_id],
    );

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        if (staff) {
            put(route('staff.update', staff.id));
        } else {
            post(route('staff.store'));
        }
    };

    return (
        <form onSubmit={submit} className="space-y-6">
            <div className="grid gap-4 sm:grid-cols-2">
                <div className="grid gap-2">
                    <Label htmlFor="hospital_id">Hospital *</Label>
                    <Select
                        value={data.hospital_id}
                        onValueChange={(value) => {
                            setData('hospital_id', value);
                            setData('department_id', '');
                        }}
                    >
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
                    <Label htmlFor="department_id">Department</Label>
                    <Select
                        value={data.department_id}
                        onValueChange={(value) => setData('department_id', value)}
                        disabled={!data.hospital_id}
                    >
                        <SelectTrigger id="department_id">
                            <SelectValue placeholder={data.hospital_id ? 'Select a department' : 'Select a hospital first'} />
                        </SelectTrigger>
                        <SelectContent>
                            {departmentsForHospital.map((department) => (
                                <SelectItem key={department.id} value={String(department.id)}>
                                    {department.name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <InputError message={errors.department_id} />
                </div>

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
                    <Select value={data.gender} onValueChange={(value) => setData('gender', value as StaffFormData['gender'])}>
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
                    <Label htmlFor="phone">Phone</Label>
                    <Input id="phone" value={data.phone} onChange={(e) => setData('phone', e.target.value)} />
                    <InputError message={errors.phone} />
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="email">Email</Label>
                    <Input id="email" type="email" value={data.email} onChange={(e) => setData('email', e.target.value)} />
                    <InputError message={errors.email} />
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="job_title">Job Title *</Label>
                    <Input id="job_title" value={data.job_title} onChange={(e) => setData('job_title', e.target.value)} required />
                    <InputError message={errors.job_title} />
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="employment_type">Employment Type *</Label>
                    <Select value={data.employment_type} onValueChange={(value) => setData('employment_type', value)}>
                        <SelectTrigger id="employment_type">
                            <SelectValue placeholder="Select type" />
                        </SelectTrigger>
                        <SelectContent>
                            {employmentTypes.map((type) => (
                                <SelectItem key={type} value={type}>
                                    {type.replace('_', ' ')}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <InputError message={errors.employment_type} />
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="professional_registration_no">Professional Registration Number</Label>
                    <Input
                        id="professional_registration_no"
                        value={data.professional_registration_no}
                        onChange={(e) => setData('professional_registration_no', e.target.value)}
                    />
                    <InputError message={errors.professional_registration_no} />
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="status">Status *</Label>
                    <Select value={data.status} onValueChange={(value) => setData('status', value as StaffFormData['status'])}>
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
                    <Label htmlFor="hired_at">Hired At</Label>
                    <Input id="hired_at" type="date" value={data.hired_at} onChange={(e) => setData('hired_at', e.target.value)} />
                    <InputError message={errors.hired_at} />
                </div>
            </div>

            {!isEditing && roles && (
                <div className="space-y-4 rounded-lg border p-4">
                    <div className="flex items-center gap-3">
                        <Checkbox
                            id="create_account"
                            checked={data.create_account}
                            onCheckedChange={(checked) => setData('create_account', checked === true)}
                        />
                        <Label htmlFor="create_account">Create a system login account for this staff member</Label>
                    </div>

                    {data.create_account && (
                        <div className="grid gap-4 sm:grid-cols-2">
                            <p className="text-muted-foreground text-sm sm:col-span-2">
                                The account's login email will be the email address entered above.
                            </p>

                            <div className="grid gap-2">
                                <Label htmlFor="password">Password *</Label>
                                <Input
                                    id="password"
                                    type="password"
                                    value={data.password}
                                    onChange={(e) => setData('password', e.target.value)}
                                    required={data.create_account}
                                />
                                <InputError message={errors.password} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="role">Role *</Label>
                                <Select value={data.role} onValueChange={(value) => setData('role', value)}>
                                    <SelectTrigger id="role">
                                        <SelectValue placeholder="Select a role" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {roles.map((role) => (
                                            <SelectItem key={role} value={role}>
                                                {role}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <InputError message={errors.role} />
                            </div>
                        </div>
                    )}
                </div>
            )}

            {isEditing && roles && (staff?.user_id || account) && (
                <div className="space-y-4 rounded-lg border p-4">
                    <div>
                        <h3 className="text-sm font-semibold text-foreground">User Login Account & Role</h3>
                        <p className="text-xs text-muted-foreground">
                            Account: <span className="font-medium text-foreground">{account?.username ?? staff?.user?.UserName ?? 'Connected'}</span> ({account?.email ?? staff?.email})
                        </p>
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="role">Role</Label>
                            <Select value={data.role} onValueChange={(value) => setData('role', value)}>
                                <SelectTrigger id="role">
                                    <SelectValue placeholder="Select a role" />
                                </SelectTrigger>
                                <SelectContent>
                                    {roles.map((role) => (
                                        <SelectItem key={role} value={role}>
                                            {role}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={errors.role} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="user_status">Account Status</Label>
                            <Select value={data.user_status} onValueChange={(value) => setData('user_status', value)}>
                                <SelectTrigger id="user_status">
                                    <SelectValue placeholder="Select account status" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="active">Active</SelectItem>
                                    <SelectItem value="inactive">Inactive</SelectItem>
                                    <SelectItem value="suspended">Suspended</SelectItem>
                                </SelectContent>
                            </Select>
                            <InputError message={errors.user_status} />
                        </div>

                        <div className="grid gap-2 sm:col-span-2">
                            <Label htmlFor="password">Change Password (leave blank to keep existing)</Label>
                            <Input
                                id="password"
                                type="password"
                                value={data.password}
                                placeholder="Enter new password if updating"
                                onChange={(e) => setData('password', e.target.value)}
                            />
                            <InputError message={errors.password} />
                        </div>
                    </div>
                </div>
            )}

            {isEditing && roles && !staff?.user_id && !account && (
                <div className="space-y-4 rounded-lg border p-4">
                    <div className="flex items-center gap-3">
                        <Checkbox
                            id="create_account"
                            checked={data.create_account}
                            onCheckedChange={(checked) => setData('create_account', checked === true)}
                        />
                        <Label htmlFor="create_account">Create a system login account for this staff member</Label>
                    </div>

                    {data.create_account && (
                        <div className="grid gap-4 sm:grid-cols-2">
                            <p className="text-muted-foreground text-sm sm:col-span-2">
                                The account's login email will be the email address entered above.
                            </p>

                            <div className="grid gap-2">
                                <Label htmlFor="password">Password *</Label>
                                <Input
                                    id="password"
                                    type="password"
                                    value={data.password}
                                    onChange={(e) => setData('password', e.target.value)}
                                    required={data.create_account}
                                />
                                <InputError message={errors.password} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="role">Role *</Label>
                                <Select value={data.role} onValueChange={(value) => setData('role', value)}>
                                    <SelectTrigger id="role">
                                        <SelectValue placeholder="Select a role" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {roles.map((role) => (
                                            <SelectItem key={role} value={role}>
                                                {role}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <InputError message={errors.role} />
                            </div>
                        </div>
                    )}
                </div>
            )}

            <div className="flex justify-end gap-2">
                <Button type="button" variant="outline" asChild>
                    <Link href={route('staff.index')}>Cancel</Link>
                </Button>
                <Button type="submit" disabled={processing}>
                    {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                    {isEditing ? 'Save changes' : 'Add staff member'}
                </Button>
            </div>
        </form>
    );
}
