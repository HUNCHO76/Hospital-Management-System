import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { type StartProcessingFormData } from '@/types/laboratory';
import { useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { type FormEventHandler, type ReactNode } from 'react';

interface StartProcessingDialogProps {
    processingId: number;
    testName: string;
    trigger: ReactNode;
}

/**
 * Starts processing of a single test (Phase 4, Step 32). Processing notes
 * are optional free text — never a replacement for the structured status
 * field itself (spec §13).
 */
export function StartProcessingDialog({ processingId, testName, trigger }: StartProcessingDialogProps) {
    const { data, setData, post, processing, errors, reset } = useForm<StartProcessingFormData>({ processing_notes: '' });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('laboratory.processing.start', processingId), { onSuccess: () => reset(), preserveScroll: true });
    };

    return (
        <Dialog>
            <DialogTrigger asChild>{trigger}</DialogTrigger>
            <DialogContent>
                <form onSubmit={submit}>
                    <DialogHeader>
                        <DialogTitle>Start processing {testName}</DialogTitle>
                        <DialogDescription>Confirm processing is beginning now.</DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-2 py-4">
                        <Label htmlFor="start_processing_notes">Notes</Label>
                        <Textarea
                            id="start_processing_notes"
                            rows={2}
                            value={data.processing_notes}
                            onChange={(e) => setData('processing_notes', e.target.value)}
                            placeholder="e.g. Sample centrifuged"
                        />
                        <InputError message={errors.processing_notes} />
                    </div>

                    <DialogFooter>
                        <Button type="submit" disabled={processing}>
                            {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                            Start Processing
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
