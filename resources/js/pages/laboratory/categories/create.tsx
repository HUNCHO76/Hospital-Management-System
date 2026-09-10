import { LaboratoryCategoryForm } from '@/components/laboratory/laboratory-category-form';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Laboratory', href: '/laboratory' },
    { title: 'Categories', href: '/laboratory/categories' },
    { title: 'Add Category', href: '/laboratory/categories/create' },
];

export default function LaboratoryCategoryCreate() {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Add Laboratory Test Category" />

            <div className="p-4">
                <Card className="max-w-2xl">
                    <CardHeader>
                        <CardTitle>Add Laboratory Test Category</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <LaboratoryCategoryForm />
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
