import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'NHIF', href: '/nhif/dashboard' },
    { title: 'Claims', href: '/nhif/claims' },
    { title: 'View Claim', href: '#' },
];

const statusColors: Record<string, string> = {
    draft: 'bg-gray-100 text-gray-700',
    validated: 'bg-blue-100 text-blue-700',
    ready: 'bg-cyan-100 text-cyan-700',
    signed: 'bg-indigo-100 text-indigo-700',
    submitted: 'bg-yellow-100 text-yellow-700',
    confirmed: 'bg-teal-100 text-teal-700',
    finalized: 'bg-emerald-100 text-emerald-700',
    accepted: 'bg-green-100 text-green-700',
    rejected: 'bg-red-100 text-red-700',
    reconciled: 'bg-purple-100 text-purple-700',
    cancelled: 'bg-gray-100 text-gray-500',
    signing_failed: 'bg-rose-100 text-rose-700',
    submission_failed: 'bg-rose-100 text-rose-700',
    bill_confirmation_failed: 'bg-amber-100 text-amber-700',
};

interface ClaimItem { id: number; line_no: number; module: string; nhif_item_code: string; service_date: string | null; quantity: number; hms_amount: number | null; nhif_allowable_amount: number | null; utilization_number: string | null }
interface Document { id: number; original_name: string; size_bytes: number | null; created_at: string }
interface SubmissionAttempt { id: number; attempt_number: number; submission_status: string; submitted_at: string | null }

interface Claim {
    id: number;
    claim_number: string | null;
    status: string;
    claim_type: string;
    folio_no: string | null;
    attendance_date: string | null;
    primary_diagnosis_code: string | null;
    primary_diagnosis_description: string | null;
    hms_total: number | null;
    nhif_allowable_total: number | null;
    nhif_approved_total: number | null;
    nhif_rejected_total: number | null;
    validation_summary: Record<string, unknown> | null;
    validated_at: string | null;
    signed_at: string | null;
    confirmation_code: string | null;
    cancellation_reason: string | null;
    cancelled_at: string | null;
    is_submittable: boolean;
    is_cancelled: boolean;
    hospital: { id: number; name: string } | null;
    patient: { id: number; name: string } | null;
    patient_insurance: { id: number; membership_number: string } | null;
    items: ClaimItem[];
    documents: Document[];
    submission_attempts: SubmissionAttempt[];
    updated_at: string;
}

interface Props { claim: Claim }

export default function ClaimShow({ claim }: Props) {
    const cancelForm = useForm({ cancellation_reason: '' });

    const submit = () => {
        if (confirm('Submit this claim to NHIF?')) {
            router.post(route('nhif.claims.submit', claim.id));
        }
    };

    const validate = () => router.post(route('nhif.claims.validate', claim.id));
    const finalize = () => router.post(route('nhif.claims.finalize', claim.id));
    const sign = () => router.post(route('nhif.claims.sign', claim.id), { signature_method: 'Manual' });
    const retryBillConfirmation = () => router.post(route('nhif.claims.retry-bill-confirmation', claim.id));

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Claim ${claim.claim_number ?? claim.id}`} />

            <div className="mx-auto max-w-4xl p-4">
                <div className="mb-6 flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <h1 className="text-xl font-semibold">
                            Claim {claim.claim_number ?? `#${claim.id}`}
                        </h1>
                        <span className={`mt-1 inline-block rounded-full px-2 py-0.5 text-xs font-medium ${statusColors[claim.status] ?? ''}`}>
                            {claim.status}
                        </span>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        {claim.status === 'draft' && <Button size="sm" onClick={validate}>Validate</Button>}
                        {claim.status === 'validated' && <Button size="sm" onClick={finalize}>Finalize</Button>}
                        {(claim.status === 'ready' || claim.status === 'signing_failed') && (
                            <Button size="sm" variant="outline" onClick={sign}>Sign Folio</Button>
                        )}
                        {claim.is_submittable && <Button size="sm" onClick={submit}>Submit to NHIF</Button>}
                        {(claim.status === 'submitted' || claim.status === 'bill_confirmation_failed') && (
                            <Button size="sm" className="bg-emerald-600 hover:bg-emerald-700 text-white" onClick={retryBillConfirmation}>
                                Request Bill Confirmation
                            </Button>
                        )}
                        {!claim.is_cancelled && (
                            <Button size="sm" variant="destructive" onClick={() => {
                                const reason = prompt('Cancellation reason:');
                                if (reason) {
                                    router.post(route('nhif.claims.cancel', claim.id), { cancellation_reason: reason });
                                }
                            }}>Cancel</Button>
                        )}
                    </div>
                </div>

                <div className="grid grid-cols-2 gap-4 text-sm">
                    <div className="space-y-1 rounded-md border p-4">
                        <p className="font-medium">Claim Details</p>
                        <dl className="space-y-1">
                            <Detail label="Type" value={claim.claim_type} />
                            <Detail label="Folio" value={claim.folio_no ?? '—'} />
                            <Detail label="Attendance Date" value={claim.attendance_date ?? '—'} />
                            <Detail label="Diagnosis" value={claim.primary_diagnosis_code ? `${claim.primary_diagnosis_code} — ${claim.primary_diagnosis_description ?? ''}` : '—'} />
                        </dl>
                    </div>
                    <div className="space-y-1 rounded-md border p-4">
                        <p className="font-medium">Amounts (TZS)</p>
                        <dl className="space-y-1">
                            <Detail label="HMS Total" value={claim.hms_total != null ? Number(claim.hms_total).toLocaleString() : '—'} />
                            <Detail label="NHIF Allowable" value={claim.nhif_allowable_total != null ? Number(claim.nhif_allowable_total).toLocaleString() : '—'} />
                            <Detail label="NHIF Approved" value={claim.nhif_approved_total != null ? Number(claim.nhif_approved_total).toLocaleString() : '—'} />
                            <Detail label="NHIF Rejected" value={claim.nhif_rejected_total != null ? Number(claim.nhif_rejected_total).toLocaleString() : '—'} />
                        </dl>
                    </div>
                </div>

                <div className="mt-6">
                    <h2 className="mb-2 text-sm font-medium">Claim Items ({claim.items.length})</h2>
                    <div className="rounded-md border">
                        <table className="w-full text-sm">
                            <thead className="border-b bg-muted/40">
                                <tr>
                                    <th className="px-3 py-2 text-left font-medium">#</th>
                                    <th className="px-3 py-2 text-left font-medium">Module</th>
                                    <th className="px-3 py-2 text-left font-medium">Code</th>
                                    <th className="px-3 py-2 text-left font-medium">Date</th>
                                    <th className="px-3 py-2 text-right font-medium">Qty</th>
                                    <th className="px-3 py-2 text-right font-medium">HMS Amt</th>
                                    <th className="px-3 py-2 text-right font-medium">Allowable</th>
                                    {(claim.status === 'draft' || claim.status === 'validated') && <th className="px-3 py-2" />}
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {claim.items.length === 0 ? (
                                    <tr><td colSpan={8} className="px-4 py-4 text-center text-muted-foreground">No items.</td></tr>
                                ) : (
                                    claim.items.map((item) => (
                                        <tr key={item.id}>
                                            <td className="px-3 py-2">{item.line_no}</td>
                                            <td className="px-3 py-2 capitalize">{item.module}</td>
                                            <td className="px-3 py-2 font-mono text-xs">{item.nhif_item_code}</td>
                                            <td className="px-3 py-2 text-xs">{item.service_date ?? '—'}</td>
                                            <td className="px-3 py-2 text-right">{item.quantity}</td>
                                            <td className="px-3 py-2 text-right">{item.hms_amount != null ? Number(item.hms_amount).toLocaleString() : '—'}</td>
                                            <td className="px-3 py-2 text-right">{item.nhif_allowable_amount != null ? Number(item.nhif_allowable_amount).toLocaleString() : '—'}</td>
                                            {(claim.status === 'draft' || claim.status === 'validated') && (
                                                <td className="px-3 py-2">
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        onClick={() => router.delete(route('nhif.claims.items.destroy', [claim.id, item.id]))}
                                                    >
                                                        Remove
                                                    </Button>
                                                </td>
                                            )}
                                        </tr>
                                    ))
                                )}
                            </tbody>
                        </table>
                    </div>
                </div>

                {claim.submission_attempts.length > 0 && (
                    <div className="mt-6">
                        <h2 className="mb-2 text-sm font-medium">Submission Attempts</h2>
                        <div className="rounded-md border">
                            <table className="w-full text-sm">
                                <thead className="border-b bg-muted/40">
                                    <tr>
                                        <th className="px-4 py-2 text-left font-medium">Attempt</th>
                                        <th className="px-4 py-2 text-left font-medium">Status</th>
                                        <th className="px-4 py-2 text-left font-medium">Submitted At</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {claim.submission_attempts.map((a) => (
                                        <tr key={a.id}>
                                            <td className="px-4 py-2">{a.attempt_number}</td>
                                            <td className="px-4 py-2 capitalize">{a.submission_status}</td>
                                            <td className="px-4 py-2 text-xs">{a.submitted_at ? new Date(a.submitted_at).toLocaleString() : '—'}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </div>
                )}

                <div className="mt-4 flex gap-2">
                    <Button variant="ghost" asChild>
                        <Link href={route('nhif.claims.index')}>← Back to claims</Link>
                    </Button>
                    <Button variant="outline" asChild>
                        <Link href={route('nhif.claims.reconciliation.show', claim.id)}>Reconciliation</Link>
                    </Button>
                </div>
            </div>
        </AppLayout>
    );
}

function Detail({ label, value }: { label: string; value: React.ReactNode }) {
    return (
        <div className="flex gap-2">
            <dt className="w-32 text-muted-foreground">{label}:</dt>
            <dd>{value}</dd>
        </div>
    );
}
