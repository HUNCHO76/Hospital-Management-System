import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { type ScheduleRadiologyOrderItemFormData, type UnscheduledRadiologyOrderItem } from '@/types/radiology';
import { useForm } from '@inertiajs/react';
import { Calendar, Clock, LoaderCircle, Zap } from 'lucide-react';
import { useEffect, useState, type FormEventHandler, type ReactNode } from 'react';

interface ScheduleRadiologyItemDialogProps {
    item: UnscheduledRadiologyOrderItem;
    knownResources: string[];
    trigger: ReactNode;
}

function getTodayDateString(): string {
    const d = new Date();
    const year = d.getFullYear();
    const month = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
}

function getCurrentTimeString(roundMinutes = 5): string {
    const d = new Date();
    let minutes = d.getMinutes();
    let hours = d.getHours();

    const remainder = minutes % roundMinutes;
    if (remainder !== 0) {
        minutes += (roundMinutes - remainder);
        if (minutes >= 60) {
            minutes = 0;
            hours = (hours + 1) % 24;
        }
    }

    return `${String(hours).padStart(2, '0')}:${String(minutes).padStart(2, '0')}`;
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
 * Radiology Appointment Scheduling Dialog.
 * Supports both same-day immediate walk-in testing and scheduling for future dates.
 * Automates the calculation of end-time on the same day based on selected duration
 * to prevent validation errors where end-date is before start-date.
 */
export function ScheduleRadiologyItemDialog({ item, knownResources, trigger }: ScheduleRadiologyItemDialogProps) {
    const today = getTodayDateString();
    const defaultTime = getCurrentTimeString(5);

    const [appointmentDate, setAppointmentDate] = useState<string>(today);
    const [startTime, setStartTime] = useState<string>(defaultTime);
    const [duration, setDuration] = useState<number>(30);
    const [isSameDayNow, setIsSameDayNow] = useState<boolean>(true);

    const { data, setData, post, processing, errors, reset } = useForm<ScheduleRadiologyOrderItemFormData>({
        radiology_order_item_id: item.id,
        resource_name: knownResources.length === 1 ? knownResources[0] : '',
        scheduled_start: `${today}T${defaultTime}`,
        scheduled_end: computeEndDateTime(today, defaultTime, 30),
        notes: '',
    });

    // Keep scheduled_start and scheduled_end synced whenever date, time or duration changes
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

    const setTestNow = () => {
        const currentToday = getTodayDateString();
        const currentTime = getCurrentTimeString(5);
        setAppointmentDate(currentToday);
        setStartTime(currentTime);
        setDuration(30);
        setIsSameDayNow(true);
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('radiology-scheduling.store'), {
            preserveScroll: true,
            onSuccess: () => reset(),
        });
    };

    const displaySummary = formatDisplayTime(appointmentDate, startTime, duration);

    return (
        <Dialog>
            <DialogTrigger asChild>{trigger}</DialogTrigger>
            <DialogContent className="max-w-md">
                <form onSubmit={submit}>
                    <DialogHeader>
                        <DialogTitle>Schedule {item.procedure_name}</DialogTitle>
                        <DialogDescription>
                            {item.order?.order_number} — {item.patient ? `${item.patient.first_name} ${item.patient.last_name}` : 'Unknown patient'}
                            {item.modality_snapshot ? ` — ${item.modality_snapshot.toUpperCase()}` : ''}
                        </DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-4 py-4">
                        {/* Quick Presets: Test Today vs Schedule Later */}
                        <div className="flex gap-2 p-1 bg-muted/40 rounded-lg border">
                            <button
                                type="button"
                                className={`flex-1 py-1.5 px-3 rounded-md text-xs font-medium flex items-center justify-center gap-1.5 transition-colors ${
                                    isSameDayNow
                                        ? 'bg-blue-600 text-white shadow-sm'
                                        : 'text-muted-foreground hover:text-foreground'
                                }`}
                                onClick={setTestNow}
                            >
                                <Zap className="h-3.5 w-3.5" />
                                Test Today (Immediate / Walk-in)
                            </button>
                            <button
                                type="button"
                                className={`flex-1 py-1.5 px-3 rounded-md text-xs font-medium flex items-center justify-center gap-1.5 transition-colors ${
                                    !isSameDayNow
                                        ? 'bg-blue-600 text-white shadow-sm'
                                        : 'text-muted-foreground hover:text-foreground'
                                }`}
                                onClick={() => setIsSameDayNow(false)}
                            >
                                <Calendar className="h-3.5 w-3.5" />
                                Schedule Future Date
                            </button>
                        </div>

                        {/* Resource / Room */}
                        <div className="grid gap-2">
                            <Label htmlFor="resource_name">Resource / Imaging Room *</Label>
                            <Input
                                id="resource_name"
                                list="known-radiology-resources"
                                value={data.resource_name}
                                onChange={(e) => setData('resource_name', e.target.value)}
                                placeholder="e.g. Ultrasound Room 1, X-Ray Room 2"
                                autoFocus
                            />
                            <datalist id="known-radiology-resources">
                                {knownResources.map((resource) => (
                                    <option key={resource} value={resource} />
                                ))}
                            </datalist>
                            <InputError message={errors.resource_name} />
                        </div>

                        {/* Appointment Date */}
                        <div className="grid gap-2">
                            <Label htmlFor="schedule_date">Appointment Date *</Label>
                            <Input
                                id="schedule_date"
                                type="date"
                                min={today}
                                value={appointmentDate}
                                onChange={(e) => {
                                    setAppointmentDate(e.target.value);
                                    if (e.target.value !== today) {
                                        setIsSameDayNow(false);
                                    }
                                }}
                            />
                            <InputError message={errors.scheduled_start} />
                        </div>

                        {/* Time & Duration on Same Day */}
                        <div className="grid grid-cols-2 gap-3">
                            <div className="grid gap-2">
                                <Label htmlFor="schedule_start_time">Start Time *</Label>
                                <Input
                                    id="schedule_start_time"
                                    type="time"
                                    value={startTime}
                                    onChange={(e) => {
                                        setStartTime(e.target.value);
                                        setIsSameDayNow(false);
                                    }}
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

                        {/* Notes */}
                        <div className="grid gap-2">
                            <Label htmlFor="schedule_notes">Special Instructions / Notes</Label>
                            <Textarea
                                id="schedule_notes"
                                rows={2}
                                value={data.notes}
                                onChange={(e) => setData('notes', e.target.value)}
                                placeholder="Optional instructions for the radiographer or patient..."
                            />
                            <InputError message={errors.notes} />
                        </div>
                    </div>

                    <DialogFooter>
                        <Button type="submit" disabled={processing} className="bg-blue-600 hover:bg-blue-700 text-white">
                            {processing && <LoaderCircle className="h-4 w-4 animate-spin mr-1.5" />}
                            {isSameDayNow && appointmentDate === today ? 'Confirm & Perform Today' : 'Confirm Schedule'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
