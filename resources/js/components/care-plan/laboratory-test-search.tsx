import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { Spinner } from '@/components/ui/spinner';
import { useDebouncedValue } from '@/hooks/use-debounced-value';
import { type LaboratoryTestSearchResult, type NhifCatalogItem } from '@/types/laboratory';
import { ChevronsUpDown, FlaskConical, Search } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

interface LaboratoryTestSearchProps {
    selected?: LaboratoryTestSearchResult | null;
    onSelect: (test: LaboratoryTestSearchResult) => void;
    disabled?: boolean;
    clinicalEncounterId?: number | null;
}

export function LaboratoryTestSearch({ selected, onSelect, disabled, clinicalEncounterId }: LaboratoryTestSearchProps) {
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const [results, setResults] = useState<LaboratoryTestSearchResult[]>([]);
    const [nhifCatalog, setNhifCatalog] = useState<NhifCatalogItem[]>([]);
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

        fetch(route('laboratory-tests.search-json', params), {
            headers: { Accept: 'application/json' },
        })
            .then((response) => response.json())
            .then((payload: { data: LaboratoryTestSearchResult[]; nhif_catalog: NhifCatalogItem[] }) => {
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

    const selectNhifItem = (item: NhifCatalogItem) => {
        if (item.local_test) {
            onSelect({ id: item.local_test.id, code: item.local_test.code, name: item.local_test.name });
            setOpen(false);
            setQuery('');

            return;
        }

        setResolving(item.item_code);

        fetch(route('laboratory-tests.resolve-nhif-item', { item_code: item.item_code, item_name: item.item_name }), {
            headers: { Accept: 'application/json' },
        })
            .then((r) => r.json())
            .then((test: { id: number; code: string; name: string }) => {
                onSelect({ id: test.id, code: test.code, name: test.name });
                setOpen(false);
                setQuery('');
            })
            .finally(() => setResolving(null));
    };

    const label = selected ? `${selected.code} — ${selected.name}` : 'Search laboratory test';

    const localTestIds = new Set(results.map((t) => t.id));
    const filteredCatalog = nhifCatalog.filter((item) => !item.local_test || !localTestIds.has(item.local_test.id));

    return (
        <Popover open={open} onOpenChange={setOpen} modal={true}>
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
            <PopoverContent
                className="w-[--radix-popover-trigger-width] p-2 z-[70]"
                align="start"
                onOpenAutoFocus={(e) => {
                    // Allow input to receive focus cleanly without being intercepted by parent dialog
                }}
            >
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

                    {!loading && query.trim().length >= 2 && results.length === 0 && filteredCatalog.length === 0 && (
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
                            <span className="flex items-center gap-2">
                                <span className="font-medium">
                                    {test.code} — {test.name}
                                </span>
                                {test.nhif_code && (
                                    <Badge variant="outline" className="border-green-300 px-1 py-0 text-[10px] text-green-700">
                                        NHIF
                                    </Badge>
                                )}
                            </span>
                        </button>
                    ))}

                    {filteredCatalog.length > 0 && (
                        <>
                            <div className="my-1 border-t px-2 pt-1.5">
                                <span className="text-muted-foreground text-xs font-medium">NHIF Covered Tests</span>
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
                                    {item.local_test && (
                                        <span className="text-muted-foreground text-xs">
                                            → {item.local_test.code} — {item.local_test.name}
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
