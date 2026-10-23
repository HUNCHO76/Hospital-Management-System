import { Can } from '@/components/authorization/can';
import { EmptyState } from '@/components/empty-state';
import { LaboratoryParameterDialog } from '@/components/laboratory/laboratory-parameter-dialog';
import { Pagination } from '@/components/pagination';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { usePermissions } from '@/hooks/use-permissions';
import AppLayout from '@/layouts/app-layout';
import {
    laboratoryResultTypeLabel,
    laboratorySpecimenContainerLabel,
    laboratorySpecimenTypeLabel,
    laboratoryTestStatusVariant,
} from '@/lib/laboratory-status';
import { type BreadcrumbItem, type Paginated } from '@/types';
import {
    type LaboratoryCatalogStats,
    type LaboratoryResultType,
    type LaboratorySpecimenContainer,
    type LaboratorySpecimenType,
    type LaboratoryTest,
    type LaboratoryTestCategoryOption,
    type LaboratoryTestFilters,
    type LaboratoryTestParameter,
} from '@/types/laboratory';
import { Head, Link, router } from '@inertiajs/react';
import {
    AlertCircle,
    CheckCircle2,
    ChevronDown,
    ChevronRight,
    Filter,
    FlaskConical,
    Layers,
    ListFilter,
    Pencil,
    Plus,
    Search,
    ShieldAlert,
    SlidersHorizontal,
    Trash2,
    X,
} from 'lucide-react';
import { type FormEventHandler, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Laboratory', href: '/laboratory' },
    { title: 'Test & Parameter Catalog', href: '/laboratory/tests' },
];

interface LaboratoryTestIndexProps {
    tests: Paginated<LaboratoryTest>;
    filters: LaboratoryTestFilters;
    stats?: LaboratoryCatalogStats;
    categories: LaboratoryTestCategoryOption[];
    specimenTypes: LaboratorySpecimenType[];
    specimenContainers?: LaboratorySpecimenContainer[];
    resultTypes: LaboratoryResultType[];
}

export default function LaboratoryTestIndex({
    tests,
    filters,
    stats,
    categories,
    specimenTypes,
    resultTypes,
}: LaboratoryTestIndexProps) {
    const { can } = usePermissions();
    const canManageParams = can('laboratory-test-parameters.manage');

    const [search, setSearch] = useState(filters.search ?? '');
    const [expandedTests, setExpandedTests] = useState<Set<number>>(new Set());

    // State for Parameter Dialog (Add / Edit)
    const [dialogOpen, setDialogOpen] = useState(false);
    const [dialogTest, setDialogTest] = useState<LaboratoryTest | null>(null);
    const [editingParameter, setEditingParameter] = useState<LaboratoryTestParameter | null>(null);

    const toggleExpand = (testId: number) => {
        setExpandedTests((prev) => {
            const next = new Set(prev);
            if (next.has(testId)) {
                next.delete(testId);
            } else {
                next.add(testId);
            }
            return next;
        });
    };

    const expandAll = () => {
        setExpandedTests(new Set(tests.data.map((t) => t.id)));
    };

    const collapseAll = () => {
        setExpandedTests(new Set());
    };

    const applyFilters = (overrides: Partial<LaboratoryTestFilters>) => {
        router.get(
            route('laboratory-tests.index'),
            {
                search,
                category_id: filters.category_id ?? '',
                department_id: filters.department_id ?? '',
                specimen_type: filters.specimen_type ?? '',
                result_type: filters.result_type ?? '',
                has_parameters: filters.has_parameters ?? '',
                scheme: filters.scheme ?? '',
                status: filters.status ?? '',
                ...overrides,
            },
            { preserveState: true, replace: true },
        );
    };

    const submitSearch: FormEventHandler = (e) => {
        e.preventDefault();
        applyFilters({ search });
    };

    const clearAllFilters = () => {
        setSearch('');
        router.get(route('laboratory-tests.index'));
    };

    const hasFilters = Boolean(
        filters.search ||
            filters.category_id ||
            filters.specimen_type ||
            filters.result_type ||
            filters.has_parameters ||
            filters.scheme ||
            filters.status,
    );

    const openAddParameter = (test: LaboratoryTest) => {
        setDialogTest(test);
        setEditingParameter(null);
        setDialogOpen(true);
    };

    const openEditParameter = (test: LaboratoryTest, parameter: LaboratoryTestParameter) => {
        setDialogTest(test);
        setEditingParameter(parameter);
        setDialogOpen(true);
    };

    const handleDeleteParameter = (test: LaboratoryTest, parameter: LaboratoryTestParameter) => {
        if (!confirm(`Are you sure you want to remove the parameter "${parameter.name}" (${parameter.code})?`)) {
            return;
        }

        router.delete(route('laboratory-test-parameters.destroy', [test.id, parameter.id]), {
            preserveScroll: true,
            preserveState: true,
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Laboratory Tests & Sample Parameters" />

            <div className="flex flex-col gap-6 p-4 sm:p-6">
                {/* Header Title & Actions */}
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-bold tracking-tight">Laboratory Tests & Sample Parameters</h1>
                        <p className="text-sm text-muted-foreground">
                            Manage lab test definitions, specimen sample requirements, and multi-analyte parameters.
                        </p>
                    </div>

                    <div className="flex items-center gap-2">
                        {tests.data.length > 0 && (
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={expandedTests.size > 0 ? collapseAll : expandAll}
                                className="text-xs"
                            >
                                <SlidersHorizontal className="mr-1.5 h-3.5 w-3.5" />
                                {expandedTests.size > 0 ? 'Collapse All' : 'Expand All Parameters'}
                            </Button>
                        )}

                        <Can permission="laboratory-tests.create">
                            <Button asChild size="sm">
                                <Link href={route('laboratory-tests.create')}>
                                    <Plus className="mr-1.5 h-4 w-4" />
                                    Add Test
                                </Link>
                            </Button>
                        </Can>
                    </div>
                </div>

                {/* Stat Metric Cards */}
                {stats && (
                    <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                        <Card
                            className={`cursor-pointer transition-colors hover:border-primary/50 ${!filters.has_parameters && !filters.scheme ? 'bg-muted/40' : ''}`}
                            onClick={() => applyFilters({ has_parameters: '', scheme: '' })}
                        >
                            <CardContent className="flex items-center justify-between p-4">
                                <div className="space-y-0.5">
                                    <p className="text-xs font-medium text-muted-foreground">Total Tests</p>
                                    <p className="text-2xl font-bold">{stats.total_tests}</p>
                                </div>
                                <div className="flex h-9 w-9 items-center justify-center rounded-lg bg-blue-500/10 text-blue-600 dark:text-blue-400">
                                    <FlaskConical className="h-5 w-5" />
                                </div>
                            </CardContent>
                        </Card>

                        <Card
                            className={`cursor-pointer transition-colors hover:border-primary/50 ${filters.has_parameters === 'with' ? 'border-primary bg-primary/5' : ''}`}
                            onClick={() => applyFilters({ has_parameters: filters.has_parameters === 'with' ? '' : 'with' })}
                        >
                            <CardContent className="flex items-center justify-between p-4">
                                <div className="space-y-0.5">
                                    <p className="text-xs font-medium text-muted-foreground">With Parameters</p>
                                    <p className="text-2xl font-bold text-emerald-600 dark:text-emerald-400">
                                        {stats.with_parameters}
                                    </p>
                                </div>
                                <div className="flex h-9 w-9 items-center justify-center rounded-lg bg-emerald-500/10 text-emerald-600 dark:text-emerald-400">
                                    <CheckCircle2 className="h-5 w-5" />
                                </div>
                            </CardContent>
                        </Card>

                        <Card
                            className={`cursor-pointer transition-colors hover:border-primary/50 ${filters.has_parameters === 'without' ? 'border-destructive bg-destructive/5' : ''}`}
                            onClick={() => applyFilters({ has_parameters: filters.has_parameters === 'without' ? '' : 'without' })}
                        >
                            <CardContent className="flex items-center justify-between p-4">
                                <div className="space-y-0.5">
                                    <p className="text-xs font-medium text-muted-foreground">Without Parameters</p>
                                    <p className={`text-2xl font-bold ${stats.without_parameters > 0 ? 'text-amber-600' : 'text-muted-foreground'}`}>
                                        {stats.without_parameters}
                                    </p>
                                </div>
                                <div className="flex h-9 w-9 items-center justify-center rounded-lg bg-amber-500/10 text-amber-600 dark:text-amber-400">
                                    <ShieldAlert className="h-5 w-5" />
                                </div>
                            </CardContent>
                        </Card>

                        <Card
                            className={`cursor-pointer transition-colors hover:border-primary/50 ${filters.scheme === 'nhif' ? 'border-cyan-500 bg-cyan-500/5' : ''}`}
                            onClick={() => applyFilters({ scheme: filters.scheme === 'nhif' ? '' : 'nhif' })}
                        >
                            <CardContent className="flex items-center justify-between p-4">
                                <div className="space-y-0.5">
                                    <p className="text-xs font-medium text-muted-foreground">NHIF Items</p>
                                    <p className="text-2xl font-bold text-cyan-600 dark:text-cyan-400">{stats.nhif_tests}</p>
                                </div>
                                <div className="flex h-9 w-9 items-center justify-center rounded-lg bg-cyan-500/10 text-cyan-600 dark:text-cyan-400">
                                    <Layers className="h-5 w-5" />
                                </div>
                            </CardContent>
                        </Card>
                    </div>
                )}

                {/* Filter and Search Bar */}
                <div className="flex flex-wrap items-center gap-2.5 rounded-lg border bg-card p-3 shadow-xs">
                    <form onSubmit={submitSearch} className="flex min-w-[220px] flex-1 items-center gap-1.5">
                        <div className="relative flex-1">
                            <Search className="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" />
                            <Input
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                placeholder="Search by test code, name, or short name..."
                                className="pl-8 text-xs sm:text-sm"
                            />
                        </div>
                        <Button type="submit" variant="secondary" size="sm">
                            Search
                        </Button>
                    </form>

                    <Select
                        value={filters.category_id || 'all'}
                        onValueChange={(value) => applyFilters({ category_id: value === 'all' ? '' : value })}
                    >
                        <SelectTrigger className="w-[160px] text-xs">
                            <SelectValue placeholder="All categories" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All Categories</SelectItem>
                            {categories.map((category) => (
                                <SelectItem key={category.id} value={String(category.id)}>
                                    {category.name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>

                    <Select
                        value={filters.specimen_type || 'all'}
                        onValueChange={(value) => applyFilters({ specimen_type: value === 'all' ? '' : value })}
                    >
                        <SelectTrigger className="w-[160px] text-xs">
                            <SelectValue placeholder="All specimens" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All Specimens</SelectItem>
                            {specimenTypes.map((type) => (
                                <SelectItem key={type} value={type}>
                                    {laboratorySpecimenTypeLabel[type] ?? type}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>

                    <Select
                        value={filters.has_parameters || 'all'}
                        onValueChange={(value) => applyFilters({ has_parameters: value === 'all' ? '' : value })}
                    >
                        <SelectTrigger className="w-[170px] text-xs">
                            <SelectValue placeholder="Parameter status" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All Parameters</SelectItem>
                            <SelectItem value="with">With Parameters</SelectItem>
                            <SelectItem value="without">Without Parameters</SelectItem>
                        </SelectContent>
                    </Select>

                    <Select
                        value={filters.scheme || 'all'}
                        onValueChange={(value) => applyFilters({ scheme: value === 'all' ? '' : value })}
                    >
                        <SelectTrigger className="w-[150px] text-xs">
                            <SelectValue placeholder="All schemes" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All Schemes</SelectItem>
                            <SelectItem value="normal">Standard / Cash</SelectItem>
                            <SelectItem value="nhif">NHIF Covered</SelectItem>
                        </SelectContent>
                    </Select>

                    <Select
                        value={filters.status || 'all'}
                        onValueChange={(value) => applyFilters({ status: value === 'all' ? '' : value })}
                    >
                        <SelectTrigger className="w-[130px] text-xs">
                            <SelectValue placeholder="All statuses" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All Statuses</SelectItem>
                            <SelectItem value="active">Active</SelectItem>
                            <SelectItem value="inactive">Inactive</SelectItem>
                        </SelectContent>
                    </Select>

                    {hasFilters && (
                        <Button variant="ghost" size="sm" onClick={clearAllFilters} className="text-xs text-muted-foreground">
                            <X className="mr-1 h-3.5 w-3.5" />
                            Clear
                        </Button>
                    )}
                </div>

                {/* Table of Tests and Parameters */}
                <div className="overflow-hidden rounded-lg border bg-card">
                    <Table>
                        <TableHeader>
                            <TableRow className="bg-muted/50">
                                <TableHead className="w-[40px] px-2 text-center"></TableHead>
                                <TableHead className="w-[110px]">Test Code</TableHead>
                                <TableHead className="min-w-[200px]">Test Name & Sample Specimen</TableHead>
                                <TableHead>Category</TableHead>
                                <TableHead>Specimen Container</TableHead>
                                <TableHead>Scheme</TableHead>
                                <TableHead className="text-center">Parameters</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead className="text-right">Actions</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {tests.data.map((test) => {
                                const isExpanded = expandedTests.has(test.id);
                                const params = test.parameters ?? [];
                                const paramCount = test.parameters_count ?? params.length;

                                return (
                                    <>
                                        <TableRow
                                            key={test.id}
                                            className={`transition-colors hover:bg-muted/40 ${isExpanded ? 'bg-muted/20 border-b-0' : ''}`}
                                        >
                                            {/* Expand/Collapse Toggle */}
                                            <TableCell className="px-2 text-center">
                                                <button
                                                    type="button"
                                                    onClick={() => toggleExpand(test.id)}
                                                    className="inline-flex h-7 w-7 items-center justify-center rounded-md text-muted-foreground hover:bg-muted hover:text-foreground"
                                                    title={isExpanded ? 'Collapse parameters' : 'Expand parameters'}
                                                >
                                                    {isExpanded ? (
                                                        <ChevronDown className="h-4 w-4" />
                                                    ) : (
                                                        <ChevronRight className="h-4 w-4" />
                                                    )}
                                                </button>
                                            </TableCell>

                                            {/* Code */}
                                            <TableCell>
                                                <Link
                                                    href={route('laboratory-tests.show', test.id)}
                                                    className="font-mono text-xs font-semibold text-primary hover:underline"
                                                >
                                                    {test.code}
                                                </Link>
                                            </TableCell>

                                            {/* Test Name & Specimen */}
                                            <TableCell>
                                                <div className="flex flex-col">
                                                    <span className="font-medium text-foreground">{test.name}</span>
                                                    <div className="flex items-center gap-1.5 pt-0.5 text-xs text-muted-foreground">
                                                        {test.specimen_type ? (
                                                            <span className="font-medium text-foreground/80">
                                                                Sample: {laboratorySpecimenTypeLabel[test.specimen_type] ?? test.specimen_type}
                                                            </span>
                                                        ) : (
                                                            <span className="italic text-amber-600">Sample not set</span>
                                                        )}
                                                        {test.short_name && <span>• ({test.short_name})</span>}
                                                    </div>
                                                </div>
                                            </TableCell>

                                            {/* Category */}
                                            <TableCell className="text-xs">
                                                {test.category?.name ? (
                                                    <Badge variant="outline" className="font-normal text-muted-foreground">
                                                        {test.category.name}
                                                    </Badge>
                                                ) : (
                                                    '—'
                                                )}
                                            </TableCell>

                                            {/* Container */}
                                            <TableCell className="text-xs text-muted-foreground">
                                                {test.specimen_container
                                                    ? laboratorySpecimenContainerLabel[test.specimen_container] ?? test.specimen_container
                                                    : '—'}
                                            </TableCell>

                                            {/* Scheme / NHIF */}
                                            <TableCell>
                                                {test.nhif_code ? (
                                                    <Badge variant="secondary" className="bg-cyan-500/10 text-cyan-700 dark:text-cyan-400 font-mono text-[11px]">
                                                        NHIF: {test.nhif_code}
                                                    </Badge>
                                                ) : (
                                                    <span className="text-xs text-muted-foreground">Standard / Cash</span>
                                                )}
                                            </TableCell>

                                            {/* Parameters Count Badge */}
                                            <TableCell className="text-center">
                                                <button
                                                    type="button"
                                                    onClick={() => toggleExpand(test.id)}
                                                    className="inline-flex items-center gap-1.5 focus:outline-hidden"
                                                >
                                                    {paramCount > 0 ? (
                                                        <Badge
                                                            variant="outline"
                                                            className={`cursor-pointer gap-1 transition-colors hover:border-primary/50 ${isExpanded ? 'border-primary bg-primary/10 text-primary' : 'bg-background'}`}
                                                        >
                                                            <SlidersHorizontal className="h-3 w-3 text-primary" />
                                                            <span>{paramCount} parameter{paramCount !== 1 ? 's' : ''}</span>
                                                        </Badge>
                                                    ) : (
                                                        <Badge variant="destructive" className="gap-1 cursor-pointer">
                                                            <AlertCircle className="h-3 w-3" />
                                                            <span>0 parameters</span>
                                                        </Badge>
                                                    )}
                                                </button>
                                            </TableCell>

                                            {/* Status */}
                                            <TableCell>
                                                <Badge variant={laboratoryTestStatusVariant[test.status]}>
                                                    {test.status === 'active' ? 'Active' : 'Inactive'}
                                                </Badge>
                                            </TableCell>

                                            {/* Actions */}
                                            <TableCell className="text-right">
                                                <div className="flex items-center justify-end gap-1.5">
                                                    {canManageParams && (
                                                        <Button
                                                            size="sm"
                                                            variant="outline"
                                                            className="h-8 gap-1 text-xs"
                                                            onClick={() => openAddParameter(test)}
                                                            title="Add parameter to this test"
                                                        >
                                                            <Plus className="h-3.5 w-3.5" />
                                                            <span className="hidden sm:inline">Add Parameter</span>
                                                        </Button>
                                                    )}

                                                    <Can permission="laboratory-tests.update">
                                                        <Button asChild size="sm" variant="ghost" className="h-8 w-8 p-0" title="Edit Test Details">
                                                            <Link href={route('laboratory-tests.edit', test.id)}>
                                                                <Pencil className="h-3.5 w-3.5" />
                                                            </Link>
                                                        </Button>
                                                    </Can>
                                                </div>
                                            </TableCell>
                                        </TableRow>

                                        {/* Expanded Parameters Sub-Table */}
                                        {isExpanded && (
                                            <TableRow className="border-t-0 bg-muted/20 hover:bg-muted/20">
                                                <TableCell colSpan={9} className="p-0">
                                                    <div className="border-t border-b bg-card/60 px-6 py-4 shadow-inner">
                                                        <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                                                            <div>
                                                                <h4 className="text-sm font-semibold flex items-center gap-2">
                                                                    <span>Configured Parameters for {test.name}</span>
                                                                    <Badge variant="outline" className="font-mono text-[11px]">
                                                                        {test.code}
                                                                    </Badge>
                                                                </h4>
                                                                <p className="text-xs text-muted-foreground">
                                                                    Sample: {test.specimen_type ? laboratorySpecimenTypeLabel[test.specimen_type] : 'Unspecified'} • Container: {test.specimen_container ? laboratorySpecimenContainerLabel[test.specimen_container] : 'Unspecified'}
                                                                </p>
                                                            </div>

                                                            {canManageParams && (
                                                                <Button
                                                                    size="sm"
                                                                    variant="default"
                                                                    className="h-7 text-xs"
                                                                    onClick={() => openAddParameter(test)}
                                                                >
                                                                    <Plus className="mr-1 h-3.5 w-3.5" />
                                                                    Add Parameter
                                                                </Button>
                                                            )}
                                                        </div>

                                                        {params.length > 0 ? (
                                                            <div className="overflow-hidden rounded-md border bg-background">
                                                                <Table>
                                                                    <TableHeader>
                                                                        <TableRow className="bg-muted/40 text-[11px]">
                                                                            <TableHead className="w-[50px] text-center">#</TableHead>
                                                                            <TableHead className="w-[120px]">Param Code</TableHead>
                                                                            <TableHead>Analyte / Parameter Name</TableHead>
                                                                            <TableHead className="w-[100px]">Data Type</TableHead>
                                                                            <TableHead className="w-[90px]">Unit</TableHead>
                                                                            <TableHead>Options / Normal Format</TableHead>
                                                                            <TableHead className="w-[80px]">Status</TableHead>
                                                                            {canManageParams && (
                                                                                <TableHead className="w-[90px] text-right">Actions</TableHead>
                                                                            )}
                                                                        </TableRow>
                                                                    </TableHeader>
                                                                    <TableBody>
                                                                        {params.map((param, idx) => (
                                                                            <TableRow key={param.id} className="text-xs">
                                                                                <TableCell className="text-center font-mono text-[11px] text-muted-foreground">
                                                                                    {param.display_order ?? idx + 1}
                                                                                </TableCell>
                                                                                <TableCell className="font-mono font-semibold text-foreground">
                                                                                    {param.code}
                                                                                </TableCell>
                                                                                <TableCell className="font-medium text-foreground">
                                                                                    {param.name}
                                                                                </TableCell>
                                                                                <TableCell>
                                                                                    <Badge variant="outline" className="text-[10px] uppercase">
                                                                                        {param.data_type}
                                                                                    </Badge>
                                                                                </TableCell>
                                                                                <TableCell className="font-mono text-muted-foreground">
                                                                                    {param.unit || '—'}
                                                                                </TableCell>
                                                                                <TableCell className="text-muted-foreground">
                                                                                    {param.options_json && param.options_json.length > 0 ? (
                                                                                        <span className="line-clamp-1 text-[11px]" title={param.options_json.join(', ')}>
                                                                                            {param.options_json.join(', ')}
                                                                                        </span>
                                                                                    ) : param.data_type === 'numeric' ? (
                                                                                        <span className="text-[11px] text-muted-foreground">
                                                                                            Numeric ({param.decimal_places ?? 1} dec)
                                                                                        </span>
                                                                                    ) : (
                                                                                        <span className="text-[11px] text-muted-foreground">Free narrative text</span>
                                                                                    )}
                                                                                </TableCell>
                                                                                <TableCell>
                                                                                    {param.active ? (
                                                                                        <span className="inline-flex items-center text-[11px] text-emerald-600 font-medium">
                                                                                            Active
                                                                                        </span>
                                                                                    ) : (
                                                                                        <span className="inline-flex items-center text-[11px] text-muted-foreground">
                                                                                            Inactive
                                                                                        </span>
                                                                                    )}
                                                                                </TableCell>
                                                                                {canManageParams && (
                                                                                    <TableCell className="text-right">
                                                                                        <div className="flex items-center justify-end gap-1">
                                                                                            <Button
                                                                                                size="sm"
                                                                                                variant="ghost"
                                                                                                className="h-7 w-7 p-0 text-muted-foreground hover:text-foreground"
                                                                                                onClick={() => openEditParameter(test, param)}
                                                                                                title="Edit parameter"
                                                                                            >
                                                                                                <Pencil className="h-3 w-3" />
                                                                                            </Button>
                                                                                            <Button
                                                                                                size="sm"
                                                                                                variant="ghost"
                                                                                                className="h-7 w-7 p-0 text-destructive hover:bg-destructive/10"
                                                                                                onClick={() => handleDeleteParameter(test, param)}
                                                                                                title="Delete parameter"
                                                                                            >
                                                                                                <Trash2 className="h-3 w-3" />
                                                                                            </Button>
                                                                                        </div>
                                                                                    </TableCell>
                                                                                )}
                                                                            </TableRow>
                                                                        ))}
                                                                    </TableBody>
                                                                </Table>
                                                            </div>
                                                        ) : (
                                                            <div className="flex flex-col items-center justify-center rounded-md border border-dashed py-6 text-center">
                                                                <AlertCircle className="mb-2 h-7 w-7 text-amber-500/80" />
                                                                <p className="text-sm font-medium">No parameters defined for this test</p>
                                                                <p className="text-xs text-muted-foreground max-w-sm mb-3">
                                                                    Result entry for this test currently relies on narrative text because no structured parameter analytes are configured.
                                                                </p>
                                                                {canManageParams && (
                                                                    <Button size="sm" onClick={() => openAddParameter(test)}>
                                                                        <Plus className="mr-1.5 h-3.5 w-3.5" />
                                                                        Add First Parameter
                                                                    </Button>
                                                                )}
                                                            </div>
                                                        )}
                                                    </div>
                                                </TableCell>
                                            </TableRow>
                                        )}
                                    </>
                                );
                            })}

                            {tests.data.length === 0 && (
                                <TableRow>
                                    <TableCell colSpan={9} className="p-0">
                                        <EmptyState
                                            icon={FlaskConical}
                                            title={hasFilters ? 'No tests match your filter criteria' : 'No laboratory tests configured'}
                                            description={
                                                hasFilters
                                                    ? 'Try adjusting your search keywords or resetting filters.'
                                                    : 'Add the hospital’s first laboratory test to start configuring parameters.'
                                            }
                                            action={
                                                hasFilters ? (
                                                    <Button variant="outline" size="sm" onClick={clearAllFilters}>
                                                        Reset Filters
                                                    </Button>
                                                ) : can('laboratory-tests.create') ? (
                                                    <Button asChild size="sm">
                                                        <Link href={route('laboratory-tests.create')}>
                                                            <Plus className="mr-1 h-4 w-4" />
                                                            Add Test
                                                        </Link>
                                                    </Button>
                                                ) : undefined
                                            }
                                        />
                                    </TableCell>
                                </TableRow>
                            )}
                        </TableBody>
                    </Table>
                </div>

                <Pagination links={tests.links} />
            </div>

            {/* Unified Parameter Dialog */}
            <LaboratoryParameterDialog
                open={dialogOpen}
                onOpenChange={setDialogOpen}
                test={dialogTest}
                parameter={editingParameter}
                onSuccess={() => {
                    // Automatically keep the test row expanded when a parameter is saved
                    if (dialogTest) {
                        setExpandedTests((prev) => new Set(prev).add(dialogTest.id));
                    }
                }}
            />
        </AppLayout>
    );
}
