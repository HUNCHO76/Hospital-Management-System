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
    type RadiologyProcedure,
    type RadiologyProcedureDepartment,
} from '@/types/radiology';
import { Head } from '@inertiajs/react';

interface RadiologyCatalogEditProps {
    procedure: RadiologyProcedure;
    isReferenced: boolean;
    departments: RadiologyProcedureDepartment[];
    modalities: RadiologyModality[];
    bodySites: RadiologyBodySite[];
    lateralityOptions: RadiologyLaterality[];
    contrastRequirements: RadiologyContrastRequirement[];
    contrastTypes: RadiologyContrastType[];
    preparationTypes: RadiologyPreparationType[];
}

export default function RadiologyCatalogEdit({ procedure, ...rest }: RadiologyCatalogEditProps) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Radiology', href: '/radiology' },
        { title: 'Catalog', href: '/radiology/catalog' },
        { title: procedure.name, href: `/radiology/catalog/${procedure.id}` },
        { title: 'Edit', href: `/radiology/catalog/${procedure.id}/edit` },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Edit ${procedure.name}`} />

            <div className="p-4">
                <Card className="max-w-3xl">
                    <CardHeader>
                        <CardTitle>Edit Radiology Procedure</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <RadiologyProcedureForm procedure={procedure} {...rest} />
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
