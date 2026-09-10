import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { Spinner } from '@/components/ui/spinner';
import { useDebouncedValue } from '@/hooks/use-debounced-value';
import { type LaboratoryTestSearchResult } from '@/types/laboratory';
import { ChevronsUpDown, FlaskConical, Search } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

interface LaboratoryTestSearchProps {
    selected?: LaboratoryTestSearchResult | null;
    onSelect: (test: LaboratoryTestSearchResult) => void;
    disabled?: boolean;
}

/**
 * A search-and-select combobox for picking a laboratory test — copies
 * MedicationSearch's structure exactly (debounce, 2-char minimum, a
 * request-id guard against stale responses, loading/empty states). Queries
 * the lightweight `/laboratory-tests/search-json` endpoint rather than
 * loading the catalog client-side. Full catalog browsing/management is
 * Step 29's job — this is only the minimal picker Step 28 needs.
 */
export function LaboratoryTestSearch({ selected, onSelect, disabled }: LaboratoryTestSearchProps) {
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const [results, setResults] = useState<LaboratoryTestSearchResult[]>([]);
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

        fetch(route('laboratory-tests.search-json', { search: debouncedQuery }), {
            headers: { Accept: 'application/json' },
        })
            .then((response) => response.json())
            .then((payload: { data: LaboratoryTestSearchResult[] }) => {
                if (thisRequest === requestId.current) {
                    setResults(payload.data);
                }
            })
            .finally(() => {
                if (thisRequest === requestId.current) {
                    setLoading(false);
                }
            });
    }, [debouncedQuery]);

    const label = selected ? `${selected.code} — ${selected.name}` : 'Search laboratory test';

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
                        <FlaskConical className="text-muted-foreground h-4 w-4 shrink-0" />
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
                        placeholder="Test name or code"
                        className="pl-8"
                        aria-label="Search laboratory tests"
                    />
                </div>

                <div className="max-h-64 overflow-y-auto">
                    {loading && (
                        <div className="flex items-center justify-center py-4">
                            <Spinner label="Searching" />
                        </div>
                    )}

                    {!loading && query.trim().length >= 2 && results.length === 0 && (
                        <p className="text-muted-foreground px-2 py-4 text-center text-sm">No matching laboratory tests found.</p>
                    )}

                    {!loading && query.trim().length < 2 && (
                        <p className="text-muted-foreground px-2 py-4 text-center text-sm">Type at least 2 characters to search.</p>
                    )}

                    {results.map((test) => (
                        <button
                            key={test.id}
                            type="button"
                            onClick={() => {
                                onSelect(test);
                                setOpen(false);
                                setQuery('');
                            }}
                            className="hover:bg-accent hover:text-accent-foreground flex w-full flex-col items-start rounded-sm px-2 py-1.5 text-left text-sm"
                        >
                            <span className="font-medium">
                                {test.code} — {test.name}
                            </span>
                        </button>
                    ))}
                </div>
            </PopoverContent>
        </Popover>
    );
}
