import { RadiologyImageGallery } from '@/components/radiology/radiology-image-gallery';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { radiologyTechnicalFieldLabel } from '@/lib/radiology-status';
import { type RadiologyReport } from '@/types/radiology';

interface RadiologyExaminationSummaryProps {
    report: RadiologyReport;
}

function formatDateTime(value: string | null): string {
    return value ? new Date(value).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' }) : '—';
}

/**
 * Read-only review of the Step 41 examination (Phase 5, Step 42, Part 10) —
 * the reporting practitioner reviews this information but never edits it
 * here; it is read live from the appointment, never duplicated onto the
 * report row.
 */
export function RadiologyExaminationSummary({ report }: RadiologyExaminationSummaryProps) {
    const examination = report.examination;
    const details = examination?.technical_details ?? null;

    return (
        <Card>
            <CardHeader>
                <CardTitle className="text-base">Examination</CardTitle>
            </CardHeader>
            <CardContent className="space-y-3">
                <dl className="grid grid-cols-2 gap-2 text-sm sm:grid-cols-4">
                    <dt className="text-muted-foreground">Performed By</dt>
                    <dd>{examination?.performed_by?.name ?? '—'}</dd>
                    <dt className="text-muted-foreground">Started</dt>
                    <dd>{formatDateTime(examination?.started_at ?? null)}</dd>
                    <dt className="text-muted-foreground">Completed</dt>
                    <dd>{formatDateTime(examination?.completed_at ?? null)}</dd>
                    <dt className="text-muted-foreground">Resource / Room</dt>
                    <dd>{examination?.resource_name ?? '—'}</dd>
                </dl>

                {details && Object.keys(details).length > 0 && (
                    <dl className="grid grid-cols-2 gap-2 border-t pt-3 text-sm sm:grid-cols-4">
                        {Object.entries(details).map(([key, value]) => (
                            <div key={key} className="contents">
                                <dt className="text-muted-foreground">{radiologyTechnicalFieldLabel[key] ?? key}</dt>
                                <dd>{value}</dd>
                            </div>
                        ))}
                    </dl>
                )}

                {examination?.technical_notes && (
                    <div className="border-t pt-3 text-sm">
                        <p className="text-muted-foreground">Technical Notes</p>
                        <p className="whitespace-pre-wrap">{examination.technical_notes}</p>
                    </div>
                )}

                {examination && (
                    <div className="border-t pt-3">
                        <p className="text-muted-foreground mb-2 text-sm">Imaging / X-Ray Images</p>
                        <RadiologyImageGallery
                            appointmentId={examination.id}
                            images={examination.images}
                            canManage={false}
                            caption={report.item?.procedure_name}
                        />
                    </div>
                )}
            </CardContent>
        </Card>
    );
}
