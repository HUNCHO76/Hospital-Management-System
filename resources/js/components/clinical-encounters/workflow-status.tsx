import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { type CarePlan } from '@/types/care-plan';
import { type ClinicalEncounter } from '@/types/clinical-encounter';
import { type ClinicalNote } from '@/types/clinical-note';
import { type Consultation } from '@/types/consultation';
import { type EncounterDiagnosis } from '@/types/diagnosis';
import { type TriageAssessment } from '@/types/triage';
import { CheckCircle2, Circle } from 'lucide-react';

interface WorkflowStatusProps {
    encounter: ClinicalEncounter;
    triage: TriageAssessment | null;
    consultation: Consultation | null;
    diagnoses: EncounterDiagnosis[];
    carePlan: CarePlan | null;
    clinicalNotes: ClinicalNote[];
}

/**
 * A read-only checklist of where this encounter stands, computed entirely
 * from the same props already rendered by the individual panels below it —
 * never a separate backend endpoint or frontend-invented state (spec §26).
 * A step being unchecked is not an error: a simple encounter legitimately
 * may never need a diagnosis or treatment (spec §15).
 */
export function WorkflowStatus({ encounter, triage, consultation, diagnoses, carePlan, clinicalNotes }: WorkflowStatusProps) {
    const steps = [
        { label: 'Triage Complete', done: triage?.status === 'completed' },
        { label: 'Consultation Complete', done: consultation?.status === 'completed' },
        { label: 'Diagnosis Recorded', done: diagnoses.length > 0 },
        { label: 'Treatment Recorded', done: carePlan !== null },
        { label: 'Clinical Note Signed', done: clinicalNotes.some((note) => note.status === 'signed' || note.status === 'amended') },
        { label: 'Disposition Set', done: encounter.disposition !== null },
    ];

    return (
        <Card>
            <CardHeader>
                <CardTitle className="text-base">Workflow Status</CardTitle>
            </CardHeader>
            <CardContent>
                <ul className="flex flex-wrap gap-x-6 gap-y-2">
                    {steps.map((step) => (
                        <li key={step.label} className="flex items-center gap-1.5 text-sm">
                            {step.done ? <CheckCircle2 className="text-primary h-4 w-4" /> : <Circle className="text-muted-foreground h-4 w-4" />}
                            <span className={step.done ? '' : 'text-muted-foreground'}>{step.label}</span>
                        </li>
                    ))}
                </ul>
            </CardContent>
        </Card>
    );
}
