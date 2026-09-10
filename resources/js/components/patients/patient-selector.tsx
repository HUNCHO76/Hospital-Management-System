import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { Spinner } from '@/components/ui/spinner';
import { useDebouncedValue } from '@/hooks/use-debounced-value';
import { type PatientSearchResult } from '@/types/patient';
import { ChevronsUpDown, Search, UserRound } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

interface PatientSelectorProps {
    value: string;
    selected?: PatientSearchResult | null;
    onSelect: (patient: PatientSearchResult) => void;
    disabled?: boolean;
}

/**
 * A search-and-select combobox for picking a patient, shared by the
 * appointment booking form and the reception walk-in form — there is no
 * other patient-picker component in this app (every other form either gets
 * a patient via route-model binding or a fully preloaded <Select>). Queries
 * the lightweight `/patients/search-json` endpoint, which itself reuses
 * PatientSearchService rather than re-implementing search server-side.
 */
export function PatientSelector({ value, selected, onSelect, disabled }: PatientSelectorProps) {
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
                    setResults(payload.data ?? []);
                }
            })
            .finally(() => {
                if (thisRequest === requestId.current) {
                    setLoading(false);
                }
            });
    }, [debouncedQuery]);

    const label = selected
        ? `${[selected.first_name, selected.middle_name, selected.last_name].filter(Boolean).join(' ')} (${selected.patient_number})`
        : value
          ? `Patient #${value}`
          : 'Search for a patient';

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
                        <UserRound className="text-muted-foreground h-4 w-4 shrink-0" />
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
                        placeholder="Name, patient number, or phone"
                        className="pl-8"
                    />
                </div>

                <div className="max-h-64 overflow-y-auto">
                    {loading && (
                        <div className="flex items-center justify-center py-4">
                            <Spinner label="Searching" />
                        </div>
                    )}

                    {!loading && query.trim().length >= 2 && results.length === 0 && (
                        <p className="text-muted-foreground px-2 py-4 text-center text-sm">No patients found.</p>
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
                                {[patient.first_name, patient.middle_name, patient.last_name].filter(Boolean).join(' ')}
                            </span>
                            <span className="text-muted-foreground text-xs">
                                {patient.patient_number}
                                {patient.phone ? ` · ${patient.phone}` : ''}
                            </span>
                        </button>
                    ))}
                </div>
            </PopoverContent>
        </Popover>
    );
}
