import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { Spinner } from '@/components/ui/spinner';
import { useDebouncedValue } from '@/hooks/use-debounced-value';
import { type MedicationSearchResult, type NhifMedicationCatalogItem } from '@/types/medication';
import { ChevronsUpDown, Pill, Search } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

interface MedicationSearchProps {
    selected?: MedicationSearchResult | null;
    onSelect: (medication: MedicationSearchResult) => void;
    disabled?: boolean;
    clinicalEncounterId?: number | null;
}

/**
 * A search-and-select combobox for picking a medication — copies
 * DiagnosisCodeSearch's structure exactly (debounce, 2-char minimum, a
 * request-id guard against stale responses, loading/empty states). Queries
 * the lightweight `/medications/search-json` endpoint rather than loading
 * the catalog client-side (spec §11/§36).
 *
 * For NHIF encounters, also shows matching items from the NHIF catalog
 * (schemes_services) — mirrors LaboratoryTestSearch's dual-list pattern.
 */
export function MedicationSearch({ selected, onSelect, disabled, clinicalEncounterId }: MedicationSearchProps) {
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const [results, setResults] = useState<MedicationSearchResult[]>([]);
    const [nhifCatalog, setNhifCatalog] = useState<NhifMedicationCatalogItem[]>([]);
    const [loading, setLoading] = useState(false);
    const [resolving, setResolving] = useState<string | null>(null);
    const debouncedQuery = useDebouncedValue(query, 300);
    const requestId = useRef(0);

    useEffect(() => {
        if (debouncedQuery.trim().length < 2) {
            setResults([]);
            setNhifCatalog([]);

            return;
        }

        const thisRequest = ++requestId.current;
        setLoading(true);

        const params: Record<string, string | number> = { search: debouncedQuery };
        if (clinicalEncounterId) {
            params.clinical_encounter_id = clinicalEncounterId;
        }

        fetch(route('medications.search-json', params), {
            headers: { Accept: 'application/json' },
        })
            .then((response) => response.json())
            .then((payload: { data: MedicationSearchResult[]; nhif_catalog: NhifMedicationCatalogItem[] }) => {
                if (thisRequest === requestId.current) {
                    setResults(payload.data);
                    setNhifCatalog(payload.nhif_catalog ?? []);
                }
            })
            .finally(() => {
                if (thisRequest === requestId.current) {
                    setLoading(false);
                }
            });
    }, [debouncedQuery, clinicalEncounterId]);

    const selectNhifItem = (item: NhifMedicationCatalogItem) => {
        if (item.local_medication) {
            onSelect({
                id: item.local_medication.id,
                code: item.local_medication.code,
                generic_name: item.local_medication.generic_name,
                brand_name: null,
                strength: null,
                form: null,
            });
            setOpen(false);
            setQuery('');

            return;
        }

        setResolving(item.item_code);

        fetch(route('medications.resolve-nhif-item', { item_code: item.item_code, item_name: item.item_name }), {
            headers: { Accept: 'application/json' },
        })
            .then((r) => r.json())
            .then((med: MedicationSearchResult) => {
                onSelect(med);
                setOpen(false);
                setQuery('');
            })
            .finally(() => setResolving(null));
    };

    const label = selected ? selected.generic_name : 'Search medication by name';

    const localMedIds = new Set(results.map((m) => m.id));
    const filteredCatalog = nhifCatalog.filter((item) => !item.local_medication || !localMedIds.has(item.local_medication.id));

    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger asChild>
                <Button
                    type="button"
                    variant="outline"
                    role="combobox"
                    aria-expanded={open}
                    disabled={disabled}
                    className="w-full justify-between font-normal"
                >
                    <span className="flex items-center gap-2 truncate">
                        <Pill className="text-muted-foreground h-4 w-4 shrink-0" />
                        <span className="truncate">{label}</span>
                    </span>
                    <ChevronsUpDown className="h-4 w-4 shrink-0 opacity-50" />
                </Button>
            </PopoverTrigger>
            <PopoverContent className="w-[--radix-popover-trigger-width] p-2" align="start">
                <div className="relative mb-2">
                    <Search className="text-muted-foreground pointer-events-none absolute top-1/2 left-2 h-4 w-4 -translate-y-1/2" />
                    <Input
                        autoFocus
                        value={query}
                        onChange={(e) => setQuery(e.target.value)}
                        placeholder="Medication name"
                        className="pl-8"
                        aria-label="Search medications"
                    />
                </div>

                <div className="max-h-64 overflow-y-auto">
                    {loading && (
                        <div className="flex items-center justify-center py-4">
                            <Spinner label="Searching" />
                        </div>
                    )}

                    {!loading && query.trim().length >= 2 && results.length === 0 && filteredCatalog.length === 0 && (
                        <p className="text-muted-foreground px-2 py-4 text-center text-sm">No matching medications found.</p>
                    )}

                    {!loading && query.trim().length < 2 && (
                        <p className="text-muted-foreground px-2 py-4 text-center text-sm">Type at least 2 characters to search.</p>
                    )}

                    {results.map((medication) => (
                        <button
                            key={medication.id}
                            type="button"
                            onClick={() => {
                                onSelect(medication);
                                setOpen(false);
                                setQuery('');
                            }}
                            className="hover:bg-accent hover:text-accent-foreground flex w-full flex-col items-start rounded-sm px-2 py-1.5 text-left text-sm"
                        >
                            <span className="flex items-center gap-2">
                                <span className="font-medium">
                                    {medication.generic_name}
                                    {medication.brand_name && <span className="text-muted-foreground"> ({medication.brand_name})</span>}
                                </span>
                                {medication.nhif_code && (
                                    <Badge variant="outline" className="border-green-300 px-1 py-0 text-[10px] text-green-700">
                                        NHIF
                                    </Badge>
                                )}
                            </span>
                            {(medication.strength || medication.form) && (
                                <span className="text-muted-foreground text-xs">{[medication.strength, medication.form].filter(Boolean).join(' · ')}</span>
                            )}
                        </button>
                    ))}

                    {filteredCatalog.length > 0 && (
                        <>
                            <div className="my-1 border-t px-2 pt-1.5">
                                <span className="text-muted-foreground text-xs font-medium">NHIF Covered Medications</span>
                            </div>
                            {filteredCatalog.map((item) => (
                                <button
                                    key={item.item_code}
                                    type="button"
                                    disabled={resolving === item.item_code}
                                    onClick={() => selectNhifItem(item)}
                                    className="hover:bg-accent hover:text-accent-foreground flex w-full flex-col items-start rounded-sm px-2 py-1.5 text-left text-sm"
                                >
                                    <span className="flex items-center gap-2">
                                        <span className="font-medium">
                                            {item.item_code} — {item.item_name}
                                        </span>
                                        {resolving === item.item_code ? (
                                            <Spinner className="h-3 w-3" />
                                        ) : (
                                            <Badge variant="outline" className="border-green-300 px-1 py-0 text-[10px] text-green-700">
                                                NHIF
                                            </Badge>
                                        )}
                                    </span>
                                    {item.local_medication && (
                                        <span className="text-muted-foreground text-xs">
                                            → {item.local_medication.code} — {item.local_medication.generic_name}
                                        </span>
                                    )}
                                </button>
                            ))}
                        </>
                    )}
                </div>
            </PopoverContent>
        </Popover>
    );
}
