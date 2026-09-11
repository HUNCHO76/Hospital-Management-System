import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { type RadiologyAppointment } from '@/types/radiology';
import { useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { type FormEventHandler, type ReactNode } from 'react';

interface RadiologyExaminationStartDialogProps {
    appointment: RadiologyAppointment;
    trigger: ReactNode;
}

/**
 * Patient-safety confirmation before starting an examination (Phase 5, Step
 * 41, Part 18) — displays patient name, patient number, date of birth, sex,
 * procedure, and order number so staff can positively verify the identity
 * match themselves. The backend independently re-validates every one of
 * these relationships regardless of what is shown here (Part 18's own "the
 * backend must independently validate" instruction) — this panel is a
 * safety aid, never the actual guard.
 */
export function RadiologyExaminationStartDialog({ appointment, trigger }: RadiologyExaminationStartDialogProps) {
    const { data, setData, post, processing, errors } = useForm({ resource_name: appointment.resource_name });

    const patientName = appointment.patient
        ? [appointment.patient.first_name, appointment.patient.middle_name, appointment.patient.last_name].filter(Boolean).join(' ')
        : '—';

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('radiology-examinations.start', appointment.id), { preserveScroll: true });
    };

    return (
        <Dialog>
            <DialogTrigger asChild>{trigger}</DialogTrigger>
            <DialogContent>
                <form onSubmit={submit}>
                    <DialogHeader>
                        <DialogTitle>Start examination</DialogTitle>
                        <DialogDescription>Confirm the patient and procedure before beginning.</DialogDescription>
                    </DialogHeader>

                    <dl className="grid grid-cols-2 gap-2 py-4 text-sm">
                        <dt className="text-muted-foreground">Patient</dt>
                        <dd className="text-right font-medium">{patientName}</dd>
                        <dt className="text-muted-foreground">Patient Number</dt>
                        <dd className="text-right">{appointment.patient?.patient_number ?? '—'}</dd>
                        <dt className="text-muted-foreground">Date of Birth</dt>
                        <dd className="text-right">{appointment.patient?.date_of_birth ?? '—'}</dd>
                        <dt className="text-muted-foreground">Sex</dt>
                        <dd className="text-right">{appointment.patient?.gender ?? '—'}</dd>
                        <dt className="text-muted-foreground">Procedure</dt>
                        <dd className="text-right">{appointment.item?.procedure_name ?? '—'}</dd>
                        <dt className="text-muted-foreground">Order Number</dt>
                        <dd className="text-right">{appointment.order?.order_number ?? '—'}</dd>
                    </dl>

                    <div className="grid gap-2 pb-2">
                        <Label htmlFor="exam_resource_name">Resource / Room</Label>
                        <Input id="exam_resource_name" value={data.resource_name} onChange={(e) => setData('resource_name', e.target.value)} />
                        <InputError message={errors.resource_name} />
                    </div>

                    <DialogFooter>
                        <Button type="submit" disabled={processing}>
                            {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                            Start Examination
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
