import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { Spinner } from '@/components/ui/spinner';
import { useDebouncedValue } from '@/hooks/use-debounced-value';
import { type PatientSearchResult } from '@/types/billing';
import { ChevronsUpDown, Search, User } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

interface PatientSearchProps {
    selected?: PatientSearchResult | null;
    onSelect: (patient: PatientSearchResult) => void;
    disabled?: boolean;
}

/**
 * A search-and-select combobox for picking a patient (Phase 7, Step 52) —
 * copies MedicationSearch's structure exactly (debounce, 2-char minimum, a
 * request-id guard against stale responses, loading/empty states). Queries
 * the existing `/patients/search-json` endpoint (Phase 2) rather than a new
 * billing-specific one.
 */
export function PatientSearch({ selected, onSelect, disabled }: PatientSearchProps) {
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const [results, setResults] = useState<PatientSearchResult[]>([]);
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

        fetch(route('patients.search-json', { search: debouncedQuery }), {
            headers: { Accept: 'application/json' },
        })
            .then((response) => response.json())
            .then((payload: { data: PatientSearchResult[] }) => {
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

    const label = selected ? `${selected.first_name} ${selected.last_name} (${selected.patient_number})` : 'Search patient by name or number';

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
                        <User className="text-muted-foreground h-4 w-4 shrink-0" />
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
                        placeholder="Patient name or number"
                        className="pl-8"
                        aria-label="Search patients"
                    />
                </div>

                <div className="max-h-64 overflow-y-auto">
                    {loading && (
                        <div className="flex items-center justify-center py-4">
                            <Spinner label="Searching" />
                        </div>
                    )}

                    {!loading && query.trim().length >= 2 && results.length === 0 && (
                        <p className="text-muted-foreground px-2 py-4 text-center text-sm">No matching patients found.</p>
                    )}

                    {!loading && query.trim().length < 2 && (
                        <p className="text-muted-foreground px-2 py-4 text-center text-sm">Type at least 2 characters to search.</p>
                    )}

                    {results.map((patient) => (
                        <button
                            key={patient.id}
                            type="button"
                            onClick={() => {
                                onSelect(patient);
                                setOpen(false);
                                setQuery('');
                            }}
                            className="hover:bg-accent hover:text-accent-foreground flex w-full flex-col items-start rounded-sm px-2 py-1.5 text-left text-sm"
                        >
                            <span className="font-medium">
                                {patient.first_name} {patient.middle_name ? `${patient.middle_name} ` : ''}
                                {patient.last_name}
                            </span>
                            <span className="text-muted-foreground text-xs">{patient.patient_number}</span>
                        </button>
                    ))}
                </div>
            </PopoverContent>
        </Popover>
    );
}
