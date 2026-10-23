import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { laboratorySpecimenContainerLabel, laboratorySpecimenTypeLabel } from '@/lib/laboratory-status';
import {
    type LaboratorySpecimenContainer,
    type LaboratorySpecimenType,
} from '@/types/laboratory';
import { useForm } from '@inertiajs/react';
import { FlaskConical, LoaderCircle } from 'lucide-react';
import { type FormEventHandler, type ReactNode, useState } from 'react';

interface ConfigureTestSpecimenDialogProps {
    testId: number;
    testCode: string;
    testName: string;
    currentSpecimenType?: LaboratorySpecimenType | null;
    currentSpecimenContainer?: LaboratorySpecimenContainer | null;
    currentCollectionNotes?: string | null;
    specimenTypes: LaboratorySpecimenType[];
    specimenContainers: LaboratorySpecimenContainer[];
    trigger: ReactNode;
    onSuccess?: () => void;
}

export function ConfigureTestSpecimenDialog({
    testId,
    testCode,
    testName,
    currentSpecimenType,
    currentSpecimenContainer,
    currentCollectionNotes,
    specimenTypes,
    specimenContainers,
    trigger,
    onSuccess,
}: ConfigureTestSpecimenDialogProps) {
    const [open, setOpen] = useState(false);

    // Provide sensible smart default if not configured yet
    const defaultSpecimenType = currentSpecimenType || (
        /blood|gas|abg|vbg|cbc|haem/i.test(testName)
            ? 'whole_blood'
            : /urine/i.test(testName)
            ? 'urine'
            : /stool/i.test(testName)
            ? 'stool'
            : /sputum/i.test(testName)
            ? 'sputum'
            : /swab/i.test(testName)
            ? 'swab'
            : /serum|chem|electrolyte/i.test(testName)
            ? 'serum'
            : 'other'
    );

    const defaultContainer = currentSpecimenContainer || (
        /abg|vbg|blood gas/i.test(testName)
            ? 'other'
            : /cbc|fbc|blood count/i.test(testName)
            ? 'edta_tube'
            : /urine|sputum|fluid|csf/i.test(testName)
            ? 'sterile_container'
            : /stool/i.test(testName)
            ? 'universal_container'
            : /swab/i.test(testName)
            ? 'swab_transport_medium'
            : /serum|chem/i.test(testName)
            ? 'plain_tube'
            : 'other'
    );

    const { data, setData, patch, processing, errors } = useForm({
        specimen_type: defaultSpecimenType,
        specimen_container: defaultContainer,
        collection_notes: currentCollectionNotes ?? (/abg|vbg|blood gas/i.test(testName) ? 'Heparinized blood gas syringe' : ''),
        redirect_to: typeof window !== 'undefined' ? window.location.href : '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        patch(route('laboratory-tests.update', testId), {
            preserveScroll: true,
            onSuccess: () => {
                setOpen(false);
                onSuccess?.();
            },
        });
    };

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>{trigger}</DialogTrigger>
            <DialogContent className="max-w-lg">
                <form onSubmit={submit}>
                    <DialogHeader>
                        <DialogTitle className="flex items-center gap-2">
                            <FlaskConical className="h-5 w-5 text-primary" />
                            Configure Specimen Requirement
                        </DialogTitle>
                        <DialogDescription>
                            Set the required specimen type and container for{' '}
                            <span className="font-semibold text-foreground">{testCode} — {testName}</span> so
                            laboratory technicians can collect and register specimens.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-4 py-4">
                        <div className="grid gap-2">
                            <Label htmlFor="specimen_type">Specimen Type *</Label>
                            <Select
                                value={data.specimen_type}
                                onValueChange={(val) => setData('specimen_type', val as LaboratorySpecimenType)}
                            >
                                <SelectTrigger id="specimen_type">
                                    <SelectValue placeholder="Select specimen type" />
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
                            <Label htmlFor="specimen_container">Container Type</Label>
                            <Select
                                value={data.specimen_container}
                                onValueChange={(val) => setData('specimen_container', val as LaboratorySpecimenContainer)}
                            >
                                <SelectTrigger id="specimen_container">
                                    <SelectValue placeholder="Select container type" />
                                </SelectTrigger>
                                <SelectContent>
                                    {specimenContainers.map((container) => (
                                        <SelectItem key={container} value={container}>
                                            {laboratorySpecimenContainerLabel[container]}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={errors.specimen_container} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="collection_notes">Collection Notes (Optional)</Label>
                            <Textarea
                                id="collection_notes"
                                rows={2}
                                value={data.collection_notes}
                                onChange={(e) => setData('collection_notes', e.target.value)}
                                placeholder="e.g. Draw into heparinized syringe on ice"
                            />
                            <InputError message={errors.collection_notes} />
                        </div>
                    </div>

                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={() => setOpen(false)} disabled={processing}>
                            Cancel
                        </Button>
                        <Button type="submit" disabled={processing || !data.specimen_type}>
                            {processing && <LoaderCircle className="mr-2 h-4 w-4 animate-spin" />}
                            Save & Enable Collection
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
