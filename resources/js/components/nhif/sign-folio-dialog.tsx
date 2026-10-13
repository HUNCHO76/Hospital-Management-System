import { SignaturePad } from '@/components/nhif/signature-pad';
import { SIGNED_BY_OPTIONS } from '@/components/nhif/nhif-claim-finalize-dialog';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { router } from '@inertiajs/react';
import { AlertCircle, Loader2, ShieldCheck } from 'lucide-react';
import { useState } from 'react';

interface SignFolioDialogProps {
    claimId: number;
    patientName?: string | null;
    trigger: React.ReactNode;
}

/**
 * Signs a ready NHIF folio with a manual signature (Topaz pad or on-screen).
 * Posts to `nhif.claims.sign`; the server requires `signature_data` for manual signing.
 */
export function SignFolioDialog({ claimId, patientName, trigger }: SignFolioDialogProps) {
    const [open, setOpen] = useState(false);
    const [signedBy, setSignedBy] = useState<string>('PATIENT');
    const [signedByName, setSignedByName] = useState(patientName ?? '');
    const [remarks, setRemarks] = useState('');
    const [signature, setSignature] = useState<string | null>(null);
    const [submitting, setSubmitting] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const handleOpenChange = (next: boolean) => {
        setOpen(next);

        if (!next) {
            setSignature(null);
            setError(null);
        }
    };

    const handleSign = () => {
        if (!signature) {
            setError("Please capture the signatory's signature before signing the folio.");

            return;
        }

        setError(null);
        router.post(
            route('nhif.claims.sign', claimId),
            {
                signature_method: 'SIGNATURE',
                signature_data: signature,
                signed_by: signedBy,
                signed_by_name: signedByName || patientName || 'Patient',
                remarks: remarks || null,
            },
            {
                preserveScroll: true,
                onStart: () => setSubmitting(true),
                onFinish: () => setSubmitting(false),
                onSuccess: () => handleOpenChange(false),
                onError: (errors) => setError(Object.values(errors).flat().join(' ') || 'Signing failed.'),
            },
        );
    };

    return (
        <Dialog open={open} onOpenChange={handleOpenChange}>
            <DialogTrigger asChild>{trigger}</DialogTrigger>
            <DialogContent className="max-h-[90vh] max-w-xl overflow-y-auto">
                <DialogHeader>
                    <DialogTitle>SignFolio &amp; Authorized Signatory</DialogTitle>
                    <DialogDescription>Capture the manual signature of the patient or authorized signatory to sign this folio.</DialogDescription>
                </DialogHeader>

                <div className="space-y-4 text-xs">
                    {error && (
                        <Alert variant="destructive" className="py-2">
                            <AlertCircle className="h-4 w-4" />
                            <AlertDescription>{error}</AlertDescription>
                        </Alert>
                    )}

                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div className="space-y-1">
                            <Label htmlFor="sign-folio-by">Signed By (Relationship)</Label>
                            <Select value={signedBy} onValueChange={setSignedBy}>
                                <SelectTrigger id="sign-folio-by" className="h-8 text-xs">
                                    <SelectValue placeholder="Select signatory relationship" />
                                </SelectTrigger>
                                <SelectContent>
                                    {SIGNED_BY_OPTIONS.map((option) => (
                                        <SelectItem key={option.value} value={option.value} className="text-xs">
                                            {option.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>

                        <div className="space-y-1">
                            <Label htmlFor="sign-folio-name">Signatory Full Name</Label>
                            <Input
                                id="sign-folio-name"
                                className="h-8 text-xs"
                                value={signedByName}
                                placeholder="Patient or signatory name"
                                onChange={(event) => setSignedByName(event.target.value)}
                            />
                        </div>
                    </div>

                    {open && <SignaturePad value={signature} onChange={setSignature} disabled={submitting} />}

                    <div className="space-y-1">
                        <Label htmlFor="sign-folio-remarks">Remarks (Optional)</Label>
                        <Input
                            id="sign-folio-remarks"
                            className="h-8 text-xs"
                            value={remarks}
                            placeholder="Optional remarks for this signature..."
                            onChange={(event) => setRemarks(event.target.value)}
                        />
                    </div>
                </div>

                <DialogFooter className="gap-2">
                    <Button variant="outline" onClick={() => handleOpenChange(false)} disabled={submitting}>
                        Cancel
                    </Button>
                    <Button
                        className="bg-emerald-600 text-white hover:bg-emerald-700"
                        onClick={handleSign}
                        disabled={!signature || submitting}
                    >
                        {submitting ? <Loader2 className="mr-2 h-4 w-4 animate-spin" /> : <ShieldCheck className="mr-2 h-4 w-4" />}
                        Sign Folio
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
