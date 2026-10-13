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

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Procurement', href: '/procurement' },
    { title: 'Vendors', href: '/procurement/vendors' },
    { title: 'Register Vendor', href: '/procurement/vendors/create' },
];

export default function CreateVendor() {
    const { data, setData, post, processing, errors } = useForm({
        code: `VEN-${Math.floor(1000 + Math.random() * 9000)}`,
        name: '',
        contact_person: '',
        email: '',
        phone: '',
        address: '',
        tax_id: '',
        payment_terms: 'Net 30 Days',
        bank_details: '',
        status: 'active',
    });

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        post('/procurement/vendors');
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Register Vendor - Procurement" />

            <div className="flex h-full flex-1 flex-col gap-6 p-6">
                <div className="flex items-center justify-between">
                    <PageHeader
                        title="Register Certified Vendor"
                        description="Enter supplier information, legal registration, TIN / VRN, and banking credentials."
                    />
                    <Link href="/procurement/vendors">
                        <Button variant="outline" size="sm">
                            <ArrowLeft className="mr-2 h-4 w-4" /> Cancel
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
                                    placeholder="e.g. MedPharm Tanzania Ltd"
                                    value={data.name}
                                    onChange={(e) => setData('name', e.target.value)}
                                />
                                {errors.name && <p className="text-xs text-red-500">{errors.name}</p>}
                            </div>

                            <div className="space-y-1">
                                <Label htmlFor="contact_person">Key Contact Person</Label>
                                <Input
                                    id="contact_person"
                                    placeholder="e.g. Dr. John Doe"
                                    value={data.contact_person}
                                    onChange={(e) => setData('contact_person', e.target.value)}
                                />
                            </div>

                            <div className="space-y-1">
                                <Label htmlFor="phone">Phone Number</Label>
                                <Input
                                    id="phone"
                                    placeholder="e.g. +255 712 345 678"
                                    value={data.phone}
                                    onChange={(e) => setData('phone', e.target.value)}
                                />
                            </div>

                            <div className="space-y-1">
                                <Label htmlFor="email">Email Address</Label>
                                <Input
                                    id="email"
                                    type="email"
                                    placeholder="e.g. sales@medpharm.co.tz"
                                    value={data.email}
                                    onChange={(e) => setData('email', e.target.value)}
                                />
                            </div>

                            <div className="space-y-1">
                                <Label htmlFor="tax_id">TIN / VRN Tax ID</Label>
                                <Input
                                    id="tax_id"
                                    placeholder="e.g. 100-234-567"
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
                                    onChange={(e) => setData('status', e.target.value)}
                                    className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                                >
                                    <option value="active">Active</option>
                                    <option value="inactive">Inactive</option>
                                </select>
                            </div>

                            <div className="space-y-1 md:col-span-3">
                                <Label htmlFor="address">Physical Address / Headquarters</Label>
                                <Input
                                    id="address"
                                    placeholder="Plot 45, Nyerere Road, Dar es Salaam"
                                    value={data.address}
                                    onChange={(e) => setData('address', e.target.value)}
                                />
                            </div>

                            <div className="space-y-1 md:col-span-3">
                                <Label htmlFor="bank_details">Banking & Remittance Details</Label>
                                <Textarea
                                    id="bank_details"
                                    placeholder="Bank Name: CRDB Bank, Account Name: MedPharm Ltd, Account Number: 0150..., Branch: Clock Tower"
                                    value={data.bank_details}
                                    onChange={(e) => setData('bank_details', e.target.value)}
                                    rows={2}
                                />
                            </div>
                        </CardContent>
                    </Card>

                    <div className="flex items-center justify-end gap-3">
                        <Link href="/procurement/vendors">
                            <Button type="button" variant="outline">
                                Cancel
                            </Button>
                        </Link>
                        <Button type="submit" disabled={processing}>
                            {processing ? 'Registering...' : 'Save Vendor'}
                        </Button>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
