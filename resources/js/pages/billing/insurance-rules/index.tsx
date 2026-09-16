import { EmptyState } from '@/components/empty-state';
import { Pagination } from '@/components/pagination';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { usePermissions } from '@/hooks/use-permissions';
import AppLayout from '@/layouts/app-layout';
import { coverageTypeLabel, insuranceCoverageRuleStatusLabel, insuranceCoverageRuleStatusVariant } from '@/lib/insurance-billing-status';
import { type BreadcrumbItem, type Paginated } from '@/types';
import { type InsuranceCoverageRule, type InsuranceCoverageRuleFilters } from '@/types/insurance-billing';
import { Head, Link, router } from '@inertiajs/react';
import { ShieldCheck } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Billing', href: '/billing' },
    { title: 'Insurance Rules', href: '/billing/insurance-rules' },
];

interface BillingInsuranceRulesIndexProps {
    rules: Paginated<InsuranceCoverageRule>;
    filters: InsuranceCoverageRuleFilters;
    schemes: { id: number; name: string; code: string }[];
}

function targetLabel(rule: InsuranceCoverageRule): string {
    if (rule.service) {
        return `${rule.service.code} — ${rule.service.name}`;
    }

    if (rule.category) {
        return `Category: ${rule.category}`;
    }

    return "Scheme default";
}

/**
 * The insurance coverage rule admin list (Phase 7, Step 56, spec §31) —
 * every rule configured for every scheme, newest effective date first. A
 * rule is create-or-cancel only, never edited in place (mirrors the
 * service pricing list's own precedent, which likewise has no inline edit).
 */
export default function BillingInsuranceRulesIndex({ rules, filters, schemes }: BillingInsuranceRulesIndexProps) {
    const { can } = usePermissions();

    const applyFilters = (overrides: Partial<InsuranceCoverageRuleFilters>) => {
        const params: Record<string, string> = { ...filters, ...overrides };
        router.get(route('billing-insurance-rules.index'), params, { preserveState: true, replace: true });
    };

    const hasFilters = Boolean(filters.insurance_scheme_id || filters.status);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Insurance Coverage Rules" />

            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-semibold">Insurance Coverage Rules</h1>
                        <p className="text-muted-foreground text-sm">How much of each service every insurance scheme covers.</p>
                    </div>
                    {can('billing-insurance-rules.create') && (
                        <Button asChild>
                            <Link href={route('billing-insurance-rules.create')}>New Rule</Link>
                        </Button>
                    )}
                </div>

                <div className="flex flex-wrap items-center gap-2">
                    <Select
                        value={filters.insurance_scheme_id || 'all'}
                        onValueChange={(value) => applyFilters({ insurance_scheme_id: value === 'all' ? '' : value })}
                    >
                        <SelectTrigger className="w-[240px]">
                            <SelectValue placeholder="All schemes" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All schemes</SelectItem>
                            {schemes.map((scheme) => (
                                <SelectItem key={scheme.id} value={String(scheme.id)}>
                                    {scheme.code} — {scheme.name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>

                    {hasFilters && (
                        <Button variant="ghost" size="sm" onClick={() => router.get(route('billing-insurance-rules.index'))}>
                            Clear filters
                        </Button>
                    )}
                </div>

                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Scheme</TableHead>
                            <TableHead>Applies To</TableHead>
                            <TableHead>Coverage</TableHead>
                            <TableHead>Effective From</TableHead>
                            <TableHead>Effective To</TableHead>
                            <TableHead>Status</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {rules.data.map((rule) => (
                            <TableRow key={rule.id}>
                                <TableCell>{rule.insurance_scheme ? `${rule.insurance_scheme.code} — ${rule.insurance_scheme.name}` : '—'}</TableCell>
                                <TableCell>{targetLabel(rule)}</TableCell>
                                <TableCell>
                                    <div className="flex flex-col">
                                        <span>{coverageTypeLabel[rule.coverage_type]}</span>
                                        {rule.coverage_type === 'percentage' && (
                                            <span className="text-muted-foreground text-xs">{rule.coverage_percentage}%</span>
                                        )}
                                        {rule.coverage_type === 'fixed_copay' && (
                                            <span className="text-muted-foreground text-xs">Copay: {rule.fixed_copay_amount}</span>
                                        )}
                                        {rule.requires_preauthorization && <span className="text-xs text-amber-600">Preauth required</span>}
                                    </div>
                                </TableCell>
                                <TableCell>{rule.effective_from}</TableCell>
                                <TableCell>{rule.effective_to ?? 'Ongoing'}</TableCell>
                                <TableCell>
                                    <Badge variant={insuranceCoverageRuleStatusVariant[rule.status]}>
                                        {insuranceCoverageRuleStatusLabel[rule.status]}
                                    </Badge>
                                </TableCell>
                            </TableRow>
                        ))}

                        {rules.data.length === 0 && (
                            <TableRow>
                                <TableCell colSpan={6} className="p-0">
                                    <EmptyState
                                        icon={ShieldCheck}
                                        title={hasFilters ? 'No rules match your filters' : 'No coverage rules configured yet'}
                                        description={
                                            hasFilters
                                                ? 'Try a different filter or clear it.'
                                                : 'A service with no matching rule is treated as not covered by that scheme.'
                                        }
                                    />
                                </TableCell>
                            </TableRow>
                        )}
                    </TableBody>
                </Table>

                <Pagination links={rules.links} />
            </div>
        </AppLayout>
    );
}
