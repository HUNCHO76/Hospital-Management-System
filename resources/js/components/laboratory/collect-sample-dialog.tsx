import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { type CollectLaboratorySampleFormData } from '@/types/laboratory';
import { useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { type FormEventHandler, type ReactNode } from 'react';

interface CollectSampleDialogProps {
    sampleId: number;
    sampleNumber: string;
    trigger: ReactNode;
}

/**
 * Records the physical collection of a registered specimen. Collector and
 * collection time are never taken from this form — the backend stamps them
 * from the authenticated user and the server clock (spec §17-18).
 */
export function CollectSampleDialog({ sampleId, sampleNumber, trigger }: CollectSampleDialogProps) {
    const { data, setData, post, processing, errors, reset } = useForm<CollectLaboratorySampleFormData>({
        collection_site: '',
        collection_notes: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('laboratory.samples.collect', sampleId), { onSuccess: () => reset(), preserveScroll: true });
    };

    return (
        <Dialog>
            <DialogTrigger asChild>{trigger}</DialogTrigger>
            <DialogContent>
                <form onSubmit={submit}>
                    <DialogHeader>
                        <DialogTitle>Collect specimen {sampleNumber}</DialogTitle>
                        <DialogDescription>Confirm the specimen has been physically drawn from the patient.</DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-4 py-4">
                        <div className="grid gap-2">
                            <Label htmlFor="collection_site">Collection Site</Label>
                            <Input
                                id="collection_site"
                                value={data.collection_site}
                                onChange={(e) => setData('collection_site', e.target.value)}
                                placeholder="e.g. Left arm"
                            />
                            <InputError message={errors.collection_site} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="collection_notes">Collection Notes</Label>
                            <Textarea
                                id="collection_notes"
                                rows={2}
                                value={data.collection_notes}
                                onChange={(e) => setData('collection_notes', e.target.value)}
                            />
                            <InputError message={errors.collection_notes} />
                        </div>
                    </div>

                    <DialogFooter>
                        <Button type="submit" disabled={processing}>
                            {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                            Collect Sample
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
