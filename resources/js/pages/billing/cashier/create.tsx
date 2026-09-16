import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { type OpenSessionFormData } from '@/types/cashier';
import { Head, useForm } from '@inertiajs/react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Billing', href: '/billing' },
    { title: 'Cashier Sessions', href: route('billing.cashier.index') },
    { title: 'Open Session', href: '#' },
];

interface Props {
    hospitals: { id: number; name: string }[];
}

export default function CashierSessionCreate({ hospitals }: Props) {
    const { data, setData, post, processing, errors } = useForm<OpenSessionFormData>({
        hospital_id: hospitals.length === 1 ? String(hospitals[0].id) : '',
        opening_balance: '0',
        notes: '',
    });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        post(route('billing.cashier.store'));
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Open Cashier Session" />

            <div className="flex flex-col gap-6 p-4">
                <div>
                    <h1 className="text-2xl font-semibold">Open Session</h1>
                    <p className="text-muted-foreground text-sm">Start a new cashier session by recording the opening cash balance.</p>
                </div>

                <form onSubmit={submit} className="max-w-lg">
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Session Details</CardTitle>
                        </CardHeader>
                        <CardContent className="flex flex-col gap-4">
                            <div className="flex flex-col gap-1.5">
                                <Label htmlFor="hospital_id">Facility</Label>
                                <Select
                                    value={data.hospital_id}
                                    onValueChange={(v) => setData('hospital_id', v)}
                                    disabled={hospitals.length === 1}
                                >
                                    <SelectTrigger id="hospital_id">
                                        <SelectValue placeholder="Select facility" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {hospitals.map((h) => (
                                            <SelectItem key={h.id} value={String(h.id)}>
                                                {h.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                {errors.hospital_id && <p className="text-destructive text-sm">{errors.hospital_id}</p>}
                            </div>

                            <div className="flex flex-col gap-1.5">
                                <Label htmlFor="opening_balance">Opening Cash Balance</Label>
                                <Input
                                    id="opening_balance"
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    value={data.opening_balance}
                                    onChange={(e) => setData('opening_balance', e.target.value)}
                                />
                                <p className="text-muted-foreground text-xs">The amount of cash in the drawer at the start of this session.</p>
                                {errors.opening_balance && <p className="text-destructive text-sm">{errors.opening_balance}</p>}
                            </div>

                            <div className="flex flex-col gap-1.5">
                                <Label htmlFor="notes">Notes (optional)</Label>
                                <Textarea
                                    id="notes"
                                    value={data.notes}
                                    onChange={(e) => setData('notes', e.target.value)}
                                    rows={2}
                                    placeholder="Any notes about this session…"
                                />
                                {errors.notes && <p className="text-destructive text-sm">{errors.notes}</p>}
                            </div>

                            {errors.status && <p className="text-destructive text-sm">{errors.status}</p>}

                            <Button type="submit" disabled={processing} className="self-start">
                                {processing ? 'Opening…' : 'Open Session'}
                            </Button>
                        </CardContent>
                    </Card>
                </form>
            </div>
        </AppLayout>
    );
}
