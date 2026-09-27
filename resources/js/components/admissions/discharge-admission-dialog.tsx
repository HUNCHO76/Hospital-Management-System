import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { dischargeTypeLabel } from '@/lib/admission-status';
import { type DischargeFormData, type DischargeType } from '@/types/admission';
import { useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { type FormEventHandler, type ReactNode } from 'react';

interface DischargeAdmissionDialogProps {
    admissionId: number;
    dischargeTypes: DischargeType[];
    trigger: ReactNode;
}

/**
 * Discharge requires both a discharge type and reason (both enforced
 * server-side too) — mirrors CancelAdmissionDialog's own shape, extended
 * with the type select.
 */
export function DischargeAdmissionDialog({ admissionId, dischargeTypes, trigger }: DischargeAdmissionDialogProps) {
    const { data, setData, post, processing, errors, reset } = useForm<DischargeFormData>({
        discharge_type: '',
        discharge_reason: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('admissions.discharge', admissionId), { onSuccess: () => reset() });
    };

    return (
        <Dialog>
            <DialogTrigger asChild>{trigger}</DialogTrigger>
            <DialogContent>
                <form onSubmit={submit}>
                    <DialogHeader>
                        <DialogTitle>Discharge patient</DialogTitle>
                        <DialogDescription>Record the discharge type and reason for this admission.</DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-4 py-4">
                        <div className="grid gap-2">
                            <Label htmlFor="discharge_type">Discharge type *</Label>
                            <Select value={data.discharge_type} onValueChange={(value) => setData('discharge_type', value as DischargeFormData['discharge_type'])}>
                                <SelectTrigger id="discharge_type">
                                    <SelectValue placeholder="Select a discharge type" />
                                </SelectTrigger>
                                <SelectContent>
                                    {dischargeTypes.map((type) => (
                                        <SelectItem key={type} value={type}>
                                            {dischargeTypeLabel[type] ?? type}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={errors.discharge_type} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="discharge_reason">Discharge reason *</Label>
                            <Textarea
                                id="discharge_reason"
                                value={data.discharge_reason}
                                onChange={(e) => setData('discharge_reason', e.target.value)}
                                rows={3}
                            />
                            <InputError message={errors.discharge_reason} />
                        </div>
                    </div>

                    <DialogFooter>
                        <Button type="submit" disabled={processing}>
                            {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                            Discharge Patient
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
