import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { type FormEventHandler } from 'react';

interface EditServicePriceFormProps {
    priceId: number;
    amount: string;
    onDone: () => void;
}

/**
 * Corrects the amount of the CURRENT open-ended price in place (requires
 * 'billing-pricing.update'; audit-logged with the reason). Past/superseded
 * prices are never editable — use the "add new price" form for future changes.
 */
export function EditServicePriceForm({ priceId, amount, onDone }: EditServicePriceFormProps) {
    const { data, setData, patch, processing, errors } = useForm({ amount, reason: '' });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        patch(route('billing-pricing.update', priceId), { preserveScroll: true, onSuccess: onDone });
    };

    return (
        <form onSubmit={submit} className="grid gap-3 sm:grid-cols-[1fr_2fr_auto] sm:items-end">
            <div className="grid gap-1">
                <Label htmlFor={`amount-${priceId}`}>New amount *</Label>
                <Input
                    id={`amount-${priceId}`}
                    type="number"
                    min={0}
                    step="0.01"
                    value={data.amount}
                    onChange={(e) => setData('amount', e.target.value)}
                    required
                    autoFocus
                />
                <InputError message={errors.amount} />
            </div>
            <div className="grid gap-1">
                <Label htmlFor={`reason-${priceId}`}>Reason *</Label>
                <Input id={`reason-${priceId}`} value={data.reason} onChange={(e) => setData('reason', e.target.value)} required />
                <InputError message={errors.reason} />
            </div>
            <div className="flex gap-2">
                <Button type="submit" size="sm" disabled={processing}>
                    {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                    Save
                </Button>
                <Button type="button" size="sm" variant="outline" onClick={onDone}>
                    Cancel
                </Button>
            </div>
        </form>
    );
}
