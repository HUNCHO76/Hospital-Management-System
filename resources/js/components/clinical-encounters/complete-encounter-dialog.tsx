import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { clinicalEncounterDispositionLabel } from '@/lib/clinical-encounter-status';
import { type ClinicalEncounterDisposition } from '@/types/clinical-encounter';
import { useForm } from '@inertiajs/react';
import { CalendarPlus, LoaderCircle } from 'lucide-react';
import { type FormEventHandler, type ReactNode, useState } from 'react';

interface CompleteEncounterDialogProps {
    encounterId: number;
    patientId?: number;
    departmentId?: number;
    trigger: ReactNode;
}

const DISPOSITIONS: ClinicalEncounterDisposition[] = ['completed', 'follow_up', 'referred', 'admitted', 'transferred'];

/**
 * Records the encounter's clinical outcome (spec §16/42) — both fields are
 * optional, since a simple encounter may complete with no declared outcome
 * at all (ClinicalEncounterService defaults it to "completed" server-side).
 * Choosing "Follow-up Required" surfaces a link into the existing Appointment
 * module rather than a second scheduling flow (spec §17).
 */
export function CompleteEncounterDialog({ encounterId, patientId, departmentId, trigger }: CompleteEncounterDialogProps) {
    const [open, setOpen] = useState(false);
    const { data, setData, post, processing, errors, reset } = useForm({
        disposition: '' as ClinicalEncounterDisposition | '',
        disposition_notes: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('clinical-encounters.complete', encounterId), {
            preserveScroll: true,
            onSuccess: () => {
                reset();
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
                        <DialogTitle>Complete clinical encounter</DialogTitle>
                        <DialogDescription>Optionally record the clinical outcome. Leave blank for a simple completion.</DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-4 py-4">
                        <div className="grid gap-1.5">
                            <Label>Disposition</Label>
                            <Select value={data.disposition} onValueChange={(value) => setData('disposition', value as ClinicalEncounterDisposition)}>
                                <SelectTrigger>
                                    <SelectValue placeholder="Not specified" />
                                </SelectTrigger>
                                <SelectContent>
                                    {DISPOSITIONS.map((disposition) => (
                                        <SelectItem key={disposition} value={disposition}>
                                            {clinicalEncounterDispositionLabel[disposition]}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={errors.disposition} />
                        </div>

                        <div className="grid gap-1.5">
                            <Label htmlFor="disposition_notes">Notes</Label>
                            <Textarea
                                id="disposition_notes"
                                rows={2}
                                value={data.disposition_notes}
                                onChange={(e) => setData('disposition_notes', e.target.value)}
                                placeholder="Optional"
                            />
                            <InputError message={errors.disposition_notes} />
                        </div>

                        {data.disposition === 'follow_up' && patientId && (
                            <Button asChild variant="outline" size="sm" type="button">
                                <a
                                    href={route('appointments.create', { patient_id: patientId, department_id: departmentId })}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                >
                                    <CalendarPlus className="h-4 w-4" />
                                    Schedule Follow-up Appointment
                                </a>
                            </Button>
                        )}
                    </div>

                    <DialogFooter>
                        <Button type="submit" disabled={processing}>
                            {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                            Complete encounter
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
