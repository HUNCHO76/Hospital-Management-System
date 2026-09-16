import { RadiologyImageViewer } from '@/components/radiology/radiology-image-viewer';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { radiologyBodySiteLabel, radiologyModalityLabel } from '@/lib/radiology-status';
import { type RadiologyResult } from '@/types/radiology';
import { Link } from '@inertiajs/react';
import { Images } from 'lucide-react';
import { useState } from 'react';

interface RadiologyResultsSectionProps {
    radiologyResults: RadiologyResult[];
}

function formatDateTime(value: string | null): string {
    return value ? new Date(value).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' }) : '—';
}

/**
 * Official released radiology results on the clinical encounter page (Phase
 * 5, Step 44, Part 15/16) — the doctor sees these automatically, without
 * needing to import or separately search for the report. Only ever
 * populated with RELEASED reports (filtered server-side in
 * ClinicalEncounterController::show() — Part 18's release boundary); a
 * draft/submitted/verified-but-not-yet-released report never appears here.
 * Deliberately separate from RadiologyOrdersSection above, which continues
 * to show plain order/item status tracking for every status.
 */
export function RadiologyResultsSection({ radiologyResults }: RadiologyResultsSectionProps) {
    const [viewingResultId, setViewingResultId] = useState<number | null>(null);
    const viewingResult = radiologyResults.find((result) => result.id === viewingResultId) ?? null;

    return (
        <Card>
            <CardHeader>
                <CardTitle className="text-base">Radiology Results</CardTitle>
            </CardHeader>
            <CardContent>
                {radiologyResults.length > 0 ? (
                    <ul className="divide-sidebar-border/70 dark:divide-sidebar-border divide-y">
                        {radiologyResults.map((result) => (
                            <li key={result.id} className="flex flex-col gap-2 py-3 first:pt-0 last:pb-0">
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <span className="font-medium">{result.procedure_name}</span>
                                        {result.modality && <Badge variant="outline">{radiologyModalityLabel[result.modality]}</Badge>}
                                        <Badge variant="secondary">Released</Badge>
                                    </div>
                                    <div className="flex items-center gap-3">
                                        {result.images.length > 0 && (
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="sm"
                                                className="h-auto p-0 text-sm"
                                                onClick={() => setViewingResultId(result.id)}
                                            >
                                                <Images className="h-4 w-4" />
                                                View X-Ray Images
                                            </Button>
                                        )}
                                        <Link href={route('radiology-results.show', result.id)} className="text-primary text-sm hover:underline">
                                            View Full Report
                                        </Link>
                                    </div>
                                </div>

                                {result.body_site_snapshot && result.body_site_snapshot.length > 0 && (
                                    <p className="text-muted-foreground text-xs">
                                        {result.body_site_snapshot.map((site) => radiologyBodySiteLabel[site]).join(', ')}
                                    </p>
                                )}

                                <dl className="space-y-1 text-sm">
                                    <div>
                                        <dt className="text-muted-foreground inline">Findings: </dt>
                                        <dd className="inline whitespace-pre-wrap">{result.findings || '—'}</dd>
                                    </div>
                                    <div>
                                        <dt className="text-muted-foreground inline">Impression: </dt>
                                        <dd className="inline whitespace-pre-wrap">{result.impression || '—'}</dd>
                                    </div>
                                    {result.recommendations && (
                                        <div>
                                            <dt className="text-muted-foreground inline">Recommendations: </dt>
                                            <dd className="inline whitespace-pre-wrap">{result.recommendations}</dd>
                                        </div>
                                    )}
                                </dl>

                                <div className="text-muted-foreground grid gap-0.5 text-xs">
                                    <span>Reported By: {result.reporting_practitioner?.name ?? '—'}</span>
                                    <span>Verified By: {result.verifying_practitioner?.name ?? '—'}</span>
                                    <span>Released: {formatDateTime(result.released_at)}</span>
                                </div>
                            </li>
                        ))}
                    </ul>
                ) : (
                    <p className="text-muted-foreground text-sm">No released radiology results yet.</p>
                )}
            </CardContent>

            {viewingResult && viewingResult.images.length > 0 && (
                <RadiologyImageViewer
                    appointmentId={viewingResult.radiology_appointment_id}
                    images={viewingResult.images}
                    initialIndex={0}
                    open={viewingResultId !== null}
                    onOpenChange={(open) => !open && setViewingResultId(null)}
                    caption={viewingResult.procedure_name ?? undefined}
                />
            )}
        </Card>
    );
}
