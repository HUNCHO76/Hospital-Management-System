import { RadiologyImageViewer } from '@/components/radiology/radiology-image-viewer';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Separator } from '@/components/ui/separator';
import {
    radiologyBodySiteLabel,
    radiologyModalityLabel,
    radiologyReportStatusLabel,
    radiologyReportStatusVariant,
} from '@/lib/radiology-status';
import { type RadiologyImage, type RadiologyModality, type RadiologyReportStatus } from '@/types/radiology';
import { ExternalLink, FileText, Images, Printer, User } from 'lucide-react';
import { useState } from 'react';

export interface ViewRadiologyReportData {
    id: number;
    report_number?: string;
    status: RadiologyReportStatus | string;
    technique?: string | null;
    findings?: string | null;
    impression?: string | null;
    recommendations?: string | null;
    submitted_at?: string | null;
    verified_at?: string | null;
    released_at?: string | null;
    reporting_practitioner?: {
        id?: number;
        name?: string | null;
        staff?: {
            user?: {
                UserName?: string | null;
            };
        };
    } | null;
    reportingPractitioner?: {
        id?: number;
        staff?: {
            user?: {
                UserName?: string | null;
            };
        };
    } | null;
    verifying_practitioner?: {
        id?: number;
        name?: string | null;
        staff?: {
            user?: {
                UserName?: string | null;
            };
        };
    } | null;
    verifyingPractitioner?: {
        id?: number;
        staff?: {
            user?: {
                UserName?: string | null;
            };
        };
    } | null;
    released_by?: {
        id?: number;
        UserName?: string | null;
    } | null;
    releasedBy?: {
        id?: number;
        UserName?: string | null;
    } | null;
}

export interface ViewRadiologyReportProcedure {
    procedure_name?: string;
    procedure_code?: string;
    modality?: RadiologyModality | string | null;
    body_site_snapshot?: string[] | null;
    appointment_id?: number | null;
    images?: RadiologyImage[];
}

interface ViewRadiologyReportDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    report: ViewRadiologyReportData | null;
    procedure?: ViewRadiologyReportProcedure | null;
    patientName?: string;
    patientNumber?: string;
}

function formatDateTime(value: string | null | undefined): string {
    return value ? new Date(value).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' }) : '—';
}

export function ViewRadiologyReportDialog({
    open,
    onOpenChange,
    report,
    procedure,
    patientName,
    patientNumber,
}: ViewRadiologyReportDialogProps) {
    const [viewerOpen, setViewerOpen] = useState(false);

    if (!report) return null;

    const reportingDoctor =
        report.reporting_practitioner?.name ??
        report.reporting_practitioner?.staff?.user?.UserName ??
        report.reportingPractitioner?.staff?.user?.UserName ??
        '—';

    const verifyingDoctor =
        report.verifying_practitioner?.name ??
        report.verifying_practitioner?.staff?.user?.UserName ??
        report.verifyingPractitioner?.staff?.user?.UserName ??
        '—';

    const releasedByUser = report.released_by?.UserName ?? report.releasedBy?.UserName ?? '—';

    const images = procedure?.images ?? [];
    const appointmentId = procedure?.appointment_id ?? null;

    return (
        <>
            <Dialog open={open} onOpenChange={onOpenChange}>
                <DialogContent className="max-w-2xl sm:max-w-3xl max-h-[90vh] flex flex-col p-0 overflow-hidden">
                    <DialogHeader className="p-6 pb-4 border-b bg-muted/20">
                        <div className="flex flex-wrap items-center justify-between gap-3 pr-6">
                            <div className="flex items-center gap-2">
                                <div className="h-9 w-9 rounded-lg bg-emerald-100 dark:bg-emerald-950 flex items-center justify-center text-emerald-600 dark:text-emerald-400">
                                    <FileText className="h-5 w-5" />
                                </div>
                                <div>
                                    <DialogTitle className="text-lg font-semibold flex items-center gap-2">
                                        <span>{procedure?.procedure_name ?? 'Radiology Report'}</span>
                                        {report.report_number && (
                                            <span className="text-xs font-mono font-normal text-muted-foreground">
                                                ({report.report_number})
                                            </span>
                                        )}
                                    </DialogTitle>
                                    <DialogDescription className="text-xs">
                                        {patientName && (
                                            <span className="font-medium text-foreground mr-1">
                                                Patient: {patientName} {patientNumber ? `(${patientNumber})` : ''} •
                                            </span>
                                        )}
                                        {procedure?.modality && (
                                            <span className="capitalize">
                                                {radiologyModalityLabel[procedure.modality as RadiologyModality] ?? procedure.modality}
                                            </span>
                                        )}
                                    </DialogDescription>
                                </div>
                            </div>
                            <div>
                                <Badge
                                    variant={
                                        radiologyReportStatusVariant[report.status as RadiologyReportStatus] ??
                                        (report.status === 'released' ? 'secondary' : 'default')
                                    }
                                    className="uppercase tracking-wider text-[11px] font-bold px-2.5 py-0.5"
                                >
                                    {radiologyReportStatusLabel[report.status as RadiologyReportStatus] ?? report.status}
                                </Badge>
                            </div>
                        </div>
                    </DialogHeader>

                    <div className="p-6 overflow-y-auto space-y-5 text-sm">
                        {procedure?.body_site_snapshot && procedure.body_site_snapshot.length > 0 && (
                            <div className="bg-muted/40 p-2.5 rounded-md text-xs text-muted-foreground flex items-center gap-1.5">
                                <span className="font-semibold text-foreground">Examined Region:</span>
                                <span>
                                    {procedure.body_site_snapshot
                                        .map((site) => radiologyBodySiteLabel[site as keyof typeof radiologyBodySiteLabel] ?? site)
                                        .join(', ')}
                                </span>
                            </div>
                        )}

                        {/* Findings Section */}
                        <div className="space-y-1.5">
                            <h4 className="text-xs font-bold uppercase tracking-wider text-muted-foreground">
                                Clinical Findings
                            </h4>
                            <div className="p-3.5 rounded-lg border bg-card text-foreground whitespace-pre-wrap leading-relaxed">
                                {report.findings || <span className="text-muted-foreground italic">No findings recorded.</span>}
                            </div>
                        </div>

                        {/* Impression / Conclusion Section */}
                        <div className="space-y-1.5">
                            <h4 className="text-xs font-bold uppercase tracking-wider text-emerald-700 dark:text-emerald-400">
                                Impression / Conclusion
                            </h4>
                            <div className="p-3.5 rounded-lg border border-emerald-200 dark:border-emerald-800 bg-emerald-50/50 dark:bg-emerald-950/30 text-foreground font-medium whitespace-pre-wrap leading-relaxed">
                                {report.impression || <span className="text-muted-foreground italic font-normal">No conclusion recorded.</span>}
                            </div>
                        </div>

                        {/* Technique & Recommendations Grid */}
                        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div className="space-y-1">
                                <h4 className="text-xs font-bold uppercase tracking-wider text-muted-foreground">Technique</h4>
                                <div className="p-3 rounded-md border bg-muted/20 text-xs whitespace-pre-wrap">
                                    {report.technique || 'Standard examination protocol.'}
                                </div>
                            </div>

                            <div className="space-y-1">
                                <h4 className="text-xs font-bold uppercase tracking-wider text-muted-foreground">Recommendations</h4>
                                <div className="p-3 rounded-md border bg-muted/20 text-xs whitespace-pre-wrap">
                                    {report.recommendations || 'Clinical correlation recommended.'}
                                </div>
                            </div>
                        </div>

                        {/* Attached Images */}
                        {images.length > 0 && appointmentId && (
                            <div className="pt-2">
                                <div className="flex items-center justify-between p-3 rounded-lg border bg-blue-50/50 dark:bg-blue-950/20 border-blue-200 dark:border-blue-900">
                                    <div className="flex items-center gap-2">
                                        <Images className="h-5 w-5 text-blue-600 dark:text-blue-400" />
                                        <div>
                                            <p className="text-xs font-semibold text-blue-950 dark:text-blue-100">
                                                {images.length} Medical {images.length === 1 ? 'Scan / Image' : 'Scans / Images'} Attached
                                            </p>
                                            <p className="text-[11px] text-muted-foreground">
                                                Available for clinical review and inspection.
                                            </p>
                                        </div>
                                    </div>
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="outline"
                                        className="h-8 gap-1.5 border-blue-300 text-blue-700 hover:bg-blue-100 dark:hover:bg-blue-900"
                                        onClick={() => setViewerOpen(true)}
                                    >
                                        <Images className="h-3.5 w-3.5" />
                                        <span>View Scans</span>
                                    </Button>
                                </div>
                            </div>
                        )}

                        <Separator />

                        {/* Practitioners / Sign-off Footer */}
                        <div className="grid grid-cols-1 sm:grid-cols-3 gap-3 text-xs text-muted-foreground bg-muted/20 p-3 rounded-lg border">
                            <div className="space-y-0.5">
                                <span className="font-semibold text-foreground flex items-center gap-1">
                                    <User className="h-3 w-3" /> Reported By
                                </span>
                                <p className="font-medium text-foreground">{reportingDoctor}</p>
                                <p className="text-[11px]">{formatDateTime(report.submitted_at)}</p>
                            </div>
                            <div className="space-y-0.5">
                                <span className="font-semibold text-foreground flex items-center gap-1">
                                    <User className="h-3 w-3" /> Verified By
                                </span>
                                <p className="font-medium text-foreground">{verifyingDoctor}</p>
                                <p className="text-[11px]">{formatDateTime(report.verified_at)}</p>
                            </div>
                            <div className="space-y-0.5">
                                <span className="font-semibold text-foreground flex items-center gap-1">
                                    <User className="h-3 w-3" /> Released By
                                </span>
                                <p className="font-medium text-foreground">{releasedByUser}</p>
                                <p className="text-[11px]">{formatDateTime(report.released_at)}</p>
                            </div>
                        </div>
                    </div>

                    <DialogFooter className="p-4 border-t bg-muted/10 flex sm:justify-between items-center gap-2">
                        <div className="flex items-center gap-2">
                            {report.id && (
                                <Button
                                    asChild
                                    size="sm"
                                    variant="outline"
                                    className="h-8 text-xs gap-1"
                                >
                                    <a href={route('radiology-results.show', report.id)} target="_blank" rel="noopener noreferrer">
                                        <ExternalLink className="h-3.5 w-3.5" />
                                        Full Report Page
                                    </a>
                                </Button>
                            )}
                        </div>
                        <div className="flex items-center gap-2">
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                className="h-8 text-xs gap-1"
                                onClick={() => window.print()}
                            >
                                <Printer className="h-3.5 w-3.5" />
                                Print
                            </Button>
                            <Button
                                type="button"
                                size="sm"
                                className="h-8 text-xs"
                                onClick={() => onOpenChange(false)}
                            >
                                Close
                            </Button>
                        </div>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {viewerOpen && images.length > 0 && appointmentId && (
                <RadiologyImageViewer
                    appointmentId={appointmentId}
                    images={images}
                    initialIndex={0}
                    open={viewerOpen}
                    onOpenChange={setViewerOpen}
                    caption={procedure?.procedure_name}
                />
            )}
        </>
    );
}
