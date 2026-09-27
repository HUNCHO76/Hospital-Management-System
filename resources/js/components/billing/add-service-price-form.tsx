import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { type CreateServicePriceFormData } from '@/types/service';
import { useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { type FormEventHandler } from 'react';

interface AddServicePriceFormProps {
    serviceId: number;
    hospitals: { id: number; name: string }[];
    practitioners: { id: number; practitioner_number: string; staff?: { first_name: string; last_name: string } }[];
}

/**
 * Records the next effective-dated price for a service, reachable from the
 * service edit page (Phase 7, Step 53 follow-up). Posts to the same
 * billing-pricing.store endpoint as /billing/pricing/create — this never
 * edits a price in place, ServicePricingService::create() still
 * auto-supersedes any open-ended price it overlaps.
 */
export function AddServicePriceForm({ serviceId, hospitals, practitioners }: AddServicePriceFormProps) {
    const { data, setData, post, processing, errors, transform } = useForm<CreateServicePriceFormData>({
        service_id: String(serviceId),
        hospital_id: hospitals.length === 1 ? String(hospitals[0].id) : '',
        practitioner_id: '',
        amount: '',
        effective_from: new Date().toISOString().slice(0, 10),
        effective_to: '',
    });

    transform((data) => ({
        ...data,
        practitioner_id: data.practitioner_id === '' ? null : data.practitioner_id,
    }));

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('billing-pricing.store'));
    };

    return (
        <form onSubmit={submit} className="space-y-4">
            <div className="grid gap-4 sm:grid-cols-2">
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
                    <Label>Practitioner</Label>
                    <Select value={data.practitioner_id || 'none'} onValueChange={(value) => setData('practitioner_id', value === 'none' ? '' : value)}>
                        <SelectTrigger>
                            <SelectValue placeholder="Facility-wide (all practitioners)" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="none">Facility-wide (all practitioners)</SelectItem>
                            {practitioners.map((practitioner) => (
                                <SelectItem key={practitioner.id} value={String(practitioner.id)}>
                                    {practitioner.staff
                                        ? `${practitioner.staff.first_name} ${practitioner.staff.last_name}`
                                        : practitioner.practitioner_number}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <InputError message={errors.practitioner_id} />
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

            <div className="flex justify-end">
                <Button type="submit" disabled={processing}>
                    {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                    Save Price
                </Button>
            </div>
        </form>
    );
}
