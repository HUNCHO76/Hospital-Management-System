import { LaboratoryTestSearch } from '@/components/care-plan/laboratory-test-search';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { type LaboratoryTestSearchResult } from '@/types/laboratory';
import { X } from 'lucide-react';

interface LaboratoryOrderTestSelectorProps {
    selected: LaboratoryTestSearchResult[];
    onChange: (tests: LaboratoryTestSearchResult[]) => void;
    disabled?: boolean;
    clinicalEncounterId?: number | null;
}

/**
 * Accumulates a local "selected tests" list on top of the existing
 * single-select LaboratoryTestSearch combobox (Step 28, reused as-is), so a
 * laboratory order can request multiple tests at once (Phase 4, Step 30 —
 * "one order, many tests"). Selecting an already-chosen test is a no-op
 * client-side; the service is the real duplicate guard.
 */
export function LaboratoryOrderTestSelector({ selected, onChange, disabled, clinicalEncounterId }: LaboratoryOrderTestSelectorProps) {
    const addTest = (test: LaboratoryTestSearchResult) => {
        if (selected.some((t) => t.id === test.id)) {
            return;
        }

        onChange([...selected, test]);
    };

    const removeTest = (testId: number) => {
        onChange(selected.filter((t) => t.id !== testId));
    };

    return (
        <div className="space-y-2">
            <LaboratoryTestSearch selected={null} onSelect={addTest} disabled={disabled} clinicalEncounterId={clinicalEncounterId} />

            {selected.length > 0 && (
                <ul className="flex flex-wrap gap-2">
                    {selected.map((test) => (
                        <li key={test.id}>
                            <Badge variant="outline" className="gap-1 py-1 pr-1">
                                {test.code} — {test.name}
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    className="h-4 w-4"
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
            )}
        </div>
    );
}
