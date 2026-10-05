import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { Spinner } from '@/components/ui/spinner';
import { useDebouncedValue } from '@/hooks/use-debounced-value';
import { type NhifDiseaseSearchResult } from '@/types/diagnosis';
import { ChevronsUpDown, Search, Shield } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

interface NhifDiseaseSearchProps {
    selected?: NhifDiseaseSearchResult | null;
    onSelect: (disease: NhifDiseaseSearchResult | null) => void;
    disabled?: boolean;
}

/**
 * Optional NHIF disease search — shown only for NHIF-eligible encounters.
 * Queries `/nhif/diseases/search-json` and snapshots the selected disease
 * code/name/version onto the diagnosis (never a FK to nhif_diseases).
 */
export function NhifDiseaseSearch({ selected, onSelect, disabled }: NhifDiseaseSearchProps) {
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const [results, setResults] = useState<NhifDiseaseSearchResult[]>([]);
    const [loading, setLoading] = useState(false);
    const debouncedQuery = useDebouncedValue(query, 300);
    const requestId = useRef(0);

    useEffect(() => {
        if (debouncedQuery.trim().length < 2) {
            setResults([]);
            return;
        }

        const thisRequest = ++requestId.current;
        setLoading(true);

        fetch(route('nhif.diseases.search-json', { q: debouncedQuery }), {
            headers: { Accept: 'application/json' },
        })
            .then((response) => response.json())
            .then((payload: NhifDiseaseSearchResult[] | { data?: NhifDiseaseSearchResult[] }) => {
                if (thisRequest === requestId.current) {
                    setResults(Array.isArray(payload) ? payload : (payload.data ?? []));
                }
            })
            .finally(() => {
                if (thisRequest === requestId.current) {
                    setLoading(false);
                }
            });
    }, [debouncedQuery]);

    const label = selected ? `${selected.disease_code} — ${selected.disease_name}` : 'Search NHIF disease (optional)';

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
                        <Shield className="h-4 w-4 shrink-0 text-emerald-600" />
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
                        placeholder="NHIF disease code or name"
                        className="pl-8"
                        aria-label="Search NHIF diseases"
                    />
                </div>

                <div className="max-h-64 overflow-y-auto">
                    {selected && (
                        <button
                            type="button"
                            onClick={() => {
                                onSelect(null);
                                setOpen(false);
                                setQuery('');
                            }}
                            className="text-muted-foreground hover:text-foreground mb-1 w-full px-2 py-1 text-left text-xs underline"
                        >
                            Clear selection
                        </button>
                    )}

                    {loading && (
                        <div className="flex items-center justify-center py-4">
                            <Spinner label="Searching" />
                        </div>
                    )}

                    {!loading && query.trim().length >= 2 && results.length === 0 && (
                        <p className="text-muted-foreground px-2 py-4 text-center text-sm">No matching NHIF diseases found.</p>
                    )}

                    {!loading && query.trim().length < 2 && (
                        <p className="text-muted-foreground px-2 py-4 text-center text-sm">Type at least 2 characters to search.</p>
                    )}

                    {results.map((disease) => (
                        <button
                            key={disease.id}
                            type="button"
                            onClick={() => {
                                onSelect(disease);
                                setOpen(false);
                                setQuery('');
                            }}
                            className="hover:bg-accent hover:text-accent-foreground flex w-full flex-col items-start rounded-sm px-2 py-1.5 text-left text-sm"
                        >
                            <span className="font-medium">
                                {disease.disease_code} — {disease.disease_name}
                            </span>
                            <span className="text-muted-foreground text-xs">{disease.icd_version_code}</span>
                        </button>
                    ))}
                </div>
            </PopoverContent>
        </Popover>
    );
}
