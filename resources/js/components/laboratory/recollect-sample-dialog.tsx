import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { laboratorySpecimenContainerLabel, laboratorySpecimenTypeLabel } from '@/lib/laboratory-status';
import { type LaboratorySpecimenContainer, type LaboratorySpecimenType, type StoreLaboratorySampleFormData } from '@/types/laboratory';
import { useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { type FormEventHandler, type ReactNode } from 'react';

interface RecollectSampleDialogProps {
    sampleId: number;
    sampleNumber: string;
    defaultSpecimenType: LaboratorySpecimenType;
    defaultContainerType: LaboratorySpecimenContainer | null;
    specimenTypes: LaboratorySpecimenType[];
    specimenContainers: LaboratorySpecimenContainer[];
    trigger: ReactNode;
}

const NONE = '__none__';

/**
 * Creates a fresh specimen to replace a rejected one (spec §33-34).
 * Specimen/container default to the rejected sample's own values but may be
 * overridden — a wrong-container rejection typically needs a different tube.
 */
export function RecollectSampleDialog({
    sampleId,
    sampleNumber,
    defaultSpecimenType,
    defaultContainerType,
    specimenTypes,
    specimenContainers,
    trigger,
}: RecollectSampleDialogProps) {
    const { data, setData, post, processing, errors, reset } = useForm<Pick<StoreLaboratorySampleFormData, 'specimen_type' | 'container_type'>>({
        specimen_type: defaultSpecimenType,
        container_type: defaultContainerType ?? '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('laboratory.samples.recollect', sampleId), { onSuccess: () => reset(), preserveScroll: true });
    };

    return (
        <Dialog>
            <DialogTrigger asChild>{trigger}</DialogTrigger>
            <DialogContent>
                <form onSubmit={submit}>
                    <DialogHeader>
                        <DialogTitle>Recollect specimen</DialogTitle>
                        <DialogDescription>Creates a new specimen to replace rejected sample {sampleNumber}.</DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-4 py-4">
                        <div className="grid gap-2">
                            <Label>Specimen Type</Label>
                            <Select value={data.specimen_type} onValueChange={(value) => setData('specimen_type', value as LaboratorySpecimenType)}>
                                <SelectTrigger>
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {specimenTypes.map((type) => (
                                        <SelectItem key={type} value={type}>
                                            {laboratorySpecimenTypeLabel[type]}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={errors.specimen_type} />
                        </div>
                        <div className="grid gap-2">
                            <Label>Container</Label>
                            <Select
                                value={data.container_type || NONE}
                                onValueChange={(value) => setData('container_type', value === NONE ? '' : (value as LaboratorySpecimenContainer))}
                            >
                                <SelectTrigger>
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={NONE}>Not specified</SelectItem>
                                    {specimenContainers.map((container) => (
                                        <SelectItem key={container} value={container}>
                                            {laboratorySpecimenContainerLabel[container]}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={errors.container_type} />
                        </div>
                    </div>

                    <DialogFooter>
                        <Button type="submit" disabled={processing}>
                            {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                            Create Recollection
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
