export type DepartmentStatus = 'active' | 'inactive';

export interface DepartmentHospital {
    id: number;
    name: string;
    code: string;
}

export interface Department {
    id: number;
    hospital_id: number;
    name: string;
    code: string;
    description: string | null;
    status: DepartmentStatus;
    hospital?: DepartmentHospital;
    created_at: string;
    updated_at: string;
}

export interface DepartmentFormData {
    hospital_id: string;
    name: string;
    code: string;
    description: string;
    status: DepartmentStatus;
    [key: string]: string;
}

export interface DepartmentFilters {
    search: string;
    hospital_id: string;
    status: string;
}
