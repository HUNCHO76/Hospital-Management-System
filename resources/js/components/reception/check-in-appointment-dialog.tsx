import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { insuranceIndicatorLabel, insuranceIndicatorVariant } from '@/lib/reception-status';
import { type TodaysAppointmentRow } from '@/types/reception';
import { router } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { type ReactNode, useState } from 'react';

interface CheckInAppointmentDialogProps {
    appointment: TodaysAppointmentRow;
    trigger: ReactNode;
}

/**
 * Shows the patient/appointment/coverage summary before confirming a
 * scheduled-appointment check-in, and disables the button while the request
 * is in flight to prevent an accidental double check-in submission.
 */
export function CheckInAppointmentDialog({ appointment, trigger }: CheckInAppointmentDialogProps) {
    const [open, setOpen] = useState(false);
    const [processing, setProcessing] = useState(false);

    const patientName = appointment.patient
        ? [appointment.patient.first_name, appointment.patient.middle_name, appointment.patient.last_name].filter(Boolean).join(' ')
        : '—';

    const confirmCheckIn = () => {
        setProcessing(true);

        router.post(
            route('reception.appointments.check-in', appointment.id),
            {},
            {
                preserveScroll: true,
                onFinish: () => {
                    setProcessing(false);
                    setOpen(false);
                },
            },
        );
    };

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>{trigger}</DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Confirm patient check-in</DialogTitle>
                    <DialogDescription>Review the details below, then confirm to check in this appointment.</DialogDescription>
                </DialogHeader>

                <dl className="space-y-2 text-sm">
                    <div className="flex justify-between gap-4">
                        <dt className="text-muted-foreground">Patient</dt>
                        <dd className="font-medium">
                            {patientName} {appointment.patient ? `(${appointment.patient.patient_number})` : ''}
                        </dd>
                    </div>
                    <div className="flex justify-between gap-4">
                        <dt className="text-muted-foreground">Appointment</dt>
                        <dd>{new Date(appointment.scheduled_start).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' })}</dd>
                    </div>
                    <div className="flex justify-between gap-4">
                        <dt className="text-muted-foreground">Department</dt>
                        <dd>{appointment.department?.name ?? '—'}</dd>
                    </div>
                    <div className="flex justify-between gap-4">
                        <dt className="text-muted-foreground">Practitioner</dt>
                        <dd>{appointment.practitioner ? `${appointment.practitioner.first_name} ${appointment.practitioner.last_name}` : '—'}</dd>
                    </div>
                    <div className="flex justify-between gap-4">
                        <dt className="text-muted-foreground">Coverage</dt>
                        <dd>
                            <Badge variant={insuranceIndicatorVariant[appointment.insurance_indicator]}>
                                {insuranceIndicatorLabel[appointment.insurance_indicator]}
                            </Badge>
                        </dd>
                    </div>
                </dl>

                <DialogFooter>
                    <Button onClick={confirmCheckIn} disabled={processing}>
                        {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                        Confirm Check-In
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
