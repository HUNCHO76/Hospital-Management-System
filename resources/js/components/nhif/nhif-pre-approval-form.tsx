import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Separator } from '@/components/ui/separator';
import { Skeleton } from '@/components/ui/skeleton';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { type ClinicalEncounterPractitioner } from '@/types/clinical-encounter';
import { type NhifInsuranceSummary, type NhifPendingItem } from '@/types/nhif';
import { router } from '@inertiajs/react';
import { AlertTriangle, Search } from 'lucide-react';
import { useRef, useState } from 'react';

interface NhifPreApprovalFormProps {
    items: NhifPendingItem[];
    insurance: NhifInsuranceSummary;
    encounterId: number;
    patientName: string;
    practitioner: ClinicalEncounterPractitioner | null;
    sourceLabel: string;
    sourceType: 'lab' | 'medication';
}

function formatCurrency(amount: number): string {
    return new Intl.NumberFormat('en-TZ', { minimumFractionDigits: 0 }).format(amount);
}

interface CatalogSearchResult {
    item_code: string;
    item_name: string;
}

function NhifCatalogSearch({
    medicationId,
    encounterId,
    onMapped,
}: {
    medicationId: number;
    encounterId: number;
    onMapped: () => void;
}) {
    const [query, setQuery] = useState('');
    const [results, setResults] = useState<CatalogSearchResult[]>([]);
    const [searching, setSearching] = useState(false);
    const [mapping, setMapping] = useState<string | null>(null);
    const requestId = useRef(0);

    const doSearch = () => {
        if (query.trim().length < 2) return;

        const thisRequest = ++requestId.current;
        setSearching(true);

        fetch(route('medications.search-json', { search: query, clinical_encounter_id: encounterId }), {
            headers: { Accept: 'application/json' },
        })
            .then((r) => r.json())
            .then((payload: { nhif_catalog: CatalogSearchResult[] }) => {
                if (thisRequest === requestId.current) {
                    setResults(payload.nhif_catalog ?? []);
                }
            })
            .finally(() => {
                if (thisRequest === requestId.current) {
                    setSearching(false);
                }
            });
    };

    const mapItem = (catalogItem: CatalogSearchResult) => {
        setMapping(catalogItem.item_code);

        fetch(route('nhif.item-mapping.medications.update', medicationId), {
            method: 'PATCH',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': getCsrfToken() },
            body: JSON.stringify({ nhif_code: String(catalogItem.item_code) }),
        })
            .then((r) => {
                if (r.ok) {
                    onMapped();
                }
            })
            .finally(() => setMapping(null));
    };

    return (
        <div className="mt-1 space-y-1">
            <div className="flex gap-1">
                <Input
                    value={query}
                    onChange={(e) => setQuery(e.target.value)}
                    onKeyDown={(e) => e.key === 'Enter' && doSearch()}
                    placeholder="Search NHIF catalog..."
                    className="h-7 text-xs"
                />
                <Button type="button" variant="outline" size="sm" className="h-7 px-2" onClick={doSearch} disabled={searching}>
                    {searching ? <Spinner className="h-3 w-3" /> : <Search className="h-3 w-3" />}
                </Button>
            </div>
            {results.length > 0 && (
                <div className="max-h-32 overflow-y-auto rounded border bg-white text-xs dark:bg-gray-900">
                    {results.map((r) => (
                        <button
                            key={r.item_code}
                            type="button"
                            disabled={mapping === r.item_code}
                            onClick={() => mapItem(r)}
                            className="hover:bg-accent flex w-full items-center gap-2 px-2 py-1 text-left"
                        >
                            <Badge variant="outline" className="shrink-0 text-[10px]">
                                {r.item_code}
                            </Badge>
                            <span className="truncate">{r.item_name}</span>
                            {mapping === r.item_code && <Spinner className="ml-auto h-3 w-3" />}
                        </button>
                    ))}
                </div>
            )}
        </div>
    );
}

function getCsrfToken(): string {
    return document.cookie
        .split('; ')
        .find((row) => row.startsWith('XSRF-TOKEN='))
        ?.split('=')[1]
        ? decodeURIComponent(
              document.cookie
                  .split('; ')
                  .find((row) => row.startsWith('XSRF-TOKEN='))!
                  .split('=')[1],
          )
        : '';
}

export function NhifPreApprovalForm({
    items,
    insurance,
    encounterId,
    patientName,
    practitioner,
    sourceLabel,
    sourceType,
}: NhifPreApprovalFormProps) {
    const mappedItems = items.filter((i) => !i.needs_mapping);
    const unmappedItems = items.filter((i) => i.needs_mapping);

    const [selectedIds, setSelectedIds] = useState<Set<number>>(new Set(mappedItems.map((i) => i.source_id)));
    const [clinicalNotes, setClinicalNotes] = useState('');
    const [submitting, setSubmitting] = useState(false);

    if (items.length === 0) {
        return null;
    }

    const allPricesLoaded = mappedItems.every((i) => i.unit_price !== null);
    const selectedItems = mappedItems.filter((i) => selectedIds.has(i.source_id));
    const total = selectedItems.reduce((sum, i) => sum + (i.unit_price ?? 0) * i.quantity, 0);

    const selectableMappedIds = new Set(mappedItems.map((i) => i.source_id));

    const toggleItem = (sourceId: number) => {
        if (!selectableMappedIds.has(sourceId)) return;
        setSelectedIds((prev) => {
            const next = new Set(prev);
            if (next.has(sourceId)) {
                next.delete(sourceId);
            } else {
                next.add(sourceId);
            }
            return next;
        });
    };

    const toggleAll = () => {
        if (selectedIds.size === mappedItems.length) {
            setSelectedIds(new Set());
        } else {
            setSelectedIds(new Set(mappedItems.map((i) => i.source_id)));
        }
    };

    const handleSubmit = () => {
        if (selectedIds.size === 0) return;

        const data: Record<string, unknown> = {
            clinical_notes: clinicalNotes || undefined,
        };
        if (sourceType === 'lab') {
            data.lab_order_item_ids = Array.from(selectedIds);
        } else {
            data.prescription_order_item_ids = Array.from(selectedIds);
        }

        setSubmitting(true);
        router.post(route('clinical-encounters.nhif-pre-approval.store', encounterId), data, {
            preserveScroll: true,
            onFinish: () => setSubmitting(false),
        });
    };

    const handleMapped = () => {
        router.reload({ only: ['nhifPendingItems'], preserveScroll: true });
    };

    const practitionerName =
        practitioner?.name ?? (practitioner?.staff ? `${practitioner.staff.first_name} ${practitioner.staff.last_name}` : null);

    return (
        <Card>
            <CardHeader className="pb-3">
                <CardTitle className="text-base">NHIF {sourceLabel} Preapproval</CardTitle>
            </CardHeader>
            <CardContent className="space-y-4">
                {/* Patient section */}
                <div className="bg-muted/50 rounded-md border p-3">
                    <p className="text-sm font-semibold">Patient</p>
                    <p className="text-sm">{patientName}</p>
                    <div className="text-muted-foreground mt-1 flex flex-wrap gap-x-4 gap-y-1 text-sm">
                        {insurance.nhif_pf_number && <span>File No: {insurance.nhif_pf_number}</span>}
                        {insurance.nhif_scheme_name && <span>Scheme: {insurance.nhif_scheme_name}</span>}
                        {insurance.nhif_scheme_id && <span>Scheme ID: {insurance.nhif_scheme_id}</span>}
                        <span>Authorization No: {insurance.nhif_authorization_no}</span>
                    </div>
                </div>

                {/* Items table */}
                <div>
                    <p className="mb-2 text-sm font-semibold uppercase tracking-wide">{sourceLabel} Request</p>
                    <div className="overflow-x-auto rounded-md border">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="bg-muted/50 border-b text-left">
                                    <th className="p-2">
                                        <Checkbox
                                            checked={mappedItems.length > 0 && selectedIds.size === mappedItems.length}
                                            onCheckedChange={toggleAll}
                                            aria-label="Select all"
                                            disabled={mappedItems.length === 0}
                                        />
                                    </th>
                                    <th className="p-2">{sourceType === 'lab' ? 'Test' : 'Medication'}</th>
                                    <th className="p-2">ItemCode</th>
                                    <th className="p-2 text-right">Price</th>
                                    <th className="p-2 text-right">Quantity</th>
                                </tr>
                            </thead>
                            <tbody>
                                {mappedItems.map((item) => (
                                    <tr key={item.source_id} className="border-b last:border-0">
                                        <td className="p-2">
                                            <Checkbox
                                                checked={selectedIds.has(item.source_id)}
                                                onCheckedChange={() => toggleItem(item.source_id)}
                                                aria-label={`Select ${item.nhif_item_name}`}
                                            />
                                        </td>
                                        <td className="p-2">{item.nhif_item_name}</td>
                                        <td className="p-2">
                                            <Badge variant="outline">{item.nhif_item_code}</Badge>
                                        </td>
                                        <td className="p-2 text-right">
                                            {item.unit_price !== null ? (
                                                formatCurrency(item.unit_price)
                                            ) : (
                                                <Skeleton className="ml-auto h-4 w-16" />
                                            )}
                                        </td>
                                        <td className="p-2 text-right">{item.quantity}</td>
                                    </tr>
                                ))}
                                {unmappedItems.map((item) => (
                                    <tr key={`unmapped-${item.source_id}`} className="border-b bg-amber-50/50 last:border-0 dark:bg-amber-950/20">
                                        <td className="p-2">
                                            <Checkbox disabled checked={false} aria-label={`${item.nhif_item_name} — needs mapping`} />
                                        </td>
                                        <td className="p-2" colSpan={4}>
                                            <div className="flex items-center gap-2">
                                                <AlertTriangle className="h-4 w-4 shrink-0 text-amber-500" />
                                                <span>{item.nhif_item_name}</span>
                                                <Badge variant="secondary" className="text-[10px]">
                                                    No NHIF Code
                                                </Badge>
                                            </div>
                                            {item.medication_id && (
                                                <NhifCatalogSearch
                                                    medicationId={item.medication_id}
                                                    encounterId={encounterId}
                                                    onMapped={handleMapped}
                                                />
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>

                {/* Clinical Notes */}
                <div>
                    <label htmlFor={`clinical-notes-${sourceType}`} className="mb-1 block text-sm font-semibold">
                        Clinical Notes
                    </label>
                    <Textarea
                        id={`clinical-notes-${sourceType}`}
                        placeholder="Brief clinical indication..."
                        value={clinicalNotes}
                        onChange={(e) => setClinicalNotes(e.target.value)}
                        rows={2}
                    />
                </div>

                {/* Practitioner */}
                {practitionerName && (
                    <div className="text-sm">
                        <p className="font-semibold">Practitioner</p>
                        <p>{practitionerName}</p>
                        {practitioner?.nhif_practitioner_no && (
                            <p className="text-muted-foreground">NHIF Practitioner No: {practitioner.nhif_practitioner_no}</p>
                        )}
                    </div>
                )}

                <Separator />

                {/* Summary and actions */}
                <div className="flex items-center justify-between">
                    <div className="text-sm">
                        <p>
                            Selected Services: <span className="font-semibold">{selectedIds.size}</span>
                        </p>
                        <p>
                            Total NHIF Amount:{' '}
                            <span className="font-semibold">{allPricesLoaded ? `TZS ${formatCurrency(total)}` : '...'}</span>
                        </p>
                        {unmappedItems.length > 0 && (
                            <p className="mt-1 text-xs text-amber-600">
                                {unmappedItems.length} item{unmappedItems.length > 1 ? 's' : ''} need NHIF code mapping before submission.
                            </p>
                        )}
                    </div>
                    <div className="flex gap-2">
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={() => {
                                setSelectedIds(new Set());
                                setClinicalNotes('');
                            }}
                            disabled={submitting}
                        >
                            Cancel
                        </Button>
                        <Button size="sm" onClick={handleSubmit} disabled={submitting || selectedIds.size === 0 || !allPricesLoaded}>
                            {submitting ? 'Submitting...' : 'Request NHIF Preapproval'}
                        </Button>
                    </div>
                </div>
            </CardContent>
        </Card>
    );
}
