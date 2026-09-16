import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { type CreateServicePriceFormData } from '@/types/service';
import { Head, Link, useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { type FormEventHandler } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Billing', href: '/billing' },
    { title: 'Pricing', href: '/billing/pricing' },
    { title: 'New Price', href: '/billing/pricing/create' },
];

interface BillingPricingCreateProps {
    hospitals: { id: number; name: string }[];
    services: { id: number; code: string; name: string }[];
    initialServiceId: number | null;
}

/**
 * Configure a service's price at one facility, effective from a given date
 * (Phase 7, Step 53). A price is never edited — this form only ever
 * creates a new one; if an open-ended price already covers this service +
 * facility, the backend automatically closes it out the day before this
 * one starts (ServicePricingService::create()'s own versioning logic).
 * Backend re-validates and re-checks for overlap regardless of what this
 * form allows.
 */
export default function BillingPricingCreate({ hospitals, services, initialServiceId }: BillingPricingCreateProps) {
    const { data, setData, post, processing, errors } = useForm<CreateServicePriceFormData>({
        service_id: initialServiceId ? String(initialServiceId) : '',
        hospital_id: hospitals.length === 1 ? String(hospitals[0].id) : '',
        amount: '',
        effective_from: new Date().toISOString().slice(0, 10),
        effective_to: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('billing-pricing.store'));
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="New Price" />

            <div className="p-4">
                <Card className="max-w-2xl">
                    <CardHeader>
                        <CardTitle>New Price</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <form onSubmit={submit} className="space-y-6">
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="grid gap-2">
                                    <Label>Service *</Label>
                                    <Select value={data.service_id} onValueChange={(value) => setData('service_id', value)}>
                                        <SelectTrigger>
                                            <SelectValue placeholder="Select service" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {services.map((service) => (
                                                <SelectItem key={service.id} value={String(service.id)}>
                                                    {service.code} — {service.name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    <InputError message={errors.service_id} />
                                </div>

                                <div className="grid gap-2">
                                    <Label>Facility *</Label>
                                    <Select value={data.hospital_id} onValueChange={(value) => setData('hospital_id', value)}>
                                        <SelectTrigger>
                                            <SelectValue placeholder="Select hospital" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {hospitals.map((hospital) => (
                                                <SelectItem key={hospital.id} value={String(hospital.id)}>
                                                    {hospital.name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    <InputError message={errors.hospital_id} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="amount">Amount *</Label>
                                    <Input
                                        id="amount"
                                        type="number"
                                        min={0}
                                        step="0.01"
                                        value={data.amount}
                                        onChange={(e) => setData('amount', e.target.value)}
                                        required
                                    />
                                    <InputError message={errors.amount} />
                                </div>

                                <div />

                                <div className="grid gap-2">
                                    <Label htmlFor="effective_from">Effective From *</Label>
                                    <Input
                                        id="effective_from"
                                        type="date"
                                        value={data.effective_from}
                                        onChange={(e) => setData('effective_from', e.target.value)}
                                        required
                                    />
                                    <InputError message={errors.effective_from} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="effective_to">Effective To</Label>
                                    <Input
                                        id="effective_to"
                                        type="date"
                                        value={data.effective_to}
                                        onChange={(e) => setData('effective_to', e.target.value)}
                                        placeholder="Leave blank for ongoing"
                                    />
                                    <InputError message={errors.effective_to} />
                                </div>
                            </div>

                            <div className="flex justify-end gap-2">
                                <Button type="button" variant="outline" asChild>
                                    <Link href={route('billing-pricing.index')}>Cancel</Link>
                                </Button>
                                <Button type="submit" disabled={processing}>
                                    {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                                    Save Price
                                </Button>
                            </div>
                        </form>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
