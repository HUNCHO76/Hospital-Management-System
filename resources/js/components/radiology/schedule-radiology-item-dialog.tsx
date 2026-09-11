import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { type ScheduleRadiologyOrderItemFormData, type UnscheduledRadiologyOrderItem } from '@/types/radiology';
import { useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { type FormEventHandler, type ReactNode } from 'react';

interface ScheduleRadiologyItemDialogProps {
    item: UnscheduledRadiologyOrderItem;
    knownResources: string[];
    trigger: ReactNode;
}

/**
 * A resource/time conflict comes back as a normal Inertia field error on
 * resource_name (see RadiologySchedulingController::store), so it renders
 * here exactly like any other validation error — never a raw exception
 * page. No slot grid is offered: the caller sees existing bookings on the
 * scheduling page itself and picks a free time here (Part 6's documented
 * limitation — no working-hours/room-registry configuration exists yet).
 */
export function ScheduleRadiologyItemDialog({ item, knownResources, trigger }: ScheduleRadiologyItemDialogProps) {
    const { data, setData, post, processing, errors, reset } = useForm<ScheduleRadiologyOrderItemFormData>({
        radiology_order_item_id: item.id,
        resource_name: '',
        scheduled_start: '',
        scheduled_end: '',
        notes: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('radiology-scheduling.store'), {
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
                        <DialogTitle>Schedule {item.procedure_name}</DialogTitle>
                        <DialogDescription>
                            {item.order?.order_number} — {item.patient ? `${item.patient.first_name} ${item.patient.last_name}` : 'Unknown patient'}
                            {item.modality_snapshot ? ` — ${item.modality_snapshot.toUpperCase()}` : ''}
                        </DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-4 py-4">
                        <div className="grid gap-2">
                            <Label htmlFor="resource_name">Resource / Room *</Label>
                            <Input
                                id="resource_name"
                                list="known-radiology-resources"
                                value={data.resource_name}
                                onChange={(e) => setData('resource_name', e.target.value)}
                                placeholder="e.g. X-Ray Room 1"
                                autoFocus
                            />
                            <datalist id="known-radiology-resources">
                                {knownResources.map((resource) => (
                                    <option key={resource} value={resource} />
                                ))}
                            </datalist>
                            <InputError message={errors.resource_name} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="schedule_start">Start time *</Label>
                            <Input
                                id="schedule_start"
                                type="datetime-local"
                                value={data.scheduled_start}
                                onChange={(e) => setData('scheduled_start', e.target.value)}
                            />
                            <InputError message={errors.scheduled_start} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="schedule_end">End time *</Label>
                            <Input
                                id="schedule_end"
                                type="datetime-local"
                                value={data.scheduled_end}
                                onChange={(e) => setData('scheduled_end', e.target.value)}
                            />
                            <InputError message={errors.scheduled_end} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="schedule_notes">Notes</Label>
                            <Textarea id="schedule_notes" rows={2} value={data.notes} onChange={(e) => setData('notes', e.target.value)} />
                            <InputError message={errors.notes} />
                        </div>
                    </div>

                    <DialogFooter>
                        <Button type="submit" disabled={processing}>
                            {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                            Confirm schedule
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
