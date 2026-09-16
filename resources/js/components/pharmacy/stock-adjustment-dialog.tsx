import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { stockAdjustmentTypeLabel } from '@/lib/stock-status';
import { type AdjustStockFormData, type StockAdjustmentType } from '@/types/stock';
import { useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { type FormEventHandler, type ReactNode } from 'react';

interface StockAdjustmentDialogProps {
    stockBatchId: number;
    currentQuantity: number;
    trigger: ReactNode;
}

const adjustmentTypes: StockAdjustmentType[] = ['adjustment_in', 'adjustment_out', 'damaged', 'expired'];

/**
 * Correct a batch's physical quantity (Phase 6, Step 49, spec §19/§35) — a
 * plain count correction, or a reason-classified expired/damaged write-off.
 * A reason is always required; the backend re-validates the resulting
 * balance can never go negative regardless of what this form allows.
 */
export function StockAdjustmentDialog({ stockBatchId, currentQuantity, trigger }: StockAdjustmentDialogProps) {
    const { data, setData, post, processing, errors, reset } = useForm<AdjustStockFormData>({
        type: 'adjustment_in',
        quantity: '',
        reason: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('pharmacy-stock.batches.adjust', stockBatchId), {
            preserveScroll: true,
            onSuccess: () => reset(),
        });
    };

    return (
        <Dialog>
            <DialogTrigger asChild>{trigger}</DialogTrigger>
            <DialogContent>
                <form onSubmit={submit}>
                    <DialogHeader>
                        <DialogTitle>Adjust stock</DialogTitle>
                        <DialogDescription>Current quantity: {currentQuantity}. Every adjustment is recorded in the stock ledger.</DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-4 py-4">
                        <div className="grid gap-2">
                            <Label>Type *</Label>
                            <Select value={data.type} onValueChange={(value) => setData('type', value as StockAdjustmentType)}>
                                <SelectTrigger>
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {adjustmentTypes.map((type) => (
                                        <SelectItem key={type} value={type}>
                                            {stockAdjustmentTypeLabel[type]}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={errors.type} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="adjustment_quantity">Quantity *</Label>
                            <Input
                                id="adjustment_quantity"
                                type="number"
                                min={1}
                                value={data.quantity}
                                onChange={(e) => setData('quantity', e.target.value)}
                                autoFocus
                            />
                            <InputError message={errors.quantity} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="adjustment_reason">Reason *</Label>
                            <Textarea id="adjustment_reason" rows={3} value={data.reason} onChange={(e) => setData('reason', e.target.value)} />
                            <InputError message={errors.reason} />
                        </div>
                    </div>

                    <DialogFooter>
                        <Button type="submit" disabled={processing}>
                            {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                            Confirm Adjustment
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
