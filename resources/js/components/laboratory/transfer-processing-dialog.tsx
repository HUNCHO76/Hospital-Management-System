import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { type LaboratoryBenchOption, type TransferProcessingFormData } from '@/types/laboratory';
import { useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { type FormEventHandler, type ReactNode } from 'react';

interface TransferProcessingDialogProps {
    processingId: number;
    testName: string;
    currentBenchId: number | null;
    benches: LaboratoryBenchOption[];
    trigger: ReactNode;
}

/**
 * Transfers a single test's processing to a different bench (Phase 4,
 * Step 32, spec §19) — only ever changes the bench; status is untouched.
 */
export function TransferProcessingDialog({ processingId, testName, currentBenchId, benches, trigger }: TransferProcessingDialogProps) {
    const { data, setData, post, processing, errors, reset } = useForm<TransferProcessingFormData>({
        bench_id: '',
        transfer_reason: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('laboratory.processing.transfer', processingId), { onSuccess: () => reset(), preserveScroll: true });
    };

    const options = benches.filter((bench) => bench.id !== currentBenchId);

    return (
        <Dialog>
            <DialogTrigger asChild>{trigger}</DialogTrigger>
            <DialogContent>
                <form onSubmit={submit}>
                    <DialogHeader>
                        <DialogTitle>Transfer {testName}</DialogTitle>
                        <DialogDescription>Move this test's processing to a different work area.</DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-4 py-4">
                        <div className="grid gap-2">
                            <Label>New Bench *</Label>
                            <Select value={data.bench_id} onValueChange={(value) => setData('bench_id', value)}>
                                <SelectTrigger>
                                    <SelectValue placeholder="Select a bench" />
                                </SelectTrigger>
                                <SelectContent>
                                    {options.map((bench) => (
                                        <SelectItem key={bench.id} value={String(bench.id)}>
                                            {bench.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={errors.bench_id} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="transfer_reason">Reason *</Label>
                            <Textarea
                                id="transfer_reason"
                                rows={2}
                                value={data.transfer_reason}
                                onChange={(e) => setData('transfer_reason', e.target.value)}
                            />
                            <InputError message={errors.transfer_reason} />
                        </div>
                    </div>

                    <DialogFooter>
                        <Button type="submit" disabled={processing || !data.bench_id || !data.transfer_reason}>
                            {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                            Transfer
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
