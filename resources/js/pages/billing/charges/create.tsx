import { PatientSearch } from '@/components/billing/patient-search';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { type BillingHospital, type CreateChargeFormData, type PatientSearchResult } from '@/types/billing';
import { type Service } from '@/types/service';
import { Head, Link, useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { type FormEventHandler, useMemo, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Billing', href: '/billing' },
    { title: 'Record Charge', href: '/billing/charges/create' },
];

interface BillingChargeCreateProps {
    hospitals: BillingHospital[];
    services: Service[];
    initialPatient: PatientSearchResult | null;
    initialHospitalId: number | null;
}

/**
 * Manually record a charge against a patient's account (Phase 7, Step 52,
 * upgraded in Step 54 — spec §26-27 — to require selecting a priced
 * catalog service rather than a free-typed description/price). The account
 * is auto-provisioned on first use; no separate "create account" step
 * exists. The backend resolves the price via ServicePriceResolver and
 * re-validates everything (ChargeCaptureService::captureManual()) — this
 * form's own constraints are a convenience only, never trusted for the
 * actual amount charged.
 */
export default function BillingChargeCreate({ hospitals, services, initialPatient, initialHospitalId }: BillingChargeCreateProps) {
    const [selectedPatient, setSelectedPatient] = useState<PatientSearchResult | null>(initialPatient);

    const { data, setData, post, processing, errors } = useForm<CreateChargeFormData>({
        hospital_id: initialHospitalId ? String(initialHospitalId) : hospitals.length === 1 ? String(hospitals[0].id) : '',
        patient_id: initialPatient ? String(initialPatient.id) : '',
        encounter_id: '',
        service_id: '',
        quantity: '1',
        notes: '',
    });

    const selectedService = useMemo(() => services.find((service) => String(service.id) === data.service_id) ?? null, [services, data.service_id]);

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('billing.charges.store'));
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Record Charge" />

            <div className="p-4">
                <Card className="max-w-2xl">
                    <CardHeader>
                        <CardTitle>Record Charge</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <form onSubmit={submit} className="space-y-6">
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="grid gap-2">
                                    <Label>Hospital *</Label>
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
                                    <Label>Patient *</Label>
                                    <PatientSearch
                                        selected={selectedPatient}
                                        onSelect={(patient) => {
                                            setSelectedPatient(patient);
                                            setData('patient_id', String(patient.id));
                                        }}
                                    />
                                    <InputError message={errors.patient_id} />
                                </div>
                            </div>

                            <div className="grid gap-2">
                                <Label>Service *</Label>
                                <Select value={data.service_id} onValueChange={(value) => setData('service_id', value)}>
                                    <SelectTrigger>
                                        <SelectValue placeholder="Select a billable service" />
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
                                {selectedService && (
                                    <p className="text-muted-foreground text-sm">
                                        The price is resolved from the current catalog price for this service at the selected
                                        hospital — it cannot be typed in directly.
                                    </p>
                                )}
                            </div>

                            <div className="grid gap-2 sm:max-w-xs">
                                <Label htmlFor="quantity">Quantity</Label>
                                <Input
                                    id="quantity"
                                    type="number"
                                    min={1}
                                    value={data.quantity}
                                    onChange={(e) => setData('quantity', e.target.value)}
                                />
                                <InputError message={errors.quantity} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="notes">Reason / Reference *</Label>
                                <Textarea
                                    id="notes"
                                    placeholder="Why this charge is being recorded manually"
                                    value={data.notes}
                                    onChange={(e) => setData('notes', e.target.value)}
                                    required
                                />
                                <InputError message={errors.notes} />
                            </div>

                            <div className="flex justify-end gap-2">
                                <Button type="button" variant="outline" asChild>
                                    <Link href={route('billing.accounts.index')}>Cancel</Link>
                                </Button>
                                <Button type="submit" disabled={processing}>
                                    {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                                    Record Charge
                                </Button>
                            </div>
                        </form>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
