import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { type CompleteProcessingFormData } from '@/types/laboratory';
import { useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { type FormEventHandler, type ReactNode } from 'react';

interface CompleteProcessingDialogProps {
    processingId: number;
    testName: string;
    trigger: ReactNode;
}

/**
 * Completes processing of a single test (Phase 4, Step 32) — the test
 * immediately becomes RESULT_PENDING, this step's own boundary. Result
 * entry itself is a future step.
 */
export function CompleteProcessingDialog({ processingId, testName, trigger }: CompleteProcessingDialogProps) {
    const { data, setData, post, processing, errors, reset } = useForm<CompleteProcessingFormData>({ processing_notes: '' });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('laboratory.processing.complete', processingId), { onSuccess: () => reset(), preserveScroll: true });
    };

    return (
        <Dialog>
            <DialogTrigger asChild>{trigger}</DialogTrigger>
            <DialogContent>
                <form onSubmit={submit}>
                    <DialogHeader>
                        <DialogTitle>Complete processing of {testName}</DialogTitle>
                        <DialogDescription>The test will move to Result Pending, awaiting result entry.</DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-2 py-4">
                        <Label htmlFor="complete_processing_notes">Notes</Label>
                        <Textarea
                            id="complete_processing_notes"
                            rows={2}
                            value={data.processing_notes}
                            onChange={(e) => setData('processing_notes', e.target.value)}
                        />
                        <InputError message={errors.processing_notes} />
                    </div>

                    <DialogFooter>
                        <Button type="submit" disabled={processing}>
                            {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                            Complete Processing
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
