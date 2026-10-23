import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { Spinner } from '@/components/ui/spinner';
import { useDebouncedValue } from '@/hooks/use-debounced-value';
import { radiologyModalityLabel, radiologyPreparationTypeLabel } from '@/lib/radiology-status';
import { type NhifRadiologyCatalogItem, type RadiologyProcedureSearchResult } from '@/types/radiology';
import { ChevronsUpDown, Scan, Search } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

interface RadiologyProcedureSearchProps {
    selected?: RadiologyProcedureSearchResult | null;
    onSelect: (procedure: RadiologyProcedureSearchResult) => void;
    disabled?: boolean;
    clinicalEncounterId?: number | null;
}

/**
 * A search-and-select combobox for picking a radiology procedure — mirrors
 * LaboratoryTestSearch's structure exactly (debounce, 2-char minimum, a
 * request-id guard against stale responses, loading/empty states). Queries
 * the lightweight `/radiology-procedures/search-json` endpoint.
 * When clinicalEncounterId belongs to an NHIF patient, displays both local
 * procedures and NHIF covered services with direct resolution.
 */
export function RadiologyProcedureSearch({ selected, onSelect, disabled, clinicalEncounterId }: RadiologyProcedureSearchProps) {
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const [results, setResults] = useState<RadiologyProcedureSearchResult[]>([]);
    const [nhifCatalog, setNhifCatalog] = useState<NhifRadiologyCatalogItem[]>([]);
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

        fetch(route('radiology-procedures.search-json', params), {
            headers: { Accept: 'application/json' },
        })
            .then((response) => response.json())
            .then((payload: { data: RadiologyProcedureSearchResult[]; nhif_catalog?: NhifRadiologyCatalogItem[] }) => {
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

    const selectNhifItem = (item: NhifRadiologyCatalogItem) => {
        if (item.local_procedure) {
            onSelect({
                id: item.local_procedure.id,
                code: item.local_procedure.code,
                name: item.local_procedure.name,
                modality: item.local_procedure.modality,
            });
            setOpen(false);
            setQuery('');

            return;
        }

        setResolving(item.item_code);

        fetch(route('radiology-procedures.resolve-nhif-item', { item_code: item.item_code, item_name: item.item_name }), {
            headers: { Accept: 'application/json' },
        })
            .then((r) => r.json())
            .then((proc: RadiologyProcedureSearchResult) => {
                onSelect({
                    id: proc.id,
                    code: proc.code,
                    name: proc.name,
                    modality: proc.modality,
                });
                setOpen(false);
                setQuery('');
            })
            .finally(() => setResolving(null));
    };

    const label = selected ? `${selected.code} — ${selected.name}` : 'Search radiology procedure';

    const localProcIds = new Set(results.map((p) => p.id));
    const filteredCatalog = nhifCatalog.filter((item) => !item.local_procedure || !localProcIds.has(item.local_procedure.id));

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
                        <Scan className="text-muted-foreground h-4 w-4 shrink-0" />
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
                        placeholder="Procedure name or code"
                        className="pl-8"
                        aria-label="Search radiology procedures"
                    />
                </div>

                <div className="max-h-64 overflow-y-auto">
                    {loading && (
                        <div className="flex items-center justify-center py-4">
                            <Spinner label="Searching" />
                        </div>
                    )}

                    {!loading && query.trim().length >= 2 && results.length === 0 && filteredCatalog.length === 0 && (
                        <p className="text-muted-foreground px-2 py-4 text-center text-sm">No matching radiology procedures found.</p>
                    )}

                    {!loading && query.trim().length < 2 && (
                        <p className="text-muted-foreground px-2 py-4 text-center text-sm">Type at least 2 characters to search.</p>
                    )}

                    {results.map((procedure) => (
                        <button
                            key={procedure.id}
                            type="button"
                            onClick={() => {
                                onSelect(procedure);
                                setOpen(false);
                                setQuery('');
                            }}
                            className="hover:bg-accent hover:text-accent-foreground flex w-full flex-col items-start rounded-sm px-2 py-1.5 text-left text-sm"
                        >
                            <span className="flex items-center gap-2">
                                <span className="font-medium">
                                    {procedure.code} — {procedure.name}
                                </span>
                                {procedure.nhif_code && (
                                    <Badge variant="outline" className="border-green-300 px-1 py-0 text-[10px] text-green-700">
                                        NHIF
                                    </Badge>
                                )}
                            </span>
                            {(procedure.modality || procedure.preparation_type) && (
                                <span className="text-muted-foreground text-xs">
                                    {procedure.modality && radiologyModalityLabel[procedure.modality]}
                                    {procedure.modality && procedure.preparation_type && ' · '}
                                    {procedure.preparation_type &&
                                        `Preparation: ${
                                            procedure.preparation_type === 'none' ? 'None' : radiologyPreparationTypeLabel[procedure.preparation_type]
                                        }`}
                                </span>
                            )}
                        </button>
                    ))}

                    {filteredCatalog.length > 0 && (
                        <>
                            <div className="my-1 border-t px-2 pt-1.5">
                                <span className="text-muted-foreground text-xs font-medium">NHIF Covered Services</span>
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
                                    {item.local_procedure && (
                                        <span className="text-muted-foreground text-xs">
                                            → {item.local_procedure.code} — {item.local_procedure.name}
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
