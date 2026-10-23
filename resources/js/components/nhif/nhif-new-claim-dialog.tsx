import { Badge } from '@/components/ui/badge';
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
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { NhifClaimFinalizeDialog } from '@/components/nhif/nhif-claim-finalize-dialog';
import { Link } from '@inertiajs/react';
import axios from 'axios';
import { AlertCircle, CheckCircle2, Clock, FilePlus2, Loader2, Plus, Search, ShieldCheck, User } from 'lucide-react';
import { useEffect, useState } from 'react';

interface EligibleVisit {
    id: number;
    type: 'encounter' | 'admission';
    visit_number: string;
    patient_id: number;
    patient_name: string;
    patient_number: string;
    card_number: string | null;
    authorization_no: string | null;
    department: string;
    date: string;
    status: string;
    is_completed: boolean;
    existing_claim: {
        id: number;
        claim_number: string;
        status: string;
    } | null;
}

interface NhifNewClaimDialogProps {
    trigger?: React.ReactNode;
}

export function NhifNewClaimDialog({ trigger }: NhifNewClaimDialogProps) {
    const [open, setOpen] = useState(false);
    const [search, setSearch] = useState('');
    const [loading, setLoading] = useState(false);
    const [visits, setVisits] = useState<EligibleVisit[]>([]);
    const [error, setError] = useState<string | null>(null);

    const fetchVisits = async (searchTerm = '') => {
        setLoading(true);
        setError(null);
        try {
            const res = await axios.get(route('nhif.claims.eligible-visits'), {
                params: { search: searchTerm },
            });
            setVisits(res.data.data ?? []);
        } catch (err: any) {
            setError(err.response?.data?.message || 'Failed to load eligible patient visits.');
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        if (open) {
            fetchVisits(search);
        }
    }, [open]);

    const handleSearch = (e: React.FormEvent) => {
        e.preventDefault();
        fetchVisits(search);
    };

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                {trigger ?? (
                    <Button>
                        <Plus className="mr-2 h-4 w-4" />
                        New Claim
                    </Button>
                )}
            </DialogTrigger>
            <DialogContent className="max-w-4xl max-h-[85vh] overflow-y-auto">
                <DialogHeader>
                    <DialogTitle className="flex items-center gap-2 text-lg">
                        <FilePlus2 className="h-5 w-5 text-primary" />
                        Create New NHIF Claim
                    </DialogTitle>
                    <DialogDescription>
                        Search for an NHIF patient with completed clinical services or hospital admission to finalize their claim.
                    </DialogDescription>
                </DialogHeader>

                <div className="space-y-4 py-2">
                    {/* Search Form */}
                    <form onSubmit={handleSearch} className="flex gap-2">
                        <div className="relative flex-1">
                            <Search className="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" />
                            <Input
                                placeholder="Search by patient name, NHIF card no, auth no, or file number..."
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                className="pl-9"
                            />
                        </div>
                        <Button type="submit" variant="secondary" disabled={loading}>
                            {loading ? <Loader2 className="h-4 w-4 animate-spin" /> : 'Search'}
                        </Button>
                    </form>

                    {error && (
                        <div className="rounded-md bg-destructive/10 p-3 text-sm text-destructive">
                            {error}
                        </div>
                    )}

                    {/* Results Table */}
                    <div className="rounded-md border">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Patient</TableHead>
                                    <TableHead>NHIF Details</TableHead>
                                    <TableHead>Visit & Dept</TableHead>
                                    <TableHead>Date & Status</TableHead>
                                    <TableHead className="text-right">Action</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {loading && visits.length === 0 ? (
                                    <TableRow>
                                        <TableCell colSpan={5} className="py-8 text-center text-muted-foreground">
                                            <Loader2 className="mx-auto h-6 w-6 animate-spin" />
                                            <p className="mt-2 text-xs">Finding eligible NHIF visits...</p>
                                        </TableCell>
                                    </TableRow>
                                ) : visits.length === 0 ? (
                                    <TableRow>
                                        <TableCell colSpan={5} className="py-8 text-center text-muted-foreground">
                                            <User className="mx-auto h-8 w-8 text-muted-foreground/40" />
                                            <p className="mt-2 text-sm font-medium">No eligible NHIF patient visits found</p>
                                            <p className="text-xs text-muted-foreground">
                                                Ensure the patient has verified NHIF insurance with an authorization number.
                                            </p>
                                        </TableCell>
                                    </TableRow>
                                ) : (
                                    visits.map((v) => (
                                        <TableRow key={`${v.type}-${v.id}`}>
                                            <TableCell>
                                                <div className="font-medium">{v.patient_name}</div>
                                                <div className="text-xs text-muted-foreground">
                                                    File: {v.patient_number}
                                                </div>
                                            </TableCell>
                                            <TableCell>
                                                <div className="flex items-center gap-1 text-xs font-mono font-medium">
                                                    <ShieldCheck className="h-3.5 w-3.5 text-blue-600" />
                                                    {v.card_number || 'No card'}
                                                </div>
                                                <div className="text-xs text-muted-foreground">
                                                    Auth: {v.authorization_no || 'Missing'}
                                                </div>
                                            </TableCell>
                                            <TableCell>
                                                <div className="flex items-center gap-1.5">
                                                    <Badge variant={v.type === 'encounter' ? 'secondary' : 'default'} className="text-[10px] uppercase">
                                                        {v.type}
                                                    </Badge>
                                                    <span className="text-xs font-mono">{v.visit_number}</span>
                                                </div>
                                                <div className="text-xs text-muted-foreground">{v.department}</div>
                                            </TableCell>
                                            <TableCell>
                                                <div className="text-xs">{v.date}</div>
                                                <Badge
                                                    variant={v.is_completed ? 'default' : 'outline'}
                                                    className="mt-0.5 text-[10px]"
                                                >
                                                    {v.status}
                                                </Badge>
                                            </TableCell>
                                            <TableCell className="text-right">
                                                {v.existing_claim && ['submitted', 'finalized', 'confirmed'].includes(v.existing_claim.status) ? (
                                                    <Button asChild size="sm" variant="outline">
                                                        <Link href={route('nhif.claims.show', v.existing_claim.id)}>
                                                            View #{v.existing_claim.claim_number}
                                                        </Link>
                                                    </Button>
                                                ) : v.type === 'encounter' && !v.is_completed ? (
                                                    <Button
                                                        size="sm"
                                                        variant="outline"
                                                        className="gap-1 border-amber-500/40 text-amber-700 hover:bg-amber-500/10 dark:text-amber-400"
                                                        onClick={() => {
                                                            alert('Doctor should complete Clinical Encounters for the patient before sending claim.');
                                                        }}
                                                        title="Doctor should complete Clinical Encounters for the patient before sending claim"
                                                    >
                                                        <AlertCircle className="h-3.5 w-3.5" />
                                                        Complete Encounter First
                                                    </Button>
                                                ) : (
                                                    <NhifClaimFinalizeDialog
                                                        clinicalEncounterId={v.type === 'encounter' ? v.id : undefined}
                                                        admissionId={v.type === 'admission' ? v.id : undefined}
                                                        patientName={v.patient_name}
                                                        patientCardNo={v.card_number ?? undefined}
                                                        trigger={
                                                            <Button size="sm" className="gap-1">
                                                                <CheckCircle2 className="h-3.5 w-3.5" />
                                                                {v.existing_claim ? 'Resume Claim' : 'Finalize Claim'}
                                                            </Button>
                                                        }
                                                    />
                                                )}
                                            </TableCell>
                                        </TableRow>
                                    ))
                                )}
                            </TableBody>
                        </Table>
                    </div>
                </div>
            </DialogContent>
        </Dialog>
    );
}
