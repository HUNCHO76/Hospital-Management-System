import { Can } from '@/components/authorization/can';
import { Button } from '@/components/ui/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { appointmentStatusColor, appointmentStatusLabel } from '@/lib/appointment-status';
import { type BreadcrumbItem } from '@/types';
import { type Appointment, type AppointmentDepartment, type AppointmentPractitioner } from '@/types/appointment';
import { Head, Link, router } from '@inertiajs/react';
import { addDays, addWeeks, endOfWeek, format, getDay, parse, startOfWeek, subWeeks } from 'date-fns';
import { enUS } from 'date-fns/locale/en-US';
import { CalendarPlus, List } from 'lucide-react';
import { useMemo, useState } from 'react';
import { Calendar, dateFnsLocalizer, type View, Views } from 'react-big-calendar';
import 'react-big-calendar/lib/css/react-big-calendar.css';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Appointments', href: '/appointments' },
    { title: 'Calendar', href: '/appointments/calendar' },
];

const locales = { 'en-US': enUS };

const localizer = dateFnsLocalizer({
    format,
    parse,
    startOfWeek: () => startOfWeek(new Date(), { weekStartsOn: 1 }),
    getDay,
    locales,
});

interface AppointmentCalendarEvent {
    id: number;
    title: string;
    start: Date;
    end: Date;
    appointment: Appointment;
}

interface AppointmentCalendarProps {
    appointments: Appointment[];
    range: { start: string; end: string };
    departments: AppointmentDepartment[];
    practitioners: AppointmentPractitioner[];
}

export default function AppointmentCalendar({ appointments, range }: AppointmentCalendarProps) {
    const [view, setView] = useState<View>(Views.WEEK);
    const [currentDate, setCurrentDate] = useState(new Date(range.start));

    const events: AppointmentCalendarEvent[] = useMemo(
        () =>
            appointments.map((appointment) => ({
                id: appointment.id,
                title: `${appointment.patient ? [appointment.patient.first_name, appointment.patient.last_name].filter(Boolean).join(' ') : 'Patient'} — ${appointment.practitioner?.staff ? appointment.practitioner.staff.last_name : appointment.practitioner?.practitioner_number || ''}`,
                start: new Date(appointment.scheduled_start),
                end: new Date(appointment.scheduled_end),
                appointment,
            })),
        [appointments],
    );

    const navigateTo = (date: Date, nextView: View = view) => {
        const start = nextView === Views.DAY ? date : startOfWeek(date, { weekStartsOn: 1 });
        const end = nextView === Views.DAY ? addDays(date, 1) : endOfWeek(date, { weekStartsOn: 1 });

        setCurrentDate(date);
        router.get(
            route('appointments.calendar'),
            { start: format(start, 'yyyy-MM-dd'), end: format(end, 'yyyy-MM-dd') },
            { preserveState: true, preserveScroll: true },
        );
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Appointment Calendar" />

            <div className="flex flex-col gap-4 p-4">
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-semibold">Appointment Calendar</h1>
                        <p className="text-muted-foreground text-sm">View scheduled appointments by day or week.</p>
                    </div>

                    <div className="flex gap-2">
                        <Button variant="outline" asChild>
                            <Link href={route('appointments.index')}>
                                <List className="h-4 w-4" />
                                List View
                            </Link>
                        </Button>
                        <Can permission="appointments.create">
                            <Button asChild>
                                <Link href={route('appointments.create')}>
                                    <CalendarPlus className="h-4 w-4" />
                                    Book Appointment
                                </Link>
                            </Button>
                        </Can>
                    </div>
                </div>

                <div className="flex flex-wrap items-center gap-2">
                    <Button variant="outline" size="sm" onClick={() => navigateTo(subWeeks(currentDate, view === Views.DAY ? 0 : 1))}>
                        Previous
                    </Button>
                    <Button variant="outline" size="sm" onClick={() => navigateTo(new Date())}>
                        Today
                    </Button>
                    <Button variant="outline" size="sm" onClick={() => navigateTo(addWeeks(currentDate, view === Views.DAY ? 0 : 1))}>
                        Next
                    </Button>

                    <Select
                        value={view}
                        onValueChange={(value) => {
                            setView(value as View);
                            navigateTo(currentDate, value as View);
                        }}
                    >
                        <SelectTrigger className="w-[120px]">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={Views.DAY}>Day</SelectItem>
                            <SelectItem value={Views.WEEK}>Week</SelectItem>
                        </SelectContent>
                    </Select>
                </div>

                <div className="bg-card rounded-lg border p-2" style={{ height: '70vh' }}>
                    <Calendar
                        localizer={localizer}
                        events={events}
                        date={currentDate}
                        view={view}
                        views={[Views.DAY, Views.WEEK]}
                        onView={(nextView: View) => {
                            setView(nextView);
                            navigateTo(currentDate, nextView);
                        }}
                        onNavigate={(date: Date) => navigateTo(date)}
                        startAccessor="start"
                        endAccessor="end"
                        titleAccessor="title"
                        style={{ height: '100%' }}
                        onSelectEvent={(event: AppointmentCalendarEvent) => router.get(route('appointments.show', event.appointment.id))}
                        eventPropGetter={(event: AppointmentCalendarEvent) => ({
                            style: {
                                backgroundColor: appointmentStatusColor[event.appointment.status],
                                borderColor: appointmentStatusColor[event.appointment.status],
                            },
                        })}
                    />
                </div>

                <div className="flex flex-wrap gap-3 text-xs">
                    {Object.entries(appointmentStatusLabel).map(([status, label]) => (
                        <span key={status} className="flex items-center gap-1.5">
                            <span
                                className="h-2.5 w-2.5 rounded-full"
                                style={{ backgroundColor: appointmentStatusColor[status as keyof typeof appointmentStatusColor] }}
                            />
                            {label}
                        </span>
                    ))}
                </div>
            </div>
        </AppLayout>
    );
}
