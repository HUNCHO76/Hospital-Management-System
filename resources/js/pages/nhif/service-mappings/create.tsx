import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Checkbox } from '@/components/ui/checkbox';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import InputError from '@/components/input-error';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'NHIF', href: '/nhif/service-mappings' },
    { title: 'Service Mappings', href: '/nhif/service-mappings' },
    { title: 'New Mapping', href: '/nhif/service-mappings/create' },
];

interface Service { id: number; name: string; code: string }
interface Hospital { id: number; name: string; code: string }
interface NhifServiceOption { id: number; nhif_item_code: string; nhif_item_name: string }

interface Props {
    services: Service[];
    hospitals: Hospital[];
    nhifServices: NhifServiceOption[];
    coverageStatuses: string[];
}

export default function ServiceMappingCreate({ services, hospitals, nhifServices, coverageStatuses }: Props) {
    const { data, setData, post, processing, errors } = useForm({
        service_id: '',
        hospital_id: '',
        nhif_item_code: '',
        nhif_item_name: '',
        nhif_package_code: '',
        nhif_package_name: '',
        coverage_status: 'covered',
        requires_preauth: false,
        effective_from: '',
        effective_to: '',
        notes: '',
    });

    const fillFromNhif = (code: string) => {
        const svc = nhifServices.find((s) => s.nhif_item_code === code);
        if (svc) {
            setData((prev) => ({ ...prev, nhif_item_code: svc.nhif_item_code, nhif_item_name: svc.nhif_item_name }));
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="New NHIF Service Mapping" />

            <div className="mx-auto max-w-2xl p-4">
                <h1 className="mb-6 text-xl font-semibold">New Service Mapping</h1>

                <form onSubmit={(e) => { e.preventDefault(); post(route('nhif.service-mappings.store')); }} className="space-y-4">
                    <div className="space-y-1">
                        <Label htmlFor="service_id">HMS Service *</Label>
                        <Select value={data.service_id} onValueChange={(v) => setData('service_id', v)}>
                            <SelectTrigger id="service_id"><SelectValue placeholder="Select service…" /></SelectTrigger>
                            <SelectContent>
                                {services.map((s) => (
                                    <SelectItem key={s.id} value={String(s.id)}>{s.name}</SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <InputError message={errors.service_id} />
                    </div>

                    <div className="space-y-1">
                        <Label htmlFor="hospital_id">Facility *</Label>
                        <Select value={data.hospital_id} onValueChange={(v) => setData('hospital_id', v)}>
                            <SelectTrigger id="hospital_id"><SelectValue placeholder="Select facility…" /></SelectTrigger>
                            <SelectContent>
                                {hospitals.map((h) => (
                                    <SelectItem key={h.id} value={String(h.id)}>{h.name}</SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <InputError message={errors.hospital_id} />
                    </div>

                    <div className="grid grid-cols-2 gap-4">
                        <div className="space-y-1">
                            <Label htmlFor="nhif_lookup">NHIF Item (from catalog)</Label>
                            <Select onValueChange={fillFromNhif}>
                                <SelectTrigger id="nhif_lookup"><SelectValue placeholder="Pick from catalog…" /></SelectTrigger>
                                <SelectContent>
                                    {nhifServices.map((s) => (
                                        <SelectItem key={s.id} value={s.nhif_item_code}>
                                            {s.nhif_item_code} — {s.nhif_item_name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>

                        <div className="space-y-1">
                            <Label htmlFor="nhif_item_code">NHIF Item Code *</Label>
                            <Input
                                id="nhif_item_code"
                                value={data.nhif_item_code}
                                onChange={(e) => setData('nhif_item_code', e.target.value)}
                                placeholder="e.g. 00123"
                            />
                            <InputError message={errors.nhif_item_code} />
                        </div>
                    </div>

                    <div className="space-y-1">
                        <Label htmlFor="nhif_item_name">NHIF Item Name *</Label>
                        <Input
                            id="nhif_item_name"
                            value={data.nhif_item_name}
                            onChange={(e) => setData('nhif_item_name', e.target.value)}
                        />
                        <InputError message={errors.nhif_item_name} />
                    </div>

                    <div className="space-y-1">
                        <Label htmlFor="coverage_status">Coverage Status *</Label>
                        <Select value={data.coverage_status} onValueChange={(v) => setData('coverage_status', v)}>
                            <SelectTrigger id="coverage_status"><SelectValue /></SelectTrigger>
                            <SelectContent>
                                {coverageStatuses.map((s) => (
                                    <SelectItem key={s} value={s}>{s.replace('_', ' ')}</SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <InputError message={errors.coverage_status} />
                    </div>

                    <div className="flex items-center gap-2">
                        <Checkbox
                            id="requires_preauth"
                            checked={data.requires_preauth}
                            onCheckedChange={(v) => setData('requires_preauth', Boolean(v))}
                        />
                        <Label htmlFor="requires_preauth">Requires Pre-authorisation</Label>
                    </div>

                    <div className="grid grid-cols-2 gap-4">
                        <div className="space-y-1">
                            <Label htmlFor="effective_from">Effective From</Label>
                            <Input id="effective_from" type="date" value={data.effective_from} onChange={(e) => setData('effective_from', e.target.value)} />
                        </div>
                        <div className="space-y-1">
                            <Label htmlFor="effective_to">Effective To</Label>
                            <Input id="effective_to" type="date" value={data.effective_to} onChange={(e) => setData('effective_to', e.target.value)} />
                        </div>
                    </div>

                    <div className="space-y-1">
                        <Label htmlFor="notes">Notes</Label>
                        <Textarea id="notes" value={data.notes} onChange={(e) => setData('notes', e.target.value)} rows={3} />
                    </div>

                    <div className="flex gap-2 pt-2">
                        <Button type="submit" disabled={processing}>Save Mapping</Button>
                        <Button type="button" variant="ghost" asChild>
                            <Link href={route('nhif.service-mappings.index')}>Cancel</Link>
                        </Button>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
