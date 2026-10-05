import { NhifItemCodeSearch } from '@/components/nhif/nhif-item-code-search';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'NHIF', href: '/nhif/dashboard' },
    { title: 'Item Mapping', href: '/nhif/item-mapping' },
];

interface LabTest {
    id: number;
    code: string;
    name: string;
    nhif_code: string | null;
}

interface Med {
    id: number;
    code: string;
    generic_name: string;
    nhif_code: string | null;
}

interface Stats {
    lab_total: number;
    lab_mapped: number;
    med_total: number;
    med_mapped: number;
}

interface Props {
    laboratoryTests: LabTest[];
    medications: Med[];
    stats: Stats;
}

type Tab = 'lab' | 'med';

export default function ItemMappingIndex({ laboratoryTests, medications, stats }: Props) {
    const [tab, setTab] = useState<Tab>('lab');
    const [unmappedOnly, setUnmappedOnly] = useState(false);

    const filteredLabs = unmappedOnly ? laboratoryTests.filter((t) => !t.nhif_code) : laboratoryTests;
    const filteredMeds = unmappedOnly ? medications.filter((m) => !m.nhif_code) : medications;

    const handleLabSelect = (testId: number, code: string) => {
        router.patch(
            route('nhif.item-mapping.laboratory-tests.update', testId),
            { nhif_code: code || null },
            { preserveScroll: true },
        );
    };

    const handleMedSelect = (medId: number, code: string) => {
        router.patch(
            route('nhif.item-mapping.medications.update', medId),
            { nhif_code: code || null },
            { preserveScroll: true },
        );
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="NHIF Item Mapping" />

            <div className="flex h-full flex-col gap-6 p-4">
                <div>
                    <h1 className="text-xl font-semibold">NHIF Item Code Mapping</h1>
                    <p className="text-muted-foreground text-sm">
                        Map local laboratory tests and medications to NHIF item codes from the catalog.
                    </p>
                </div>

                <div className="flex flex-wrap gap-4">
                    <div className="rounded-md border p-4">
                        <p className="text-muted-foreground text-xs font-medium uppercase tracking-wide">Laboratory Tests</p>
                        <p className="mt-1 text-lg font-semibold">
                            {stats.lab_mapped} <span className="text-muted-foreground text-sm font-normal">/ {stats.lab_total} mapped</span>
                        </p>
                    </div>
                    <div className="rounded-md border p-4">
                        <p className="text-muted-foreground text-xs font-medium uppercase tracking-wide">Medications</p>
                        <p className="mt-1 text-lg font-semibold">
                            {stats.med_mapped} <span className="text-muted-foreground text-sm font-normal">/ {stats.med_total} mapped</span>
                        </p>
                    </div>
                </div>

                <div className="flex flex-wrap items-center gap-4">
                    <div className="flex gap-1">
                        <Button
                            variant={tab === 'lab' ? 'default' : 'outline'}
                            size="sm"
                            onClick={() => setTab('lab')}
                        >
                            Laboratory Tests
                        </Button>
                        <Button
                            variant={tab === 'med' ? 'default' : 'outline'}
                            size="sm"
                            onClick={() => setTab('med')}
                        >
                            Medications
                        </Button>
                    </div>
                    <label className="flex items-center gap-2 text-sm">
                        <Checkbox
                            checked={unmappedOnly}
                            onCheckedChange={(v) => setUnmappedOnly(v === true)}
                        />
                        Show unmapped only
                    </label>
                </div>

                {tab === 'lab' && (
                    <div className="rounded-md border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/40 border-b">
                                <tr>
                                    <th className="px-4 py-3 text-left font-medium">Code</th>
                                    <th className="px-4 py-3 text-left font-medium">Name</th>
                                    <th className="px-4 py-3 text-left font-medium">NHIF Item Code</th>
                                    <th className="px-4 py-3 text-left font-medium">Status</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {filteredLabs.length === 0 ? (
                                    <tr>
                                        <td colSpan={4} className="text-muted-foreground px-4 py-8 text-center">
                                            {unmappedOnly ? 'All laboratory tests are mapped.' : 'No active laboratory tests.'}
                                        </td>
                                    </tr>
                                ) : (
                                    filteredLabs.map((test) => (
                                        <tr key={test.id} className="hover:bg-muted/30">
                                            <td className="px-4 py-2 font-mono text-xs">{test.code}</td>
                                            <td className="px-4 py-2">{test.name}</td>
                                            <td className="px-4 py-2">
                                                <NhifItemCodeSearch
                                                    value={test.nhif_code ?? ''}
                                                    onSelect={(code) => handleLabSelect(test.id, code)}
                                                    typeId={5}
                                                    compact
                                                />
                                            </td>
                                            <td className="px-4 py-2">
                                                {test.nhif_code ? (
                                                    <Badge variant="default" className="bg-green-100 text-green-700">Mapped</Badge>
                                                ) : (
                                                    <Badge variant="outline" className="text-muted-foreground">Unmapped</Badge>
                                                )}
                                            </td>
                                        </tr>
                                    ))
                                )}
                            </tbody>
                        </table>
                    </div>
                )}

                {tab === 'med' && (
                    <div className="rounded-md border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/40 border-b">
                                <tr>
                                    <th className="px-4 py-3 text-left font-medium">Code</th>
                                    <th className="px-4 py-3 text-left font-medium">Name</th>
                                    <th className="px-4 py-3 text-left font-medium">NHIF Item Code</th>
                                    <th className="px-4 py-3 text-left font-medium">Status</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {filteredMeds.length === 0 ? (
                                    <tr>
                                        <td colSpan={4} className="text-muted-foreground px-4 py-8 text-center">
                                            {unmappedOnly ? 'All medications are mapped.' : 'No active medications.'}
                                        </td>
                                    </tr>
                                ) : (
                                    filteredMeds.map((med) => (
                                        <tr key={med.id} className="hover:bg-muted/30">
                                            <td className="px-4 py-2 font-mono text-xs">{med.code}</td>
                                            <td className="px-4 py-2">{med.generic_name}</td>
                                            <td className="px-4 py-2">
                                                <NhifItemCodeSearch
                                                    value={med.nhif_code ?? ''}
                                                    onSelect={(code) => handleMedSelect(med.id, code)}
                                                    typeId={3}
                                                    compact
                                                />
                                            </td>
                                            <td className="px-4 py-2">
                                                {med.nhif_code ? (
                                                    <Badge variant="default" className="bg-green-100 text-green-700">Mapped</Badge>
                                                ) : (
                                                    <Badge variant="outline" className="text-muted-foreground">Unmapped</Badge>
                                                )}
                                            </td>
                                        </tr>
                                    ))
                                )}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
