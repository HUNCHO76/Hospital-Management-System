import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { type PossibleDuplicatePatientInsurance } from '@/types/patient-insurance';
import { TriangleAlert } from 'lucide-react';

interface DuplicateInsuranceWarningProps {
    duplicates: PossibleDuplicatePatientInsurance[];
    onContinue: () => void;
    processing: boolean;
}

export function DuplicateInsuranceWarning({ duplicates, onContinue, processing }: DuplicateInsuranceWarningProps) {
    if (duplicates.length === 0) {
        return null;
    }

    return (
        <Alert className="border-amber-500/40 bg-amber-50 text-amber-900 dark:border-amber-500/30 dark:bg-amber-950 dark:text-amber-200">
            <TriangleAlert className="h-4 w-4" />
            <AlertTitle>Possible Existing Membership</AlertTitle>
            <AlertDescription className="text-current">
                <p className="mb-3">A patient insurance record with this membership number under this provider already exists.</p>
                <ul className="mb-3 space-y-2">
                    {duplicates.map((duplicate) => (
                        <li key={duplicate.id} className="rounded-md border border-current/20 px-3 py-2 text-sm">
                            <span className="font-medium">{duplicate.membership_number}</span>
                            {duplicate.insurance_provider && <span> · {duplicate.insurance_provider.name}</span>}
                            {duplicate.patient && (
                                <span>
                                    {' '}
                                    · {duplicate.patient.patient_number} —{' '}
                                    {[duplicate.patient.first_name, duplicate.patient.middle_name, duplicate.patient.last_name]
                                        .filter(Boolean)
                                        .join(' ')}
                                </span>
                            )}
                        </li>
                    ))}
                </ul>
                <Button type="button" size="sm" variant="secondary" onClick={onContinue} disabled={processing}>
                    Continue Anyway
                </Button>
            </AlertDescription>
        </Alert>
    );
}
