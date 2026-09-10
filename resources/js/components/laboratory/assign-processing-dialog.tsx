import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { type AssignProcessingFormData, type LaboratoryBenchOption, type LaboratoryOrderUser } from '@/types/laboratory';
import { useForm } from '@inertiajs/react';
import { AlertTriangle, LoaderCircle } from 'lucide-react';
import { type FormEventHandler, type ReactNode } from 'react';

interface AssignProcessingDialogProps {
    processingId: number;
    testName: string;
    technicians: LaboratoryOrderUser[];
    benches: LaboratoryBenchOption[];
    trigger: ReactNode;
}

const NONE = '__none__';

/**
 * Assigns a single test's processing to a technician and (optionally) a
 * bench (Phase 4, Step 32, spec §10-11). Assignment is optional in the
 * overall workflow — processing can also be started directly once
 * accepted.
 */
export function AssignProcessingDialog({ processingId, testName, technicians, benches, trigger }: AssignProcessingDialogProps) {
    const { data, setData, post, processing, errors, reset } = useForm<AssignProcessingFormData>({
        assigned_to: '',
        bench_id: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('laboratory.processing.assign', processingId), { onSuccess: () => reset(), preserveScroll: true });
    };

    const noTechnicians = technicians.length === 0;
    const noBenches = benches.length === 0;

    return (
        <Dialog>
            <DialogTrigger asChild>{trigger}</DialogTrigger>
            <DialogContent>
                <form onSubmit={submit}>
                    <DialogHeader>
                        <DialogTitle>Assign {testName}</DialogTitle>
                        <DialogDescription>Choose a technician and, if applicable, a work area.</DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-4 py-4">
                        <div className="grid gap-2">
                            <Label>Technician *</Label>
                            {noTechnicians ? (
                                <div className="flex items-center gap-2 rounded-md border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-200">
                                    <AlertTriangle className="h-4 w-4 shrink-0" />
                                    <span>
                                        No laboratory technicians are configured. Assign the{' '}
                                        <strong>laboratory-technician</strong> role to a user first.
                                    </span>
                                </div>
                            ) : (
                                <Select value={data.assigned_to} onValueChange={(value) => setData('assigned_to', value)}>
                                    <SelectTrigger>
                                        <SelectValue placeholder="Select a technician" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {technicians.map((tech) => (
                                            <SelectItem key={tech.id} value={String(tech.id)}>
                                                {tech.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            )}
                            <InputError message={errors.assigned_to} />
                        </div>
                        <div className="grid gap-2">
                            <Label>Bench</Label>
                            {noBenches ? (
                                <div className="flex items-center gap-2 rounded-md border border-blue-200 bg-blue-50 p-3 text-sm text-blue-800 dark:border-blue-800 dark:bg-blue-950 dark:text-blue-200">
                                    <AlertTriangle className="h-4 w-4 shrink-0" />
                                    <span>
                                        No active benches found. Add benches under{' '}
                                        <strong>Laboratory → Benches</strong> to enable bench assignment.
                                    </span>
                                </div>
                            ) : (
                                <Select value={data.bench_id || NONE} onValueChange={(value) => setData('bench_id', value === NONE ? '' : value)}>
                                    <SelectTrigger>
                                        <SelectValue placeholder="Not specified" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value={NONE}>Not specified</SelectItem>
                                        {benches.map((bench) => (
                                            <SelectItem key={bench.id} value={String(bench.id)}>
                                                {bench.name} ({bench.code})
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            )}
                            <InputError message={errors.bench_id} />
                        </div>
                    </div>

                    <DialogFooter>
                        <Button type="submit" disabled={processing || !data.assigned_to || noTechnicians}>
                            {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                            Assign
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

