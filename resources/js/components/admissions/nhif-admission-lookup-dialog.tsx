import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useState } from 'react';
import { Loader2, Search } from 'lucide-react';
import axios from 'axios';

interface NhifAdmissionDetail {
    AdmissionNo?: string;
    CardNo?: string;
    FirstName?: string;
    LastName?: string;
    DateAdmitted?: string;
    SchemeID?: number | string;
    WardTypeID?: number | string;
    RoomTypeID?: number | string;
    Diagnosis?: string;
    [key: string]: any;
}

export function NhifAdmissionLookupDialog() {
    const [open, setOpen] = useState(false);
    const [authNo, setAuthNo] = useState('');
    const [loading, setLoading] = useState(false);
    const [result, setResult] = useState<NhifAdmissionDetail | null>(null);
    const [error, setError] = useState<string | null>(null);

    const handleLookup = async () => {
        if (!authNo.trim()) return;

        setLoading(true);
        setError(null);
        setResult(null);

        try {
            const response = await axios.get(route('nhif.admissions.lookup'), {
                params: { authorization_no: authNo.trim() },
            });
            setResult(response.data);
        } catch (err: any) {
            setError(err.response?.data?.error || 'Failed to lookup admission details. Please check the authorization number.');
        } finally {
            setLoading(false);
        }
    };

    return (
        <Dialog open={open} onOpenChange={(isOpen) => {
            setOpen(isOpen);
            if (!isOpen) {
                setAuthNo('');
                setResult(null);
                setError(null);
            }
        }}>
            <DialogTrigger asChild>
                <Button variant="outline">
                    <Search className="mr-2 h-4 w-4" />
                    Lookup NHIF Admission
                </Button>
            </DialogTrigger>
            <DialogContent className="sm:max-w-[500px]">
                <DialogHeader>
                    <DialogTitle>NHIF Admission Lookup</DialogTitle>
                    <DialogDescription>
                        Enter an NHIF Authorization Number to retrieve admission details directly from NHIF.
                    </DialogDescription>
                </DialogHeader>

                <div className="grid gap-4 py-4">
                    <div className="grid grid-cols-4 items-center gap-4">
                        <Label htmlFor="authNo" className="text-right">
                            Auth No
                        </Label>
                        <Input
                            id="authNo"
                            value={authNo}
                            onChange={(e) => setAuthNo(e.target.value)}
                            className="col-span-3"
                            placeholder="e.g. 123456789"
                            onKeyDown={(e) => e.key === 'Enter' && handleLookup()}
                        />
                    </div>
                    <div className="flex justify-end">
                        <Button onClick={handleLookup} disabled={loading || !authNo.trim()}>
                            {loading && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
                            Search
                        </Button>
                    </div>

                    {error && (
                        <div className="mt-4 rounded-md bg-destructive/15 p-3 text-sm text-destructive">
                            {error}
                        </div>
                    )}

                    {result && (
                        <div className="mt-4 rounded-md border p-4 text-sm">
                            <h4 className="mb-2 font-semibold text-primary">Admission Found</h4>
                            <div className="grid grid-cols-2 gap-2">
                                <div className="text-muted-foreground">Admission No:</div>
                                <div className="font-medium">{result.AdmissionNo ?? '—'}</div>
                                
                                <div className="text-muted-foreground">Card No:</div>
                                <div>{result.CardNo ?? '—'}</div>
                                
                                <div className="text-muted-foreground">Patient Name:</div>
                                <div className="font-medium">{result.FullName || [result.FirstName, result.LastName].filter(Boolean).join(' ') || '—'}</div>
                                
                                <div className="text-muted-foreground">Date Admitted:</div>
                                <div>{result.DateAdmitted ? new Date(result.DateAdmitted).toLocaleDateString() : '—'}</div>
                                
                                <div className="text-muted-foreground">Scheme:</div>
                                <div>{result.SchemeID ?? '—'}</div>
                                
                                <div className="text-muted-foreground">Ward / Room:</div>
                                <div>{result.WardTypeID ?? '—'} / {result.RoomTypeID ?? '—'}</div>

                                <div className="text-muted-foreground">Facility Code:</div>
                                <div>{result.FacilityCode ?? '—'}</div>

                                <div className="text-muted-foreground">Practitioner No:</div>
                                <div>{result.AdmittingPractitionerNo ?? '—'}</div>
                                
                                <div className="text-muted-foreground">Diagnosis:</div>
                                <div>{result.DiagnosisAtAdmission || result.Diagnosis || '—'}</div>
                            </div>
                        </div>
                    )}
                </div>
            </DialogContent>
        </Dialog>
    );
}
