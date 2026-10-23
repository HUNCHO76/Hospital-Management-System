import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { type CollectLaboratorySampleFormData } from '@/types/laboratory';
import { useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { type FormEventHandler, type ReactNode } from 'react';

interface CollectSampleDialogProps {
    sampleId: number;
    sampleNumber: string;
    trigger: ReactNode;
    initialSpecimenSource?: string | null;
}

/**
 * Records the physical collection of a registered specimen. Collector and
 * collection time are never taken from this form — the backend stamps them
 * from the authenticated user and the server clock (spec §17-18).
 */
export function CollectSampleDialog({ sampleId, sampleNumber, trigger, initialSpecimenSource }: CollectSampleDialogProps) {
    const { data, setData, post, processing, errors, reset } = useForm<CollectLaboratorySampleFormData>({
        collection_site: '',
        collection_notes: '',
        specimen_source: initialSpecimenSource ?? '',
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
                            <Label htmlFor="specimen_source">Specimen Source</Label>
                            <Select
                                value={data.specimen_source || 'unspecified'}
                                onValueChange={(val) => setData('specimen_source', val === 'unspecified' ? '' : val)}
                            >
                                <SelectTrigger id="specimen_source">
                                    <SelectValue placeholder="Select specimen source (optional)" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="unspecified">Unspecified / Standard</SelectItem>
                                    <SelectItem value="arterial">Arterial (e.g. Radial / Femoral / ABG)</SelectItem>
                                    <SelectItem value="venous">Venous (e.g. Peripheral / Central / VBG)</SelectItem>
                                    <SelectItem value="capillary">Capillary (e.g. Heel / Fingerstick)</SelectItem>
                                </SelectContent>
                            </Select>
                            <InputError message={errors.specimen_source} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="collection_site">Collection Site</Label>
                            <Input
                                id="collection_site"
                                value={data.collection_site}
                                onChange={(e) => setData('collection_site', e.target.value)}
                                placeholder="e.g. Left radial artery / Right antecubital fossa"
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
