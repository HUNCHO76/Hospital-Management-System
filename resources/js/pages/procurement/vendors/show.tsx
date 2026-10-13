import { PageHeader } from '@/components/page-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { usePermissions } from '@/hooks/use-permissions';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, Edit3, FileText, Plus, ShoppingCart } from 'lucide-react';

interface PurchaseOrder {
    id: number;
    po_number: string;
    po_date: string;
    total_amount: number;
    status: string;
}

interface Invoice {
    id: number;
    system_invoice_number: string;
    invoice_number: string;
    invoice_date: string;
    total_amount: number;
    match_status: string;
    status: string;
}

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
    rating: number | null;
    status: 'active' | 'inactive' | 'blacklisted';
    purchase_orders?: PurchaseOrder[];
    invoices?: Invoice[];
}

interface Props {
    vendor: Vendor;
}

export default function ShowVendor({ vendor }: Props) {
    const { can } = usePermissions();
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Dashboard', href: '/dashboard' },
        { title: 'Procurement', href: '/procurement' },
        { title: 'Vendors', href: '/procurement/vendors' },
        { title: vendor.name, href: `/procurement/vendors/${vendor.id}` },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Vendor ${vendor.name}`} />

            <div className="flex h-full flex-1 flex-col gap-6 p-6">
                <div className="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
                    <div>
                        <div className="flex items-center gap-3">
                            <PageHeader
                                title={vendor.name}
                                description={`Vendor Code: ${vendor.code} • Rating: ${vendor.rating ? `${vendor.rating} / 5.0` : 'Unrated'}`}
                            />
                            <Badge variant={vendor.status === 'active' ? 'default' : 'secondary'} className="capitalize">
                                {vendor.status}
                            </Badge>
                        </div>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <Link href="/procurement/vendors">
                            <Button variant="outline" size="sm">
                                <ArrowLeft className="mr-2 h-4 w-4" /> Back to Vendors
                            </Button>
                        </Link>
                        {can('procurement-vendors.update') && (
                            <Link href={`/procurement/vendors/${vendor.id}/edit`}>
                                <Button variant="outline" size="sm">
                                    <Edit3 className="mr-2 h-4 w-4" /> Edit Vendor
                                </Button>
                            </Link>
                        )}
                        {can('procurement-purchase-orders.create') && (
                            <Link href={`/procurement/orders/create?vendor_id=${vendor.id}`}>
                                <Button size="sm">
                                    <ShoppingCart className="mr-2 h-4 w-4" /> New PO
                                </Button>
                            </Link>
                        )}
                    </div>
                </div>

                {/* Details Cards */}
                <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm text-muted-foreground uppercase">Contact Information</CardTitle>
                        </CardHeader>
                        <CardContent className="text-sm space-y-1">
                            <p><span className="text-muted-foreground">Contact Person:</span> {vendor.contact_person || 'N/A'}</p>
                            <p><span className="text-muted-foreground">Phone:</span> {vendor.phone || 'N/A'}</p>
                            <p><span className="text-muted-foreground">Email:</span> {vendor.email || 'N/A'}</p>
                            <p><span className="text-muted-foreground">Address:</span> {vendor.address || 'N/A'}</p>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm text-muted-foreground uppercase">Commercial Terms</CardTitle>
                        </CardHeader>
                        <CardContent className="text-sm space-y-1">
                            <p><span className="text-muted-foreground">TIN / VRN Tax ID:</span> {vendor.tax_id || 'N/A'}</p>
                            <p><span className="text-muted-foreground">Payment Terms:</span> {vendor.payment_terms || 'Standard 30 Days'}</p>
                            <p><span className="text-muted-foreground">Registration Status:</span> Certified Hospital Supplier</p>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm text-muted-foreground uppercase">Banking Remittance</CardTitle>
                        </CardHeader>
                        <CardContent className="text-sm">
                            {vendor.bank_details ? (
                                <p className="font-mono text-xs whitespace-pre-line bg-muted p-2 rounded">
                                    {vendor.bank_details}
                                </p>
                            ) : (
                                <p className="text-muted-foreground">No bank account specified</p>
                            )}
                        </CardContent>
                    </Card>
                </div>

                {/* Recent POs */}
                <Card>
                    <CardHeader className="flex flex-row items-center justify-between pb-3">
                        <CardTitle className="text-base font-semibold">Recent Purchase Orders</CardTitle>
                        <Link href={`/procurement/orders?vendor_id=${vendor.id}`}>
                            <Button variant="ghost" size="sm">View All POs</Button>
                        </Link>
                    </CardHeader>
                    <CardContent className="p-0">
                        <div className="overflow-x-auto">
                            <table className="w-full text-left text-sm">
                                <thead className="bg-muted text-xs uppercase text-muted-foreground">
                                    <tr>
                                        <th className="px-4 py-2">PO #</th>
                                        <th className="px-4 py-2">Date</th>
                                        <th className="px-4 py-2">Total Amount</th>
                                        <th className="px-4 py-2">Status</th>
                                        <th className="px-4 py-2 text-right">Action</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {(!vendor.purchase_orders || vendor.purchase_orders.length === 0) ? (
                                        <tr>
                                            <td colSpan={5} className="py-4 text-center text-muted-foreground">
                                                No purchase orders recorded for this vendor.
                                            </td>
                                        </tr>
                                    ) : (
                                        vendor.purchase_orders.map((po) => (
                                            <tr key={po.id}>
                                                <td className="px-4 py-2 font-semibold text-primary">{po.po_number}</td>
                                                <td className="px-4 py-2 text-muted-foreground">{po.po_date}</td>
                                                <td className="px-4 py-2 font-medium">TZS {Number(po.total_amount).toLocaleString()}</td>
                                                <td className="px-4 py-2">
                                                    <Badge variant="outline" className="capitalize">{po.status}</Badge>
                                                </td>
                                                <td className="px-4 py-2 text-right">
                                                    <Link href={`/procurement/orders/${po.id}`}>
                                                        <Button variant="ghost" size="sm">View</Button>
                                                    </Link>
                                                </td>
                                            </tr>
                                        ))
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </CardContent>
                </Card>

                {/* Recent Invoices */}
                <Card>
                    <CardHeader className="flex flex-row items-center justify-between pb-3">
                        <CardTitle className="text-base font-semibold">Recent Invoices</CardTitle>
                        <Link href={`/procurement/invoices?vendor_id=${vendor.id}`}>
                            <Button variant="ghost" size="sm">View All Invoices</Button>
                        </Link>
                    </CardHeader>
                    <CardContent className="p-0">
                        <div className="overflow-x-auto">
                            <table className="w-full text-left text-sm">
                                <thead className="bg-muted text-xs uppercase text-muted-foreground">
                                    <tr>
                                        <th className="px-4 py-2">Invoice #</th>
                                        <th className="px-4 py-2">Date</th>
                                        <th className="px-4 py-2">Amount</th>
                                        <th className="px-4 py-2">Match Status</th>
                                        <th className="px-4 py-2 text-right">Action</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {(!vendor.invoices || vendor.invoices.length === 0) ? (
                                        <tr>
                                            <td colSpan={5} className="py-4 text-center text-muted-foreground">
                                                No invoices recorded for this vendor.
                                            </td>
                                        </tr>
                                    ) : (
                                        vendor.invoices.map((inv) => (
                                            <tr key={inv.id}>
                                                <td className="px-4 py-2 font-semibold text-primary">{inv.invoice_number}</td>
                                                <td className="px-4 py-2 text-muted-foreground">{inv.invoice_date}</td>
                                                <td className="px-4 py-2 font-medium">TZS {Number(inv.total_amount).toLocaleString()}</td>
                                                <td className="px-4 py-2">
                                                    <Badge variant="outline" className="capitalize">{inv.match_status}</Badge>
                                                </td>
                                                <td className="px-4 py-2 text-right">
                                                    <Link href={`/procurement/invoices/${inv.id}`}>
                                                        <Button variant="ghost" size="sm">View</Button>
                                                    </Link>
                                                </td>
                                            </tr>
                                        ))
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
