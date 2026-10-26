import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { useDebouncedValue } from '@/hooks/use-debounced-value';
import { type MedicationSearchResult, type NhifMedicationCatalogItem } from '@/types/medication';
import { Pill, Search, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

interface MedicationSearchProps {
    selected?: MedicationSearchResult | null;
    onSelect: (medication: MedicationSearchResult) => void;
    onClear?: () => void;
    disabled?: boolean;
    clinicalEncounterId?: number | null;
}

/**
 * A direct inline search-and-select component for picking a medication.
 * Avoids Radix Popover/Portal conflicts when rendered inside modal dialogs
 * and sheets. Renders an active search Input directly in the form DOM, with
 * an absolute dropdown and outside-click handling.
 */
export function MedicationSearch({ selected, onSelect, onClear, disabled, clinicalEncounterId }: MedicationSearchProps) {
    const [isOpen, setIsOpen] = useState(false);
    const [query, setQuery] = useState('');
    const [results, setResults] = useState<MedicationSearchResult[]>([]);
    const [nhifCatalog, setNhifCatalog] = useState<NhifMedicationCatalogItem[]>([]);
    const [loading, setLoading] = useState(false);
    const [resolving, setResolving] = useState<string | null>(null);
    const debouncedQuery = useDebouncedValue(query, 250);
    const requestId = useRef(0);
    const containerRef = useRef<HTMLDivElement>(null);

    useEffect(() => {
        if (debouncedQuery.trim().length < 2) {
            setResults([]);
            setNhifCatalog([]);
            return;
        }

        const thisRequest = ++requestId.current;
        setLoading(true);

        const params: Record<string, string | number> = { search: debouncedQuery.trim() };
        if (clinicalEncounterId) {
            params.clinical_encounter_id = clinicalEncounterId;
        }

        fetch(route('medications.search-json', params), {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        })
            .then((response) => response.json())
            .then((payload: { data: MedicationSearchResult[]; nhif_catalog?: NhifMedicationCatalogItem[] }) => {
                if (thisRequest === requestId.current) {
                    setResults(payload.data || []);
                    setNhifCatalog(payload.nhif_catalog ?? []);
                    setIsOpen(true);
                }
            })
            .catch(() => {
                if (thisRequest === requestId.current) {
                    setResults([]);
                    setNhifCatalog([]);
                }
            })
            .finally(() => {
                if (thisRequest === requestId.current) {
                    setLoading(false);
                }
            });
    }, [debouncedQuery, clinicalEncounterId]);

    // Close dropdown on click outside
    useEffect(() => {
        const handleClickOutside = (e: MouseEvent) => {
            if (containerRef.current && !containerRef.current.contains(e.target as Node)) {
                setIsOpen(false);
            }
        };

        document.addEventListener('mousedown', handleClickOutside);
        return () => document.removeEventListener('mousedown', handleClickOutside);
    }, []);

    const selectNhifItem = (item: NhifMedicationCatalogItem) => {
        if (item.local_medication) {
            onSelect({
                id: item.local_medication.id,
                code: item.local_medication.code,
                generic_name: item.local_medication.generic_name,
                brand_name: null,
                strength: null,
                form: null,
                available_stock: item.local_medication.available_stock,
                reorder_level: item.local_medication.reorder_level,
                is_low_stock: item.local_medication.is_low_stock,
                is_out_of_stock: item.local_medication.is_out_of_stock,
            });
            setIsOpen(false);
            setQuery('');
            return;
        }

        setResolving(item.item_code);

        fetch(route('medications.resolve-nhif-item', { item_code: item.item_code, item_name: item.item_name }), {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        })
            .then((r) => r.json())
            .then((med: MedicationSearchResult) => {
                onSelect(med);
                setIsOpen(false);
                setQuery('');
            })
            .finally(() => setResolving(null));
    };

    const handleSelectMedication = (medication: MedicationSearchResult) => {
        onSelect(medication);
        setIsOpen(false);
        setQuery('');
    };

    const handleClearSelection = () => {
        if (onClear) {
            onClear();
        } else {
            (onSelect as any)(null);
        }
        setQuery('');
        setIsOpen(false);
    };

    const localMedIds = new Set(results.map((m) => m.id));
    const filteredCatalog = nhifCatalog.filter((item) => !item.local_medication || !localMedIds.has(item.local_medication.id));

    // If a medication is already selected, display the selected card with a change button
    if (selected) {
        return (
            <div className="flex items-center justify-between rounded-lg border border-purple-200 bg-purple-50/50 p-2 text-xs dark:border-purple-900/50 dark:bg-purple-950/20">
                <div className="flex items-center gap-2 truncate">
                    <Pill className="h-4 w-4 shrink-0 text-purple-600" />
                    <div className="truncate">
                        <span className="font-semibold text-foreground">{selected.generic_name}</span>
                        {selected.brand_name && <span className="text-muted-foreground ml-1">({selected.brand_name})</span>}
                        {(selected.strength || selected.form) && (
                            <span className="text-muted-foreground ml-2 text-[11px]">
                                · {[selected.strength, selected.form].filter(Boolean).join(' ')}
                            </span>
                        )}
                    </div>
                    {selected.nhif_code && (
                        <Badge variant="outline" className="border-green-300 px-1 py-0 text-[10px] text-green-700 shrink-0">
                            NHIF
                        </Badge>
                    )}
                </div>
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    disabled={disabled}
                    className="h-6 px-2 text-xs text-muted-foreground hover:text-foreground"
                    onClick={handleClearSelection}
                >
                    <X className="mr-1 h-3.5 w-3.5" />
                    Change
                </Button>
            </div>
        );
    }

    return (
        <div ref={containerRef} className="relative w-full">
            <div className="relative">
                <Search className="text-muted-foreground pointer-events-none absolute top-1/2 left-2.5 h-4 w-4 -translate-y-1/2" />
                <Input
                    value={query}
                    onChange={(e) => {
                        setQuery(e.target.value);
                        if (!isOpen) setIsOpen(true);
                    }}
                    onFocus={() => {
                        if (results.length > 0 || query.trim().length >= 2 || nhifCatalog.length > 0) {
                            setIsOpen(true);
                        }
                    }}
                    placeholder="Type medication name (e.g. Paracetamol, Oxytocin, Amoxicillin)..."
                    className="pl-8 pr-8 h-9 text-xs"
                    disabled={disabled}
                    autoComplete="off"
                />
                {loading && (
                    <div className="absolute top-1/2 right-2.5 -translate-y-1/2">
                        <Spinner className="h-3.5 w-3.5 text-muted-foreground" />
                    </div>
                )}
                {query && !loading && (
                    <button
                        type="button"
                        onClick={() => {
                            setQuery('');
                            setResults([]);
                            setIsOpen(false);
                        }}
                        className="absolute top-1/2 right-2.5 -translate-y-1/2 text-muted-foreground hover:text-foreground p-0.5"
                    >
                        <X className="h-3.5 w-3.5" />
                    </button>
                )}
            </div>

            {/* Dropdown Results */}
            {isOpen && query.trim().length >= 2 && (
                <div className="absolute left-0 right-0 top-full mt-1 z-50 rounded-md border bg-popover shadow-lg max-h-60 overflow-y-auto p-1 text-xs">
                    {loading && results.length === 0 && (
                        <div className="flex items-center justify-center p-4 text-xs text-muted-foreground gap-2">
                            <Spinner className="h-3.5 w-3.5" />
                            Searching medications...
                        </div>
                    )}

                    {!loading && results.length === 0 && filteredCatalog.length === 0 && (
                        <p className="text-muted-foreground px-2 py-4 text-center text-xs">No matching medications found.</p>
                    )}

                    {results.map((medication) => (
                        <button
                            key={medication.id}
                            type="button"
                            onClick={() => handleSelectMedication(medication)}
                            className="hover:bg-accent hover:text-accent-foreground flex w-full flex-col items-start rounded-sm px-2 py-1.5 text-left text-xs transition-colors"
                        >
                            <span className="flex w-full items-center justify-between gap-2">
                                <span className="flex items-center gap-2">
                                    <span className="font-medium text-foreground">
                                        {medication.generic_name}
                                        {medication.brand_name && <span className="text-muted-foreground"> ({medication.brand_name})</span>}
                                    </span>
                                    {medication.nhif_code && (
                                        <Badge variant="outline" className="border-green-300 px-1 py-0 text-[10px] text-green-700">
                                            NHIF
                                        </Badge>
                                    )}
                                </span>
                                {medication.available_stock !== undefined && (
                                    medication.is_out_of_stock ? (
                                        <span className="inline-flex shrink-0 items-center rounded px-1.5 py-0.5 text-[10px] font-medium bg-red-50 text-red-700 border border-red-200 dark:bg-red-950/40 dark:text-red-300 dark:border-red-900">
                                            Out of Stock
                                        </span>
                                    ) : medication.is_low_stock ? (
                                        <span className="inline-flex shrink-0 items-center rounded px-1.5 py-0.5 text-[10px] font-medium bg-amber-50 text-amber-700 border border-amber-200 dark:bg-amber-950/40 dark:text-amber-300 dark:border-amber-900">
                                            Low Stock ({medication.available_stock})
                                        </span>
                                    ) : (
                                        <span className="inline-flex shrink-0 items-center rounded px-1.5 py-0.5 text-[10px] font-medium bg-emerald-50 text-emerald-700 border border-emerald-200 dark:bg-emerald-950/40 dark:text-emerald-300 dark:border-emerald-900">
                                            {medication.available_stock} in stock
                                        </span>
                                    )
                                )}
                            </span>
                            {(medication.strength || medication.form) && (
                                <span className="text-muted-foreground text-[11px]">{[medication.strength, medication.form].filter(Boolean).join(' · ')}</span>
                            )}
                        </button>
                    ))}

                    {filteredCatalog.length > 0 && (
                        <>
                            <div className="my-1 border-t px-2 pt-1.5">
                                <span className="text-muted-foreground text-[10px] font-semibold uppercase tracking-wider">NHIF Covered Catalog</span>
                            </div>
                            {filteredCatalog.map((item) => (
                                <button
                                    key={item.item_code}
                                    type="button"
                                    disabled={resolving === item.item_code}
                                    onClick={() => selectNhifItem(item)}
                                    className="hover:bg-accent hover:text-accent-foreground flex w-full flex-col items-start rounded-sm px-2 py-1.5 text-left text-xs transition-colors"
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
                                        <span className="flex items-center gap-2 text-muted-foreground text-[11px]">
                                            <span>→ {item.local_medication.code} — {item.local_medication.generic_name}</span>
                                            {item.local_medication.available_stock !== undefined && (
                                                item.local_medication.is_out_of_stock ? (
                                                    <span className="text-[10px] text-red-600 font-medium">(Out of stock)</span>
                                                ) : item.local_medication.is_low_stock ? (
                                                    <span className="text-[10px] text-amber-600 font-medium">({item.local_medication.available_stock} in stock - Low)</span>
                                                ) : (
                                                    <span className="text-[10px] text-emerald-600 font-medium">({item.local_medication.available_stock} in stock)</span>
                                                )
                                            )}
                                        </span>
                                    )}
                                </button>
                            ))}
                        </>
                    )}
                </div>
            )}
        </div>
    );
}
