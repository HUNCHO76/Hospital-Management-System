import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { type PossibleDuplicatePatient } from '@/types/patient';
import { Link } from '@inertiajs/react';
import { TriangleAlert } from 'lucide-react';

interface DuplicatePatientWarningProps {
    duplicates: PossibleDuplicatePatient[];
    onContinue: () => void;
    processing: boolean;
}

export function DuplicatePatientWarning({ duplicates, onContinue, processing }: DuplicatePatientWarningProps) {
    if (duplicates.length === 0) {
        return null;
    }

    return (
        <Alert className="border-amber-500/40 bg-amber-50 text-amber-900 dark:border-amber-500/30 dark:bg-amber-950 dark:text-amber-200">
            <TriangleAlert className="h-4 w-4" />
            <AlertTitle>Possible Existing Patient</AlertTitle>
            <AlertDescription className="text-current">
                <p className="mb-3">A patient with similar information already exists. Please check before continuing.</p>
                <ul className="mb-3 space-y-2">
                    {duplicates.map((duplicate) => (
                        <li
                            key={duplicate.id}
                            className="flex flex-wrap items-center justify-between gap-2 rounded-md border border-current/20 px-3 py-2"
                        >
                            <div className="text-sm">
                                <span className="font-medium">{duplicate.patient_number}</span> —{' '}
                                {[duplicate.first_name, duplicate.middle_name, duplicate.last_name].filter(Boolean).join(' ')}
                                {duplicate.date_of_birth && <span> · DOB {duplicate.date_of_birth}</span>}
                                {duplicate.phone && <span> · {duplicate.phone}</span>}
                            </div>
                            <Button size="sm" variant="outline" asChild>
                                <Link href={route('patients.show', duplicate.id)}>View Patient</Link>
                            </Button>
                        </li>
                    ))}
                </ul>
                <Button type="button" size="sm" variant="secondary" onClick={onContinue} disabled={processing}>
                    Continue Registration Anyway
                </Button>
            </AlertDescription>
        </Alert>
    );
}
