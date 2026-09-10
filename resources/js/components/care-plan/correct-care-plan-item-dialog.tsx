import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { type CarePlanItem } from '@/types/care-plan';
import { useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { type FormEventHandler, type ReactNode, useState } from 'react';

interface CorrectCarePlanItemDialogProps {
    encounterId: number;
    item: CarePlanItem;
    trigger: ReactNode;
}

/**
 * The controlled correction workflow for a completed treatment item (spec
 * §31) — description/instructions/dates only, never the prescription's
 * structured fields (see CarePlanService::correctItem()'s class docs). A
 * reason is always required.
 */
export function CorrectCarePlanItemDialog({ encounterId, item, trigger }: CorrectCarePlanItemDialogProps) {
    const [open, setOpen] = useState(false);
    const { data, setData, post, processing, errors, reset } = useForm({
        description: item.description ?? '',
        instructions: item.instructions ?? '',
        correction_reason: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('clinical-encounters.care-plan.items.correct', [encounterId, item.id]), {
            preserveScroll: true,
            onSuccess: () => {
                reset('correction_reason');
                setOpen(false);
            },
        });
    };

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>{trigger}</DialogTrigger>
            <DialogContent>
                <form onSubmit={submit}>
                    <DialogHeader>
                        <DialogTitle>Correct treatment item</DialogTitle>
                        <DialogDescription>This item is {item.status}. Amend only what is clinically necessary and record why.</DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-4 py-4">
                        <div className="grid gap-1.5">
                            <Label htmlFor="correct_item_description">Description</Label>
                            <Textarea
                                id="correct_item_description"
                                value={data.description}
                                onChange={(e) => setData('description', e.target.value)}
                                rows={2}
                            />
                            <InputError message={errors.description} />
                        </div>

                        <div className="grid gap-1.5">
                            <Label htmlFor="correct_item_instructions">Instructions</Label>
                            <Textarea
                                id="correct_item_instructions"
                                value={data.instructions}
                                onChange={(e) => setData('instructions', e.target.value)}
                                rows={2}
                            />
                            <InputError message={errors.instructions} />
                        </div>

                        <div className="grid gap-1.5">
                            <Label htmlFor="item_correction_reason">Reason for correction *</Label>
                            <Textarea
                                id="item_correction_reason"
                                value={data.correction_reason}
                                onChange={(e) => setData('correction_reason', e.target.value)}
                                rows={2}
                            />
                            <InputError message={errors.correction_reason} />
                        </div>
                    </div>

                    <DialogFooter>
                        <Button type="submit" disabled={processing}>
                            {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                            Save Correction
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
