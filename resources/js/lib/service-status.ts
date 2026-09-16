import { type ServiceCategory, type ServicePriceStatus, type ServiceStatus } from '@/types/service';

export const serviceStatusLabel: Record<ServiceStatus, string> = {
    active: 'Active',
    inactive: 'Inactive',
};

export const serviceStatusVariant: Record<ServiceStatus, 'default' | 'secondary' | 'destructive' | 'outline'> = {
    active: 'default',
    inactive: 'outline',
};

export const serviceCategoryLabel: Record<ServiceCategory, string> = {
    consultation: 'Consultation',
    laboratory: 'Laboratory',
    radiology: 'Radiology',
    pharmacy: 'Pharmacy',
    procedure: 'Procedure',
    emergency: 'Emergency',
    dental: 'Dental',
    maternity: 'Maternity',
    inpatient: 'Inpatient',
    other: 'Other',
};

export const servicePriceStatusLabel: Record<ServicePriceStatus, string> = {
    active: 'Active',
    cancelled: 'Cancelled',
};

export const servicePriceStatusVariant: Record<ServicePriceStatus, 'default' | 'secondary' | 'destructive' | 'outline'> = {
    active: 'default',
    cancelled: 'destructive',
};
