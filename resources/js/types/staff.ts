import { type DepartmentHospital } from '@/types/department';
import { type Practitioner } from '@/types/practitioner';

export type StaffStatus = 'active' | 'inactive' | 'suspended';
export type StaffGender = 'male' | 'female';

export interface StaffDepartment {
    id: number;
    name: string;
    code: string;
}

export interface StaffUser {
    id: number;
    UserName: string;
    email: string;
}

export interface Staff {
    id: number;
    user_id: number | null;
    hospital_id: number;
    department_id: number | null;
    staff_number: string;
    first_name: string;
    middle_name: string | null;
    last_name: string;
    gender: StaffGender;
    date_of_birth: string | null;
    phone: string | null;
    email: string | null;
    professional_registration_no: string | null;
    job_title: string;
    employment_type: string;
    status: StaffStatus;
    hired_at: string | null;
    hospital?: DepartmentHospital;
    department?: StaffDepartment | null;
    practitioner?: Practitioner | null;
    user?: StaffUser | null;
    created_at: string;
    updated_at: string;
}

export interface StaffFormData {
    hospital_id: string;
    department_id: string;
    first_name: string;
    middle_name: string;
    last_name: string;
    gender: StaffGender | '';
    date_of_birth: string;
    phone: string;
    email: string;
    professional_registration_no: string;
    job_title: string;
    employment_type: string;
    status: StaffStatus;
    hired_at: string;
    create_account: boolean;
    password: string;
    role: string;
    user_status?: string;
    [key: string]: string | boolean | undefined;
}

export interface StaffFilters {
    search: string;
    hospital_id: string;
    department_id: string;
    status: string;
    employment_type: string;
    job_title: string;
    has_practitioner: string;
}
