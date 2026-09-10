import { BrandMark } from '@/components/brand-mark';
import { Button } from '@/components/ui/button';
import { brand } from '@/lib/brand';
import { laboratorySpecimenTypeLabel } from '@/lib/laboratory-status';
import { type LaboratorySample } from '@/types/laboratory';
import { Head, router } from '@inertiajs/react';
import { Printer } from 'lucide-react';

interface LaboratorySampleLabelProps {
    sample: LaboratorySample;
}

function formatDateTime(value: string): string {
    return new Date(value).toLocaleString(undefined, { dateStyle: 'short', timeStyle: 'short' });
}

/**
 * The printable specimen label (Phase 4, Step 31, spec §22/55). Deliberately
 * bypasses AppLayout entirely — the sidebar/nav chrome has no place on a
 * physical label. `sample_number` is the one authoritative identifier
 * printed here (spec §23): it is read-only, server-generated, and unique by
 * database constraint, never editable from this page. No barcode/QR symbol
 * is rendered — no such library exists anywhere in this app yet, and adding
 * one is a dependency change outside this step's scope (see docs/
 * laboratory.md); the large monospace sample number is this step's own
 * "minimal identifier/printing mechanism" (spec §23).
 */
export default function LaboratorySampleLabel({ sample }: LaboratorySampleLabelProps) {
    const patientName = sample.patient
        ? [sample.patient.first_name, sample.patient.middle_name, sample.patient.last_name].filter(Boolean).join(' ')
        : '—';

    const print = () => {
        router.post(route('laboratory.samples.label', sample.id), {}, { preserveScroll: true, onFinish: () => window.print() });
    };

    return (
        <div className="bg-muted/30 flex min-h-screen flex-col items-center gap-4 p-6 print:bg-white print:p-0">
            <Head title={`Label — ${sample.sample_number}`} />

            <div className="w-full max-w-sm rounded-md border bg-white p-4 shadow-sm print:w-full print:max-w-none print:border-0 print:shadow-none">
                <div className="mb-2 flex items-center gap-2">
                    <BrandMark className="h-8 w-8" />
                    <span className="text-sm font-semibold">{brand.hospitalName} Hospital</span>
                </div>

                <dl className="space-y-1 text-sm text-black">
                    <div className="flex justify-between gap-2">
                        <dt className="text-muted-foreground">Patient</dt>
                        <dd className="font-medium">{patientName}</dd>
                    </div>
                    <div className="flex justify-between gap-2">
                        <dt className="text-muted-foreground">Patient No.</dt>
                        <dd>{sample.patient?.patient_number ?? '—'}</dd>
                    </div>
                    <div className="flex justify-between gap-2">
                        <dt className="text-muted-foreground">Order</dt>
                        <dd>{sample.laboratory_order?.order_number ?? '—'}</dd>
                    </div>
                    <div className="flex justify-between gap-2">
                        <dt className="text-muted-foreground">Specimen</dt>
                        <dd>{laboratorySpecimenTypeLabel[sample.specimen_type]}</dd>
                    </div>
                    <div className="flex justify-between gap-2">
                        <dt className="text-muted-foreground">Collected</dt>
                        <dd>{sample.collected_at ? formatDateTime(sample.collected_at) : 'Not yet collected'}</dd>
                    </div>
                </dl>

                <div className="mt-3 rounded border border-dashed p-2 text-center">
                    <div className="font-mono text-lg font-bold tracking-widest text-black">{sample.sample_number}</div>
                </div>
            </div>

            <Button onClick={print} className="print:hidden">
                <Printer className="h-4 w-4" />
                Print Label
            </Button>
        </div>
    );
}
