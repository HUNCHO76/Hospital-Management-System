import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { useDebouncedValue } from '@/hooks/use-debounced-value';
import { type LaboratoryTestSearchResult, type NhifCatalogItem } from '@/types/laboratory';
import { Check, FlaskConical, Plus, Search, Sparkles, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

interface LaboratoryOrderTestSelectorProps {
    selected: LaboratoryTestSearchResult[];
    onChange: (tests: LaboratoryTestSearchResult[]) => void;
    disabled?: boolean;
    clinicalEncounterId?: number | null;
}

const MATERNITY_PRESETS = [
    { label: 'Hemoglobin / Hb', query: 'Hemoglobin' },
    { label: 'Blood Group & Rh', query: 'Blood Group' },
    { label: 'Urinalysis', query: 'Urinalysis' },
    { label: 'VDRL / Syphilis', query: 'VDRL' },
    { label: 'HIV 1/2 Screening', query: 'HIV' },
    { label: 'Blood Glucose', query: 'Glucose' },
    { label: 'Malaria MRDT', query: 'Malaria' },
];

export function LaboratoryOrderTestSelector({
    selected,
    onChange,
    disabled,
    clinicalEncounterId,
}: LaboratoryOrderTestSelectorProps) {
    const [query, setQuery] = useState('');
    const [results, setResults] = useState<LaboratoryTestSearchResult[]>([]);
    const [nhifCatalog, setNhifCatalog] = useState<NhifCatalogItem[]>([]);
    const [loading, setLoading] = useState(false);
    const [isOpen, setIsOpen] = useState(false);
    const [resolvingCode, setResolvingCode] = useState<string | null>(null);

    const debouncedQuery = useDebouncedValue(query, 250);
    const requestId = useRef(0);
    const containerRef = useRef<HTMLDivElement>(null);

    // Fetch tests when search query changes
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

        fetch(route('laboratory-tests.search-json', params), {
            headers: { Accept: 'application/json' },
        })
            .then((r) => r.json())
            .then((payload: { data: LaboratoryTestSearchResult[]; nhif_catalog?: NhifCatalogItem[] }) => {
                if (thisRequest === requestId.current) {
                    setResults(payload.data || []);
                    setNhifCatalog(payload.nhif_catalog || []);
                    setIsOpen(true);
                }
            })
            .catch(() => {
                if (thisRequest === requestId.current) {
                    setResults([]);
                }
            })
            .finally(() => {
                if (thisRequest === requestId.current) {
                    setLoading(false);
                }
            });
    }, [debouncedQuery, clinicalEncounterId]);

    // Handle outside clicks to close the dropdown
    useEffect(() => {
        const handleClickOutside = (e: MouseEvent) => {
            if (containerRef.current && !containerRef.current.contains(e.target as Node)) {
                setIsOpen(false);
            }
        };

        document.addEventListener('mousedown', handleClickOutside);
        return () => document.removeEventListener('mousedown', handleClickOutside);
    }, []);

    const addTest = (test: LaboratoryTestSearchResult) => {
        if (!selected.some((t) => t.id === test.id)) {
            onChange([...selected, test]);
        }
        setQuery('');
        setResults([]);
        setIsOpen(false);
    };

    const removeTest = (testId: number) => {
        onChange(selected.filter((t) => t.id !== testId));
    };

    const selectNhifItem = (item: NhifCatalogItem) => {
        if (item.local_test) {
            addTest({ id: item.local_test.id, code: item.local_test.code, name: item.local_test.name });
            return;
        }

        setResolvingCode(item.item_code);
        fetch(route('laboratory-tests.resolve-nhif-item', { item_code: item.item_code, item_name: item.item_name }), {
            headers: { Accept: 'application/json' },
        })
            .then((r) => r.json())
            .then((test: { id: number; code: string; name: string }) => {
                addTest({ id: test.id, code: test.code, name: test.name });
            })
            .finally(() => setResolvingCode(null));
    };

    const handlePresetClick = (presetQuery: string) => {
        setQuery(presetQuery);
        setIsOpen(true);
    };

    const selectedIds = new Set(selected.map((t) => t.id));

    return (
        <div ref={containerRef} className="space-y-3">
            {/* Quick-Pick Presets */}
            <div className="flex flex-wrap items-center gap-1.5">
                <span className="text-[11px] font-medium text-muted-foreground flex items-center gap-1">
                    <Sparkles className="h-3 w-3 text-pink-600" />
                    Quick Picks:
                </span>
                {MATERNITY_PRESETS.map((p) => (
                    <Button
                        key={p.label}
                        type="button"
                        variant="outline"
                        size="sm"
                        disabled={disabled}
                        className="h-6 px-2 text-[11px] hover:border-pink-300 hover:text-pink-700 dark:hover:text-pink-300"
                        onClick={() => handlePresetClick(p.query)}
                    >
                        <Plus className="h-3 w-3 mr-1 text-muted-foreground" />
                        {p.label}
                    </Button>
                ))}
            </div>

            {/* Direct Search Input */}
            <div className="relative">
                <Search className="text-muted-foreground pointer-events-none absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2" />
                <Input
                    value={query}
                    onChange={(e) => {
                        setQuery(e.target.value);
                        if (!isOpen) setIsOpen(true);
                    }}
                    onFocus={() => {
                        if (results.length > 0 || debouncedQuery.length >= 2) {
                            setIsOpen(true);
                        }
                    }}
                    placeholder="Search test name or code (e.g. Hemoglobin, Urinalysis, Glucose, HIV)..."
                    className="pl-9 pr-8 h-9 text-xs"
                    disabled={disabled}
                />
                {loading && (
                    <div className="absolute top-1/2 right-2.5 -translate-y-1/2">
                        <Spinner className="h-4 w-4 text-muted-foreground" />
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

                {/* Instant Inline Dropdown Results */}
                {isOpen && query.trim().length >= 2 && (
                    <div className="absolute left-0 right-0 top-full mt-1.5 z-50 rounded-md border bg-popover shadow-lg max-h-60 overflow-y-auto p-1 text-xs">
                        {loading && results.length === 0 && (
                            <div className="flex items-center justify-center p-4 text-xs text-muted-foreground gap-2">
                                <Spinner className="h-3.5 w-3.5" />
                                Searching lab catalog...
                            </div>
                        )}

                        {!loading && results.length === 0 && nhifCatalog.length === 0 && (
                            <div className="p-3 text-center text-xs text-muted-foreground">
                                No lab investigations found matching "{query}"
                            </div>
                        )}

                        {results.length > 0 && (
                            <div className="space-y-0.5">
                                <div className="px-2 py-1 text-[10px] font-semibold text-muted-foreground uppercase tracking-wider">
                                    Laboratory Tests ({results.length})
                                </div>
                                {results.map((test) => {
                                    const isAlreadySelected = selectedIds.has(test.id);
                                    return (
                                        <button
                                            key={test.id}
                                            type="button"
                                            disabled={isAlreadySelected || disabled}
                                            onClick={() => addTest(test)}
                                            className={`w-full flex items-center justify-between px-2.5 py-1.5 rounded text-left transition-colors ${
                                                isAlreadySelected
                                                    ? 'bg-muted/50 text-muted-foreground opacity-60 cursor-not-allowed'
                                                    : 'hover:bg-accent hover:text-accent-foreground cursor-pointer'
                                            }`}
                                        >
                                            <div className="flex items-center gap-2 truncate">
                                                <FlaskConical className="h-3.5 w-3.5 text-emerald-600 shrink-0" />
                                                <div className="truncate">
                                                    <span className="font-semibold">{test.code}</span> — {test.name}
                                                </div>
                                            </div>
                                            {isAlreadySelected ? (
                                                <span className="flex items-center gap-1 text-[10px] text-emerald-600 font-medium shrink-0">
                                                    <Check className="h-3 w-3" /> Added
                                                </span>
                                            ) : (
                                                <span className="text-[10px] font-medium text-pink-600 flex items-center gap-0.5 shrink-0">
                                                    <Plus className="h-3 w-3" /> Add
                                                </span>
                                            )}
                                        </button>
                                    );
                                })}
                            </div>
                        )}

                        {nhifCatalog.length > 0 && (
                            <div className="mt-2 pt-2 border-t space-y-0.5">
                                <div className="px-2 py-1 text-[10px] font-semibold text-muted-foreground uppercase tracking-wider">
                                    NHIF Scheme Catalog Items ({nhifCatalog.length})
                                </div>
                                {nhifCatalog.map((item) => (
                                    <button
                                        key={item.item_code}
                                        type="button"
                                        disabled={resolvingCode === item.item_code || disabled}
                                        onClick={() => selectNhifItem(item)}
                                        className="w-full flex items-center justify-between px-2.5 py-1.5 rounded text-left hover:bg-accent hover:text-accent-foreground cursor-pointer"
                                    >
                                        <div className="flex items-center gap-2 truncate">
                                            <Badge variant="outline" className="text-[10px] px-1 py-0 border-blue-400 text-blue-700">
                                                NHIF
                                            </Badge>
                                            <div className="truncate">
                                                <span className="font-semibold">{item.item_code}</span> — {item.item_name}
                                            </div>
                                        </div>
                                        {resolvingCode === item.item_code ? (
                                            <Spinner className="h-3 w-3 shrink-0" />
                                        ) : (
                                            <span className="text-[10px] font-medium text-blue-600 flex items-center gap-0.5 shrink-0">
                                                <Plus className="h-3 w-3" /> Add
                                            </span>
                                        )}
                                    </button>
                                ))}
                            </div>
                        )}
                    </div>
                )}
            </div>

            {/* Selected Tests Badges List */}
            {selected.length > 0 ? (
                <div className="space-y-1.5">
                    <span className="text-[11px] font-semibold text-muted-foreground">
                        Selected Tests ({selected.length}):
                    </span>
                    <ul className="flex flex-wrap gap-1.5">
                        {selected.map((test) => (
                            <li key={test.id}>
                                <Badge
                                    variant="outline"
                                    className="gap-1.5 py-1 pl-2.5 pr-1.5 border-emerald-300 bg-emerald-50/70 text-emerald-900 dark:bg-emerald-950/30 dark:border-emerald-800 dark:text-emerald-300"
                                >
                                    <FlaskConical className="h-3 w-3 text-emerald-600 shrink-0" />
                                    <span className="font-semibold text-xs">{test.code}</span>
                                    <span className="text-xs text-neutral-600 dark:text-neutral-400">— {test.name}</span>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        className="h-4 w-4 rounded-full p-0 text-neutral-500 hover:text-destructive hover:bg-destructive/10 ml-0.5"
                                        onClick={() => removeTest(test.id)}
                                        disabled={disabled}
                                        aria-label={`Remove ${test.name}`}
                                    >
                                        <X className="h-3 w-3" />
                                    </Button>
                                </Badge>
                            </li>
                        ))}
                    </ul>
                </div>
            ) : (
                <p className="text-[11px] text-muted-foreground italic">
                    No tests selected yet. Type above or click a quick pick to add tests to this order.
                </p>
            )}
        </div>
    );
}
