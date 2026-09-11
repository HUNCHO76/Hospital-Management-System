import { RadiologyProcedureForm } from '@/components/radiology/radiology-procedure-form';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import {
    type RadiologyBodySite,
    type RadiologyContrastRequirement,
    type RadiologyContrastType,
    type RadiologyLaterality,
    type RadiologyModality,
    type RadiologyPreparationType,
    type RadiologyProcedureDepartment,
} from '@/types/radiology';
import { Head } from '@inertiajs/react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Radiology', href: '/radiology' },
    { title: 'Catalog', href: '/radiology/catalog' },
    { title: 'Add Procedure', href: '/radiology/catalog/create' },
];

interface RadiologyCatalogCreateProps {
    departments: RadiologyProcedureDepartment[];
    modalities: RadiologyModality[];
    bodySites: RadiologyBodySite[];
    lateralityOptions: RadiologyLaterality[];
    contrastRequirements: RadiologyContrastRequirement[];
    contrastTypes: RadiologyContrastType[];
    preparationTypes: RadiologyPreparationType[];
}

export default function RadiologyCatalogCreate(props: RadiologyCatalogCreateProps) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Add Radiology Procedure" />

            <div className="p-4">
                <Card className="max-w-3xl">
                    <CardHeader>
                        <CardTitle>Add Radiology Procedure</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <RadiologyProcedureForm {...props} />
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
