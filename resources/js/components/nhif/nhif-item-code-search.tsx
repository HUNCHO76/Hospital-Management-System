import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { Spinner } from '@/components/ui/spinner';
import { useDebouncedValue } from '@/hooks/use-debounced-value';
import { ChevronsUpDown, Search, Shield, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

interface CatalogItem {
    item_code: number | string;
    item_name: string;
    type_name: string;
}

interface NhifItemCodeSearchProps {
    value: string;
    onSelect: (code: string, name: string) => void;
    typeId?: number;
    disabled?: boolean;
    compact?: boolean;
}

export function NhifItemCodeSearch({ value, onSelect, typeId, disabled, compact }: NhifItemCodeSearchProps) {
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const [results, setResults] = useState<CatalogItem[]>([]);
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

        const params: Record<string, string | number> = { q: debouncedQuery };
        if (typeId) {
            params.type_id = typeId;
        }

        fetch(route('nhif.catalog-items.search-json', params), {
            headers: { Accept: 'application/json' },
        })
            .then((response) => response.json())
            .then((payload: CatalogItem[] | { data?: CatalogItem[] }) => {
                if (thisRequest === requestId.current) {
                    setResults(Array.isArray(payload) ? payload : (payload.data ?? []));
                }
            })
            .finally(() => {
                if (thisRequest === requestId.current) {
                    setLoading(false);
                }
            });
    }, [debouncedQuery, typeId]);

    const label = value ? value : (compact ? 'Select...' : 'Search NHIF item code');

    return (
        <div className="flex items-center gap-1">
            <Popover open={open} onOpenChange={setOpen}>
                <PopoverTrigger asChild>
                    <Button
                        type="button"
                        variant="outline"
                        role="combobox"
                        aria-expanded={open}
                        disabled={disabled}
                        className={`justify-between font-normal ${compact ? 'h-8 text-xs' : 'w-full'}`}
                    >
                        <span className="flex items-center gap-2 truncate">
                            <Shield className="h-3.5 w-3.5 shrink-0 text-emerald-600" />
                            <span className="truncate">{label}</span>
                        </span>
                        <ChevronsUpDown className="h-3.5 w-3.5 shrink-0 opacity-50" />
                    </Button>
                </PopoverTrigger>
                <PopoverContent className="w-80 p-2" align="start">
                    <div className="relative mb-2">
                        <Search className="text-muted-foreground pointer-events-none absolute top-1/2 left-2 h-4 w-4 -translate-y-1/2" />
                        <Input
                            autoFocus
                            value={query}
                            onChange={(e) => setQuery(e.target.value)}
                            placeholder="NHIF item code or name"
                            className="pl-8"
                            aria-label="Search NHIF catalog items"
                        />
                    </div>

                    <div className="max-h-64 overflow-y-auto">
                        {loading && (
                            <div className="flex items-center justify-center py-4">
                                <Spinner label="Searching" />
                            </div>
                        )}

                        {!loading && query.trim().length >= 2 && results.length === 0 && (
                            <p className="text-muted-foreground px-2 py-4 text-center text-sm">No matching NHIF items found.</p>
                        )}

                        {!loading && query.trim().length < 2 && (
                            <p className="text-muted-foreground px-2 py-4 text-center text-sm">Type at least 2 characters to search.</p>
                        )}

                        {results.map((item) => (
                            <button
                                key={`${item.item_code}-${item.item_name}`}
                                type="button"
                                onClick={() => {
                                    onSelect(String(item.item_code), item.item_name);
                                    setOpen(false);
                                    setQuery('');
                                }}
                                className="hover:bg-accent hover:text-accent-foreground flex w-full flex-col items-start rounded-sm px-2 py-1.5 text-left text-sm"
                            >
                                <span className="font-medium">
                                    {item.item_code} — {item.item_name}
                                </span>
                                <span className="text-muted-foreground text-xs">{item.type_name}</span>
                            </button>
                        ))}
                    </div>
                </PopoverContent>
            </Popover>
            {value && !disabled && (
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    className="h-8 w-8 shrink-0"
                    onClick={() => onSelect('', '')}
                    aria-label="Clear NHIF code"
                >
                    <X className="h-3.5 w-3.5" />
                </Button>
            )}
        </div>
    );
}
