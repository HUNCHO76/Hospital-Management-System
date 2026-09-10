import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { laboratorySpecimenContainerLabel, laboratorySpecimenTypeLabel } from '@/lib/laboratory-status';
import { type LaboratoryOrderItem, type StoreLaboratorySampleFormData } from '@/types/laboratory';
import { useForm } from '@inertiajs/react';
import { ClipboardPlus, LoaderCircle } from 'lucide-react';
import { type FormEventHandler, useState } from 'react';

interface RegisterSampleFormProps {
    orderId: number;
    items: LaboratoryOrderItem[];
}

/**
 * Registers a new specimen against one or more of an order's still-pending
 * tests (spec §27-29). Deliberately offers no free-text specimen/container
 * pickers: the clinician only ever selects tests, and the specimen type and
 * container are derived automatically from whichever catalog requirement
 * those tests share — the only way to avoid a mismatched-specimen mistake
 * entirely, rather than merely rejecting it after the fact.
 */
export function RegisterSampleForm({ orderId, items }: RegisterSampleFormProps) {
    const [selectedIds, setSelectedIds] = useState<number[]>([]);
    const { setData, post, processing, errors, reset } = useForm<StoreLaboratorySampleFormData>({
        item_ids: [],
        specimen_type: '',
        container_type: '',
    });

    const availableItems = items.filter((item) => item.status === 'ordered' && item.specimen_type);
    const firstSelected = availableItems.find((item) => item.id === selectedIds[0]);

    const isCompatible = (item: LaboratoryOrderItem): boolean => {
        if (!firstSelected) {
            return true;
        }

        return item.specimen_type === firstSelected.specimen_type && (item.specimen_container ?? null) === (firstSelected.specimen_container ?? null);
    };

    const toggle = (item: LaboratoryOrderItem, checked: boolean) => {
        const next = checked ? [...selectedIds, item.id] : selectedIds.filter((id) => id !== item.id);
        setSelectedIds(next);
        setData('item_ids', next);

        const chosen = availableItems.find((i) => i.id === next[0]);
        setData('specimen_type', chosen?.specimen_type ?? '');
        setData('container_type', chosen?.specimen_container ?? '');
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('laboratory.samples.store', orderId), {
            preserveScroll: true,
            onSuccess: () => {
                reset();
                setSelectedIds([]);
            },
        });
    };

    if (availableItems.length === 0) {
        return null;
    }

    return (
        <form onSubmit={submit} className="space-y-4 border-t pt-4">
            <h3 className="text-sm font-medium">Register Specimen</h3>

            <div className="space-y-2">
                {availableItems.map((item) => {
                    const compatible = isCompatible(item);

                    return (
                        <div key={item.id} className="flex items-start gap-3">
                            <Checkbox
                                id={`item-${item.id}`}
                                checked={selectedIds.includes(item.id)}
                                disabled={!compatible}
                                onCheckedChange={(checked) => toggle(item, checked === true)}
                            />
                            <Label htmlFor={`item-${item.id}`} className={compatible ? '' : 'text-muted-foreground'}>
                                <span className="font-medium">
                                    {item.test_code} — {item.test_name}
                                </span>
                                <span className="text-muted-foreground ml-2 text-xs">
                                    {item.specimen_type ? laboratorySpecimenTypeLabel[item.specimen_type] : 'No specimen configured'}
                                    {item.specimen_container ? ` / ${laboratorySpecimenContainerLabel[item.specimen_container]}` : ''}
                                </span>
                            </Label>
                        </div>
                    );
                })}
            </div>
            <InputError message={errors.item_ids} />
            <InputError message={errors.specimen_type} />
            <InputError message={errors.container_type} />

            {firstSelected && (
                <p className="text-muted-foreground text-sm">
                    Specimen: {laboratorySpecimenTypeLabel[firstSelected.specimen_type!]}
                    {firstSelected.specimen_container ? ` · Container: ${laboratorySpecimenContainerLabel[firstSelected.specimen_container]}` : ''}
                </p>
            )}

            <div className="flex justify-end">
                <Button type="submit" size="sm" disabled={processing || selectedIds.length === 0}>
                    {processing ? <LoaderCircle className="h-4 w-4 animate-spin" /> : <ClipboardPlus className="h-4 w-4" />}
                    Register Specimen
                </Button>
            </div>
        </form>
    );
}
