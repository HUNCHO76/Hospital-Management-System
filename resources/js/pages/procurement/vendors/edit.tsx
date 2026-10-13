import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';

interface Vendor {
    id: number;
    code: string;
    name: string;
    contact_person: string | null;
    email: string | null;
    phone: string | null;
    address: string | null;
    tax_id: string | null;
    payment_terms: string | null;
    bank_details: string | null;
    status: 'active' | 'inactive';
}

interface Props {
    vendor: Vendor;
}

export default function EditVendor({ vendor }: Props) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Dashboard', href: '/dashboard' },
        { title: 'Procurement', href: '/procurement' },
        { title: 'Vendors', href: '/procurement/vendors' },
        { title: vendor.name, href: `/procurement/vendors/${vendor.id}` },
        { title: 'Edit', href: `/procurement/vendors/${vendor.id}/edit` },
    ];

    const { data, setData, put, processing, errors } = useForm({
        code: vendor.code || '',
        name: vendor.name || '',
        contact_person: vendor.contact_person || '',
        email: vendor.email || '',
        phone: vendor.phone || '',
        address: vendor.address || '',
        tax_id: vendor.tax_id || '',
        payment_terms: vendor.payment_terms || 'Net 30 Days',
        bank_details: vendor.bank_details || '',
        status: vendor.status || 'active',
    });

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        put(`/procurement/vendors/${vendor.id}`);
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Edit Vendor ${vendor.name}`} />

            <div className="flex h-full flex-1 flex-col gap-6 p-6">
                <div className="flex items-center justify-between">
                    <PageHeader
                        title={`Edit Vendor: ${vendor.name}`}
                        description="Update vendor contact information, bank accounts, or compliance status."
                    />
                    <Link href={`/procurement/vendors/${vendor.id}`}>
                        <Button variant="outline" size="sm">
                            <ArrowLeft className="mr-2 h-4 w-4" /> Cancel & Return
                        </Button>
                    </Link>
                </div>

                <form onSubmit={handleSubmit} className="space-y-6">
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base font-semibold">Vendor Identity & Legal Information</CardTitle>
                        </CardHeader>
                        <CardContent className="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div className="space-y-1">
                                <Label htmlFor="code">Vendor Code *</Label>
                                <Input
                                    id="code"
                                    required
                                    value={data.code}
                                    onChange={(e) => setData('code', e.target.value)}
                                />
                                {errors.code && <p className="text-xs text-red-500">{errors.code}</p>}
                            </div>

                            <div className="space-y-1 md:col-span-2">
                                <Label htmlFor="name">Company / Supplier Legal Name *</Label>
                                <Input
                                    id="name"
                                    required
                                    value={data.name}
                                    onChange={(e) => setData('name', e.target.value)}
                                />
                                {errors.name && <p className="text-xs text-red-500">{errors.name}</p>}
                            </div>

                            <div className="space-y-1">
                                <Label htmlFor="contact_person">Key Contact Person</Label>
                                <Input
                                    id="contact_person"
                                    value={data.contact_person}
                                    onChange={(e) => setData('contact_person', e.target.value)}
                                />
                            </div>

                            <div className="space-y-1">
                                <Label htmlFor="phone">Phone Number</Label>
                                <Input
                                    id="phone"
                                    value={data.phone}
                                    onChange={(e) => setData('phone', e.target.value)}
                                />
                            </div>

                            <div className="space-y-1">
                                <Label htmlFor="email">Email Address</Label>
                                <Input
                                    id="email"
                                    type="email"
                                    value={data.email}
                                    onChange={(e) => setData('email', e.target.value)}
                                />
                            </div>

                            <div className="space-y-1">
                                <Label htmlFor="tax_id">TIN / VRN Tax ID</Label>
                                <Input
                                    id="tax_id"
                                    value={data.tax_id}
                                    onChange={(e) => setData('tax_id', e.target.value)}
                                />
                            </div>

                            <div className="space-y-1">
                                <Label htmlFor="payment_terms">Standard Payment Terms</Label>
                                <Input
                                    id="payment_terms"
                                    value={data.payment_terms}
                                    onChange={(e) => setData('payment_terms', e.target.value)}
                                />
                            </div>

                            <div className="space-y-1">
                                <Label htmlFor="status">Vendor Status</Label>
                                <select
                                    id="status"
                                    value={data.status}
                                    onChange={(e) => setData('status', e.target.value as any)}
                                    className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                                >
                                    <option value="active">Active</option>
                                    <option value="inactive">Inactive</option>
                                </select>
                            </div>

                            <div className="space-y-1 md:col-span-3">
                                <Label htmlFor="address">Physical Address</Label>
                                <Input
                                    id="address"
                                    value={data.address}
                                    onChange={(e) => setData('address', e.target.value)}
                                />
                            </div>

                            <div className="space-y-1 md:col-span-3">
                                <Label htmlFor="bank_details">Banking & Remittance Details</Label>
                                <Textarea
                                    id="bank_details"
                                    value={data.bank_details}
                                    onChange={(e) => setData('bank_details', e.target.value)}
                                    rows={2}
                                />
                            </div>
                        </CardContent>
                    </Card>

                    <div className="flex items-center justify-end gap-3">
                        <Link href={`/procurement/vendors/${vendor.id}`}>
                            <Button type="button" variant="outline">
                                Cancel
                            </Button>
                        </Link>
                        <Button type="submit" disabled={processing}>
                            {processing ? 'Saving...' : 'Save Changes'}
                        </Button>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
