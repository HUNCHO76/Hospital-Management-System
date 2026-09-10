import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { type AppointmentRescheduleFormData } from '@/types/appointment';
import { useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { type FormEventHandler, type ReactNode } from 'react';

interface RescheduleAppointmentDialogProps {
    appointmentId: number;
    currentStart: string;
    currentEnd: string;
    trigger: ReactNode;
}

/**
 * A scheduling-conflict response comes back as a normal Inertia field error
 * on scheduled_start (see AppointmentController::reschedule), so it renders
 * here exactly like any other validation error — never a raw exception page.
 */
export function RescheduleAppointmentDialog({ appointmentId, currentStart, currentEnd, trigger }: RescheduleAppointmentDialogProps) {
    const { data, setData, post, processing, errors } = useForm<AppointmentRescheduleFormData>({
        scheduled_start: currentStart.slice(0, 16),
        scheduled_end: currentEnd.slice(0, 16),
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('appointments.reschedule', appointmentId));
    };

    return (
        <Dialog>
            <DialogTrigger asChild>{trigger}</DialogTrigger>
            <DialogContent>
                <form onSubmit={submit}>
                    <DialogHeader>
                        <DialogTitle>Reschedule appointment</DialogTitle>
                        <DialogDescription>Choose a new time for this appointment.</DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-4 py-4">
                        <div className="grid gap-2">
                            <Label htmlFor="scheduled_start">New start time *</Label>
                            <Input
                                id="scheduled_start"
                                type="datetime-local"
                                value={data.scheduled_start}
                                onChange={(e) => setData('scheduled_start', e.target.value)}
                            />
                            <InputError message={errors.scheduled_start} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="scheduled_end">New end time *</Label>
                            <Input
                                id="scheduled_end"
                                type="datetime-local"
                                value={data.scheduled_end}
                                onChange={(e) => setData('scheduled_end', e.target.value)}
                            />
                            <InputError message={errors.scheduled_end} />
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
