import { LaboratoryCategoryForm } from '@/components/laboratory/laboratory-category-form';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { type LaboratoryTestCategory } from '@/types/laboratory';
import { Head } from '@inertiajs/react';

interface LaboratoryCategoryEditProps {
    category: LaboratoryTestCategory;
}

export default function LaboratoryCategoryEdit({ category }: LaboratoryCategoryEditProps) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Laboratory', href: '/laboratory' },
        { title: 'Categories', href: '/laboratory/categories' },
        { title: 'Edit', href: `/laboratory/categories/${category.id}/edit` },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Edit ${category.name}`} />

            <div className="p-4">
                <Card className="max-w-2xl">
                    <CardHeader>
                        <CardTitle>Edit Laboratory Test Category</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <LaboratoryCategoryForm category={category} />
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
