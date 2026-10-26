import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { useForm } from '@inertiajs/react';
import { Clock, LoaderCircle } from 'lucide-react';
import { useEffect, useState, type FormEventHandler, type ReactNode } from 'react';

interface RescheduleRadiologyAppointmentDialogProps {
    appointmentId: number;
    currentStart: string;
    currentEnd: string;
    trigger: ReactNode;
}

function getTodayDateString(): string {
    const d = new Date();
    const year = d.getFullYear();
    const month = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
}

function parseInitial(startStr: string, endStr: string) {
    try {
        const start = new Date(startStr.replace(/(Z|[+-]\d{2}:\d{2})$/, ''));
        const end = new Date(endStr.replace(/(Z|[+-]\d{2}:\d{2})$/, ''));
        const diffMinutes = Math.max(15, Math.round((end.getTime() - start.getTime()) / (60 * 1000)));

        const year = start.getFullYear();
        const month = String(start.getMonth() + 1).padStart(2, '0');
        const day = String(start.getDate()).padStart(2, '0');
        const hours = String(start.getHours()).padStart(2, '0');
        const minutes = String(start.getMinutes()).padStart(2, '0');

        return {
            date: `${year}-${month}-${day}`,
            time: `${hours}:${minutes}`,
            duration: diffMinutes,
        };
    } catch {
        return {
            date: getTodayDateString(),
            time: '09:00',
            duration: 30,
        };
    }
}

function computeEndDateTime(dateStr: string, timeStr: string, durationMinutes: number): string {
    if (!dateStr || !timeStr) return '';
    const [hours, minutes] = timeStr.split(':').map(Number);
    const start = new Date(`${dateStr}T${String(hours).padStart(2, '0')}:${String(minutes).padStart(2, '0')}:00`);
    const end = new Date(start.getTime() + durationMinutes * 60 * 1000);

    const endYear = end.getFullYear();
    const endMonth = String(end.getMonth() + 1).padStart(2, '0');
    const endDay = String(end.getDate()).padStart(2, '0');
    const endHours = String(end.getHours()).padStart(2, '0');
    const endMinutes = String(end.getMinutes()).padStart(2, '0');

    return `${endYear}-${endMonth}-${endDay}T${endHours}:${endMinutes}`;
}

function formatDisplayTime(dateStr: string, timeStr: string, durationMinutes: number): string {
    if (!dateStr || !timeStr) return '';
    try {
        const [hours, minutes] = timeStr.split(':').map(Number);
        const start = new Date(`${dateStr}T${String(hours).padStart(2, '0')}:${String(minutes).padStart(2, '0')}:00`);
        const end = new Date(start.getTime() + durationMinutes * 60 * 1000);

        const dateFormatted = start.toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' });
        const startTimeFormatted = start.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' });
        const endTimeFormatted = end.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' });

        return `${dateFormatted} • ${startTimeFormatted} – ${endTimeFormatted} (${durationMinutes} min on the same day)`;
    } catch {
        return '';
    }
}

/**
 * Reschedule Radiology Appointment Dialog.
 * Uses Date + Start Time + Duration selection so that the new end-time is
 * automatically calculated on the same day, avoiding end-before-start validation errors.
 */
export function RescheduleRadiologyAppointmentDialog({
    appointmentId,
    currentStart,
    currentEnd,
    trigger,
}: RescheduleRadiologyAppointmentDialogProps) {
    const today = getTodayDateString();
    const initial = parseInitial(currentStart, currentEnd);

    const [appointmentDate, setAppointmentDate] = useState<string>(initial.date);
    const [startTime, setStartTime] = useState<string>(initial.time);
    const [duration, setDuration] = useState<number>(initial.duration);

    const { data, setData, post, processing, errors, reset } = useForm({
        scheduled_start: `${initial.date}T${initial.time}`,
        scheduled_end: computeEndDateTime(initial.date, initial.time, initial.duration),
        reason: '',
    });

    useEffect(() => {
        if (appointmentDate && startTime) {
            const startIso = `${appointmentDate}T${startTime}`;
            const endIso = computeEndDateTime(appointmentDate, startTime, duration);
            setData((prev) => ({
                ...prev,
                scheduled_start: startIso,
                scheduled_end: endIso,
            }));
        }
    }, [appointmentDate, startTime, duration]);

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('radiology-scheduling.reschedule', appointmentId), {
            preserveScroll: true,
            onSuccess: () => reset('reason'),
        });
    };

    const displaySummary = formatDisplayTime(appointmentDate, startTime, duration);

    return (
        <Dialog>
            <DialogTrigger asChild>{trigger}</DialogTrigger>
            <DialogContent className="max-w-md">
                <form onSubmit={submit}>
                    <DialogHeader>
                        <DialogTitle>Reschedule Appointment</DialogTitle>
                        <DialogDescription>Select the new appointment date, start time, and duration.</DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-4 py-4">
                        {/* New Date */}
                        <div className="grid gap-2">
                            <Label htmlFor="reschedule_date">New Appointment Date *</Label>
                            <Input
                                id="reschedule_date"
                                type="date"
                                min={today}
                                value={appointmentDate}
                                onChange={(e) => setAppointmentDate(e.target.value)}
                            />
                            <InputError message={errors.scheduled_start} />
                        </div>

                        {/* Start Time & Duration */}
                        <div className="grid grid-cols-2 gap-3">
                            <div className="grid gap-2">
                                <Label htmlFor="reschedule_start_time">New Start Time *</Label>
                                <Input
                                    id="reschedule_start_time"
                                    type="time"
                                    value={startTime}
                                    onChange={(e) => setStartTime(e.target.value)}
                                />
                            </div>

                            <div className="grid gap-2">
                                <Label>Duration *</Label>
                                <div className="grid grid-cols-4 gap-1">
                                    {[15, 30, 45, 60].map((mins) => (
                                        <button
                                            key={mins}
                                            type="button"
                                            className={`py-2 px-1 text-xs rounded border font-medium transition-colors ${
                                                duration === mins
                                                    ? 'bg-blue-600 text-white border-blue-600'
                                                    : 'bg-background hover:bg-muted/50 border-input'
                                            }`}
                                            onClick={() => setDuration(mins)}
                                        >
                                            {mins}m
                                        </button>
                                    ))}
                                </div>
                            </div>
                        </div>

                        {/* Computed Timing Confirmation Badge */}
                        {displaySummary && (
                            <div className="rounded-md bg-blue-50 border border-blue-200 dark:bg-blue-950/30 dark:border-blue-900 p-2.5 text-xs text-blue-900 dark:text-blue-300 flex items-center gap-2">
                                <Clock className="h-4 w-4 shrink-0 text-blue-600 dark:text-blue-400" />
                                <span className="font-medium">{displaySummary}</span>
                            </div>
                        )}
                        <InputError message={errors.scheduled_end} />

                        {/* Reason */}
                        <div className="grid gap-2">
                            <Label htmlFor="reschedule_reason">Reason for Rescheduling *</Label>
                            <Textarea
                                id="reschedule_reason"
                                rows={2}
                                value={data.reason}
                                onChange={(e) => setData('reason', e.target.value)}
                                placeholder="State why the appointment is being moved..."
                            />
                            <InputError message={errors.reason} />
                        </div>
                    </div>

                    <DialogFooter>
                        <Button type="submit" disabled={processing} className="bg-blue-600 hover:bg-blue-700 text-white">
                            {processing && <LoaderCircle className="h-4 w-4 animate-spin mr-1.5" />}
                            Reschedule
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
