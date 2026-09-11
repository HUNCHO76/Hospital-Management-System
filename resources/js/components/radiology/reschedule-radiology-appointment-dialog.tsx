import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { type FormEventHandler, type ReactNode } from 'react';

interface RescheduleRadiologyAppointmentDialogProps {
    appointmentId: number;
    currentStart: string;
    currentEnd: string;
    trigger: ReactNode;
}

/**
 * A resource/time conflict comes back as a normal Inertia field error on
 * scheduled_start. A reason is required (Part 18) — the previous time is
 * preserved via the audit log, never silently overwritten.
 */
export function RescheduleRadiologyAppointmentDialog({
    appointmentId,
    currentStart,
    currentEnd,
    trigger,
}: RescheduleRadiologyAppointmentDialogProps) {
    const { data, setData, post, processing, errors, reset } = useForm({
        scheduled_start: currentStart.slice(0, 16),
        scheduled_end: currentEnd.slice(0, 16),
        reason: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('radiology-scheduling.reschedule', appointmentId), {
            preserveScroll: true,
            onSuccess: () => reset('reason'),
        });
    };

    return (
        <Dialog>
            <DialogTrigger asChild>{trigger}</DialogTrigger>
            <DialogContent>
                <form onSubmit={submit}>
                    <DialogHeader>
                        <DialogTitle>Reschedule appointment</DialogTitle>
                        <DialogDescription>Choose a new time and record why.</DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-4 py-4">
                        <div className="grid gap-2">
                            <Label htmlFor="reschedule_start">New start time *</Label>
                            <Input
                                id="reschedule_start"
                                type="datetime-local"
                                value={data.scheduled_start}
                                onChange={(e) => setData('scheduled_start', e.target.value)}
                            />
                            <InputError message={errors.scheduled_start} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="reschedule_end">New end time *</Label>
                            <Input
                                id="reschedule_end"
                                type="datetime-local"
                                value={data.scheduled_end}
                                onChange={(e) => setData('scheduled_end', e.target.value)}
                            />
                            <InputError message={errors.scheduled_end} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="reschedule_reason">Reason *</Label>
                            <Textarea id="reschedule_reason" rows={2} value={data.reason} onChange={(e) => setData('reason', e.target.value)} />
                            <InputError message={errors.reason} />
                        </div>
                    </div>

                    <DialogFooter>
                        <Button type="submit" disabled={processing}>
                            {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                            Reschedule
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
