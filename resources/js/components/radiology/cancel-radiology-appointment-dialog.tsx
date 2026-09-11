import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { type FormEventHandler, type ReactNode } from 'react';

interface CancelRadiologyAppointmentDialogProps {
    appointmentId: number;
    trigger: ReactNode;
}

/**
 * Cancellation requires a reason (enforced server-side too) — never a
 * silent status change. Never hard-deletes; the appointment remains
 * historically visible (Part 19).
 */
export function CancelRadiologyAppointmentDialog({ appointmentId, trigger }: CancelRadiologyAppointmentDialogProps) {
    const { data, setData, post, processing, errors, reset } = useForm({ cancellation_reason: '' });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('radiology-scheduling.cancel', appointmentId), {
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
                        <DialogTitle>Cancel appointment</DialogTitle>
                        <DialogDescription>Please provide a reason for cancelling this appointment.</DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-2 py-4">
                        <Label htmlFor="rad_appt_cancellation_reason">Cancellation reason *</Label>
                        <Textarea
                            id="rad_appt_cancellation_reason"
                            value={data.cancellation_reason}
                            onChange={(e) => setData('cancellation_reason', e.target.value)}
                            rows={3}
                            autoFocus
                        />
                        <InputError message={errors.cancellation_reason} />
                    </div>

                    <DialogFooter>
                        <Button type="submit" variant="destructive" disabled={processing}>
                            {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                            Cancel appointment
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
