import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/react';

function formatDateTime(value: string | null | undefined): string {
    if (!value) return '—';
    return new Date(value).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });
}
import {
    AlertCircle,
    ArrowRight,
    Baby,
    Bed,
    Calendar,
    CheckCircle2,
    Clock,
    Filter,
    HeartPulse,
    Search,
    Stethoscope,
    User,
    UserCheck,
    Users,
} from 'lucide-react';
import { useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Maternity', href: '/maternity/queue' },
    { title: 'Patient Queue', href: '/maternity/queue' },
];

interface QueuePatient {
    id: number;
    patient_number: string;
    first_name: string;
    middle_name: string | null;
    last_name: string;
    phone: string | null;
    date_of_birth: string | null;
    gender: string;
}

interface QueueVisit {
    id: number;
    visit_number: string;
    patient_id: number;
    department_id: number;
    visit_type: string;
    visit_purpose: string | null;
    status: string;
    maternity_status: string | null;
    created_at: string;
    patient: QueuePatient | null;
    department?: { id: number; name: string; code?: string } | null;
    practitioner?: {
        staff?: { first_name: string; last_name: string; job_title?: string } | null;
    } | null;
    clinical_encounter?: {
        id: number;
        status: string;
        started_at: string | null;
    } | null;
}

interface MaternityQueueProps {
    visits: {
        data: QueueVisit[];
        current_page: number;
        last_page: number;
        total: number;
        links: Array<{ url: string | null; label: string; active: boolean }>;
    };
    filters: {
        date: string;
        maternity_status: string | null;
        visit_purpose: string | null;
        search: string | null;
    };
    statusCounts: Record<string, number>;
    maternityStatuses: Record<string, string>;
    visitPurposes: Record<string, string>;
}

const PURPOSE_BADGE_STYLES: Record<string, string> = {
    antenatal_care: 'border-pink-200 bg-pink-50 text-pink-700 dark:border-pink-800 dark:bg-pink-950/40 dark:text-pink-300',
    maternity_assessment: 'border-purple-200 bg-purple-50 text-purple-700 dark:border-purple-800 dark:bg-purple-950/40 dark:text-purple-300',
    labour_assessment: 'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-300',
    postnatal_care: 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300',
};

const STATUS_BADGE_STYLES: Record<string, string> = {
    waiting_for_assessment: 'border-amber-300 bg-amber-50 text-amber-800 dark:border-amber-800 dark:bg-amber-950/30 dark:text-amber-300',
    assessment_in_progress: 'border-blue-300 bg-blue-50 text-blue-800 dark:border-blue-800 dark:bg-blue-950/30 dark:text-blue-300',
    awaiting_clinician: 'border-indigo-300 bg-indigo-50 text-indigo-800 dark:border-indigo-800 dark:bg-indigo-950/30 dark:text-indigo-300',
    consultation_in_progress: 'border-teal-300 bg-teal-50 text-teal-800 dark:border-teal-800 dark:bg-teal-950/30 dark:text-teal-300',
    awaiting_investigation: 'border-orange-300 bg-orange-50 text-orange-800 dark:border-orange-800 dark:bg-orange-950/30 dark:text-orange-300',
    treatment_in_progress: 'border-cyan-300 bg-cyan-50 text-cyan-800 dark:border-cyan-800 dark:bg-cyan-950/30 dark:text-cyan-300',
    follow_up_required: 'border-yellow-300 bg-yellow-50 text-yellow-800 dark:border-yellow-800 dark:bg-yellow-950/30 dark:text-yellow-300',
    completed: 'border-emerald-300 bg-emerald-50 text-emerald-800 dark:border-emerald-800 dark:bg-emerald-950/30 dark:text-emerald-300',
    admission_recommended: 'border-rose-300 bg-rose-50 text-rose-800 dark:border-rose-800 dark:bg-rose-950/30 dark:text-rose-300',
    admitted: 'border-purple-300 bg-purple-50 text-purple-800 dark:border-purple-800 dark:bg-purple-950/30 dark:text-purple-300',
};

export default function MaternityQueue({
    visits,
    filters,
    statusCounts,
    maternityStatuses,
    visitPurposes,
}: MaternityQueueProps) {
    const [selectedVisit, setSelectedVisit] = useState<QueueVisit | null>(null);
    const [statusDialogOpen, setStatusDialogOpen] = useState(false);

    const { data, setData, post, processing, reset } = useForm({
        maternity_status: '',
        notes: '',
    });

    const openStatusDialog = (visit: QueueVisit) => {
        setSelectedVisit(visit);
        setData({
            maternity_status: visit.maternity_status || 'waiting_for_assessment',
            notes: '',
        });
        setStatusDialogOpen(true);
    };

    const handleStatusSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        if (!selectedVisit) return;

        post(route('maternity.queue.status.update', selectedVisit.id), {
            preserveScroll: true,
            onSuccess: () => {
                setStatusDialogOpen(false);
                reset();
                setSelectedVisit(null);
            },
        });
    };

    const handleFilterChange = (key: string, value: string) => {
        const newFilters = { ...filters, [key]: value || undefined };
        router.get(route('maternity.queue'), newFilters, {
            preserveState: true,
            preserveScroll: true,
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Maternity Outpatient Queue" />

            <div className="flex flex-col gap-6 p-4 sm:p-6 lg:p-8">
                {/* Header */}
                <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                    <div>
                        <div className="flex items-center gap-2">
                            <h1 className="text-2xl font-bold tracking-tight text-foreground flex items-center gap-2">
                                <Baby className="h-7 w-7 text-pink-600" />
                                Maternity Outpatient Queue
                            </h1>
                            <Badge className="bg-pink-600 text-white hover:bg-pink-700">
                                Dedicated Midwife & ANC Care
                            </Badge>
                        </div>
                        <p className="text-sm text-muted-foreground mt-1">
                            Live clinical queue for outpatient antenatal care, maternity triage, labour assessment, and postnatal monitoring without mandatory admission.
                        </p>
                    </div>

                    <div className="flex items-center gap-2">
                        <Button variant="outline" asChild>
                            <Link href="/reception/walk-in/create">
                                <UserCheck className="h-4 w-4 mr-2" />
                                Register Walk-In
                            </Link>
                        </Button>
                    </div>
                </div>

                {/* Queue Summary Cards */}
                <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
                    <Card className="border-l-4 border-l-pink-500 shadow-sm">
                        <CardHeader className="pb-2">
                            <CardDescription className="text-xs">Total Maternity Visits</CardDescription>
                            <CardTitle className="text-2xl font-bold text-pink-600">
                                {statusCounts.all ?? 0}
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <span className="text-xs text-muted-foreground">Active in outpatient queue</span>
                        </CardContent>
                    </Card>

                    <Card className="border-l-4 border-l-amber-500 shadow-sm">
                        <CardHeader className="pb-2">
                            <CardDescription className="text-xs">Waiting for Assessment</CardDescription>
                            <CardTitle className="text-2xl font-bold text-amber-600">
                                {statusCounts.waiting_for_assessment ?? 0}
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <span className="text-xs text-muted-foreground">Ready for midwife vitals</span>
                        </CardContent>
                    </Card>

                    <Card className="border-l-4 border-l-blue-500 shadow-sm">
                        <CardHeader className="pb-2">
                            <CardDescription className="text-xs">Assessment in Progress</CardDescription>
                            <CardTitle className="text-2xl font-bold text-blue-600">
                                {(statusCounts.assessment_in_progress ?? 0) + (statusCounts.consultation_in_progress ?? 0)}
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <span className="text-xs text-muted-foreground">Midwife / Clinician review</span>
                        </CardContent>
                    </Card>

                    <Card className="border-l-4 border-l-rose-500 shadow-sm">
                        <CardHeader className="pb-2">
                            <CardDescription className="text-xs">Admission Recommended</CardDescription>
                            <CardTitle className="text-2xl font-bold text-rose-600">
                                {statusCounts.admission_recommended ?? 0}
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <span className="text-xs text-muted-foreground">Clinical indication for ward</span>
                        </CardContent>
                    </Card>
                </div>

                {/* Filter and Search Bar */}
                <Card className="shadow-sm">
                    <CardContent className="p-4 space-y-3">
                        <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-3">
                            {/* Search */}
                            <div className="relative">
                                <Search className="absolute left-3 top-2.5 h-4 w-4 text-muted-foreground" />
                                <Input
                                    placeholder="Search patient, number..."
                                    className="pl-9 h-9"
                                    defaultValue={filters.search ?? ''}
                                    onKeyDown={(e) => {
                                        if (e.key === 'Enter') {
                                            handleFilterChange('search', (e.target as HTMLInputElement).value);
                                        }
                                    }}
                                />
                            </div>

                            {/* Status Filter */}
                            <Select
                                value={filters.maternity_status ?? 'all'}
                                onValueChange={(val) => handleFilterChange('maternity_status', val === 'all' ? '' : val)}
                            >
                                <SelectTrigger className="h-9">
                                    <SelectValue placeholder="All Maternity Statuses" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">All Maternity Statuses</SelectItem>
                                    {Object.entries(maternityStatuses).map(([key, label]) => (
                                        <SelectItem key={key} value={key}>
                                            {label} ({statusCounts[key] ?? 0})
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>

                            {/* Visit Purpose Filter */}
                            <Select
                                value={filters.visit_purpose ?? 'all'}
                                onValueChange={(val) => handleFilterChange('visit_purpose', val === 'all' ? '' : val)}
                            >
                                <SelectTrigger className="h-9">
                                    <SelectValue placeholder="All Visit Purposes" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">All Visit Purposes</SelectItem>
                                    {Object.entries(visitPurposes).map(([key, label]) => (
                                        <SelectItem key={key} value={key}>
                                            {label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>

                            {/* Date Filter */}
                            <div className="flex items-center gap-2">
                                <Input
                                    type="date"
                                    className="h-9"
                                    value={filters.date === 'all' ? '' : filters.date}
                                    onChange={(e) => handleFilterChange('date', e.target.value)}
                                />
                                {filters.date !== 'all' && (
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        className="text-xs h-9"
                                        onClick={() => handleFilterChange('date', 'all')}
                                    >
                                        All Dates
                                    </Button>
                                )}
                            </div>
                        </div>
                    </CardContent>
                </Card>

                {/* Queue Table */}
                <Card className="shadow-sm">
                    <CardHeader className="pb-3 border-b">
                        <div className="flex items-center justify-between">
                            <CardTitle className="text-base font-semibold flex items-center gap-2">
                                <Users className="h-5 w-5 text-pink-600" />
                                Patients in Queue ({visits.total})
                            </CardTitle>
                            <Badge variant="outline" className="text-xs">
                                Showing {visits.data.length} of {visits.total} visits
                            </Badge>
                        </div>
                    </CardHeader>
                    <CardContent className="p-0">
                        {visits.data.length === 0 ? (
                            <div className="py-12 text-center">
                                <Baby className="h-10 w-10 text-muted-foreground mx-auto mb-3 opacity-40" />
                                <h3 className="text-sm font-semibold text-foreground">No patients in maternity queue</h3>
                                <p className="text-xs text-muted-foreground mt-1 max-w-sm mx-auto">
                                    No patients currently match the selected filters. Register a walk-in or select Maternity at reception to queue a patient.
                                </p>
                            </div>
                        ) : (
                            <div className="overflow-x-auto">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Visit # & Time</TableHead>
                                            <TableHead>Patient Details</TableHead>
                                            <TableHead>Visit Purpose</TableHead>
                                            <TableHead>Assigned Clinician/Midwife</TableHead>
                                            <TableHead>Queue Status</TableHead>
                                            <TableHead className="text-right">Actions</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {visits.data.map((visit) => {
                                            const purposeLabel = visit.visit_purpose ? visitPurposes[visit.visit_purpose] ?? visit.visit_purpose : 'Maternity Care';
                                            const purposeStyle = visit.visit_purpose ? PURPOSE_BADGE_STYLES[visit.visit_purpose] : 'border-slate-200 bg-slate-50 text-slate-700';

                                            const statusKey = visit.maternity_status || 'waiting_for_assessment';
                                            const statusLabel = maternityStatuses[statusKey] ?? statusKey;
                                            const statusStyle = STATUS_BADGE_STYLES[statusKey] ?? 'border-slate-200 bg-slate-50 text-slate-700';

                                            const patientName = visit.patient
                                                ? `${visit.patient.first_name} ${visit.patient.last_name}`
                                                : 'Unknown Patient';

                                            return (
                                                <TableRow key={visit.id} className="hover:bg-muted/40">
                                                    <TableCell className="font-mono text-xs">
                                                        <div className="font-semibold text-foreground">{visit.visit_number}</div>
                                                        <div className="text-[11px] text-muted-foreground flex items-center gap-1 mt-0.5">
                                                            <Clock className="h-3 w-3" />
                                                            {formatDateTime(visit.created_at)}
                                                        </div>
                                                    </TableCell>

                                                    <TableCell>
                                                        <div className="font-medium text-sm text-foreground flex items-center gap-1.5">
                                                            <Link
                                                                href={`/patients/${visit.patient_id}`}
                                                                className="hover:underline hover:text-pink-600"
                                                            >
                                                                {patientName}
                                                            </Link>
                                                        </div>
                                                        <div className="text-xs text-muted-foreground flex items-center gap-2 mt-0.5">
                                                            <span>No: {visit.patient?.patient_number}</span>
                                                            {visit.patient?.phone && (
                                                                <>
                                                                    <span>•</span>
                                                                    <span>{visit.patient.phone}</span>
                                                                </>
                                                            )}
                                                        </div>
                                                    </TableCell>

                                                    <TableCell>
                                                        <Badge variant="outline" className={`text-xs font-medium ${purposeStyle}`}>
                                                            {purposeLabel}
                                                        </Badge>
                                                    </TableCell>

                                                    <TableCell className="text-xs">
                                                        {visit.practitioner?.staff ? (
                                                            <div>
                                                                <span className="font-medium">
                                                                    {visit.practitioner.staff.first_name} {visit.practitioner.staff.last_name}
                                                                </span>
                                                                {visit.practitioner.staff.job_title && (
                                                                    <div className="text-[11px] text-muted-foreground">
                                                                        {visit.practitioner.staff.job_title}
                                                                    </div>
                                                                )}
                                                            </div>
                                                        ) : (
                                                            <span className="text-muted-foreground italic">Pending Assignment</span>
                                                        )}
                                                    </TableCell>

                                                    <TableCell>
                                                        <Badge variant="outline" className={`text-xs font-semibold ${statusStyle}`}>
                                                            {statusLabel}
                                                        </Badge>
                                                    </TableCell>

                                                    <TableCell className="text-right">
                                                        <div className="flex items-center justify-end gap-2">
                                                            <Button
                                                                size="sm"
                                                                className="h-8 text-xs bg-pink-600 hover:bg-pink-700 text-white gap-1"
                                                                asChild
                                                            >
                                                                <Link href={route('maternity.mother-assessment', visit.id)}>
                                                                    <Stethoscope className="h-3.5 w-3.5" />
                                                                    <span>Assess Mother</span>
                                                                </Link>
                                                            </Button>

                                                            <Button
                                                                size="sm"
                                                                variant="outline"
                                                                className="h-8 text-xs gap-1 border-pink-200 hover:bg-pink-50 hover:text-pink-700 dark:border-pink-900"
                                                                onClick={() => openStatusDialog(visit)}
                                                            >
                                                                Update Status
                                                            </Button>

                                                            <Button
                                                                size="sm"
                                                                variant="ghost"
                                                                className="h-8 text-xs text-muted-foreground hover:text-foreground gap-1"
                                                                asChild
                                                            >
                                                                <Link href={route('reception.visits.show', visit.id)}>
                                                                    <span>Visit Details</span>
                                                                    <ArrowRight className="h-3 w-3" />
                                                                </Link>
                                                            </Button>
                                                        </div>
                                                    </TableCell>
                                                </TableRow>
                                            );
                                        })}
                                    </TableBody>
                                </Table>
                            </div>
                        )}
                    </CardContent>
                </Card>

                {/* Status Update Dialog */}
                <Dialog open={statusDialogOpen} onOpenChange={setStatusDialogOpen}>
                    <DialogContent className="sm:max-w-md">
                        <form onSubmit={handleStatusSubmit}>
                            <DialogHeader>
                                <DialogTitle className="flex items-center gap-2">
                                    <HeartPulse className="h-5 w-5 text-pink-600" />
                                    Update Maternity Queue Status
                                </DialogTitle>
                                <DialogDescription>
                                    Transition outpatient workflow for visit #{selectedVisit?.visit_number} ({selectedVisit?.patient?.first_name} {selectedVisit?.patient?.last_name}).
                                </DialogDescription>
                            </DialogHeader>

                            <div className="space-y-4 py-4">
                                <div className="space-y-2">
                                    <Label htmlFor="m_status">Target Queue Status *</Label>
                                    <Select
                                        value={data.maternity_status}
                                        onValueChange={(val) => setData('maternity_status', val)}
                                    >
                                        <SelectTrigger id="m_status" className="h-10">
                                            <SelectValue placeholder="Choose new status" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {Object.entries(maternityStatuses).map(([key, label]) => (
                                                <SelectItem key={key} value={key}>
                                                    {label}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor="status_notes">Clinical Handover Remarks</Label>
                                    <Textarea
                                        id="status_notes"
                                        placeholder="e.g. Assessment completed, vitals stable, awaiting lab results or doctor review"
                                        rows={3}
                                        value={data.notes}
                                        onChange={(e) => setData('notes', e.target.value)}
                                    />
                                </div>

                                {data.maternity_status === 'admission_recommended' && (
                                    <div className="rounded-lg border border-amber-300 bg-amber-50/70 p-3 dark:border-amber-900/50 dark:bg-amber-950/30 text-xs text-amber-900 dark:text-amber-200">
                                        <p className="font-semibold mb-1 flex items-center gap-1">
                                            <AlertCircle className="h-4 w-4 text-amber-600" />
                                            Inpatient Admission Pathway
                                        </p>
                                        Marking this status flags the patient for hospital admission. The clinician can proceed to bed booking only when clinically justified.
                                    </div>
                                )}
                            </div>

                            <DialogFooter>
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => setStatusDialogOpen(false)}
                                >
                                    Cancel
                                </Button>
                                <Button
                                    type="submit"
                                    disabled={processing}
                                    className="bg-pink-600 hover:bg-pink-700 text-white"
                                >
                                    Update Status
                                </Button>
                            </DialogFooter>
                        </form>
                    </DialogContent>
                </Dialog>
            </div>
        </AppLayout>
    );
}

