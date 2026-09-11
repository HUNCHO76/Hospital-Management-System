import { RadiologyProcedureSearch } from '@/components/radiology/radiology-procedure-search';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { type RadiologyProcedureSearchResult } from '@/types/radiology';
import { X } from 'lucide-react';

interface RadiologyOrderProcedureSelectorProps {
    selected: RadiologyProcedureSearchResult[];
    onChange: (procedures: RadiologyProcedureSearchResult[]) => void;
    disabled?: boolean;
}

/**
 * Accumulates a local "selected procedures" list on top of the existing
 * single-select RadiologyProcedureSearch combobox, so a radiology order can
 * request multiple procedures at once (Phase 5, Step 36, Part 9 — "one
 * order, many procedures", e.g. Chest X-Ray + Abdominal Ultrasound).
 * Mirrors LaboratoryOrderTestSelector exactly. Selecting an already-chosen
 * procedure is a no-op client-side; the service is the real duplicate
 * guard.
 */
export function RadiologyOrderProcedureSelector({ selected, onChange, disabled }: RadiologyOrderProcedureSelectorProps) {
    const addProcedure = (procedure: RadiologyProcedureSearchResult) => {
        if (selected.some((p) => p.id === procedure.id)) {
            return;
        }

        onChange([...selected, procedure]);
    };

    const removeProcedure = (procedureId: number) => {
        onChange(selected.filter((p) => p.id !== procedureId));
    };

    return (
        <div className="space-y-2">
            <RadiologyProcedureSearch selected={null} onSelect={addProcedure} disabled={disabled} />

            {selected.length > 0 && (
                <ul className="flex flex-wrap gap-2">
                    {selected.map((procedure) => (
                        <li key={procedure.id}>
                            <Badge variant="outline" className="gap-1 py-1 pr-1">
                                {procedure.code} — {procedure.name}
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    className="h-4 w-4"
                                    onClick={() => removeProcedure(procedure.id)}
                                    disabled={disabled}
                                    aria-label={`Remove ${procedure.name}`}
                                >
                                    <X className="h-3 w-3" />
                                </Button>
                            </Badge>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}
