import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarInput,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { usePermissions } from '@/hooks/use-permissions';
import { type NavGroup, type NavItem } from '@/types';
import { Link } from '@inertiajs/react';
import {
    Activity,
    BarChart3,
    BedDouble,
    Building2,
    CalendarClock,
    ClipboardCheck,
    ClipboardList,
    FileText,
    FlaskConical,
    Layers,
    LayoutGrid,
    LineChart,
    Network,
    Pill,
    Receipt,
    RefreshCw,
    Scan,
    ScrollText,
    Search,
    Settings,
    ShieldCheck,
    ShoppingCart,
    Stethoscope,
    Users,
    UsersRound,
    Warehouse,
    X,
} from 'lucide-react';
import * as React from 'react';
import AppLogo from './app-logo';

export function AppSidebar() {
    const { can } = usePermissions();
    const [searchQuery, setSearchQuery] = React.useState('');

    const navGroups: NavGroup[] = React.useMemo(() => {
        // 1. WORKSPACE
        const workspaceItems: NavItem[] = [
            ...(can('dashboard.view')
                ? [
                    {
                        title: 'Dashboard',
                        url: '/dashboard',
                        icon: LayoutGrid,
                    },
                ]
                : []),
        ];

        // 2. PATIENT CARE
        const patientCareItems: NavItem[] = [
            ...(can('patients.view')
                ? [{ title: 'Patients', url: '/patients', icon: UsersRound }]
                : []),
            ...(can('appointments.view')
                ? [{ title: 'Appointments', url: '/appointments', icon: CalendarClock }]
                : []),
            ...(can('reception.dashboard') || can('reception.view')
                ? [{ title: 'Reception', url: '/reception', icon: ClipboardList }]
                : []),
            ...(can('clinical-encounters.view')
                ? [{ title: 'Clinical / OPD', url: '/clinical-encounters', icon: Stethoscope }]
                : []),
            ...(can('laboratory.view')
                ? [
                    {
                        title: 'Laboratory',
                        url: '/laboratory',
                        icon: FlaskConical,
                        items: [
                            { title: 'Orders & Worklist', url: '/laboratory' },
                            ...(can('laboratory-samples.view')
                                ? [{ title: 'Sample Collection', url: '/laboratory/collection' }]
                                : []),
                            ...(can('laboratory-processing.view')
                                ? [{ title: 'Bench Processing', url: '/laboratory/processing' }]
                                : []),
                            ...(can('laboratory-results.view')
                                ? [{ title: 'Results Entry & Release', url: '/laboratory/results' }]
                                : []),
                            ...(can('laboratory-tests.view')
                                ? [{ title: 'Test Catalog', url: '/laboratory/tests' }]
                                : []),
                        ],
                    },
                ]
                : []),
            ...(can('radiology.view')
                ? [
                    {
                        title: 'Radiology',
                        url: '/radiology',
                        icon: Scan,
                        items: [
                            { title: 'Orders Worklist', url: '/radiology' },
                            ...(can('radiology-scheduling.view')
                                ? [{ title: 'Scheduling Worklist', url: '/radiology/worklist' }]
                                : []),
                            ...(can('radiology-reception.view')
                                ? [{ title: 'Reception & Prep', url: '/radiology/reception' }]
                                : []),
                            ...(can('radiology-examination.view')
                                ? [{ title: 'Examinations', url: '/radiology/examinations' }]
                                : []),
                            ...(can('radiology-results.view')
                                ? [{ title: 'Results & Reports', url: '/radiology/results' }]
                                : []),
                            ...(can('radiology-procedures.view')
                                ? [{ title: 'Procedure Catalog', url: '/radiology/catalog' }]
                                : []),
                        ],
                    },
                ]
                : []),
            ...(can('pharmacy.view')
                ? [
                    {
                        title: 'Pharmacy',
                        url: '/pharmacy',
                        icon: Pill,
                        items: [
                            ...(can('pharmacy-prescriptions.view') || can('pharmacy.view')
                                ? [{ title: 'Prescriptions', url: '/pharmacy/prescriptions' }]
                                : []),
                            ...(can('pharmacy-verification.view')
                                ? [{ title: 'Prescription Verification', url: '/pharmacy/prescriptions/verification' }]
                                : []),
                            ...(can('pharmacy-dispensing.view')
                                ? [{ title: 'Dispensing', url: '/pharmacy/dispensing' }]
                                : []),
                            ...(can('pharmacy-stock.view')
                                ? [{ title: 'Stock & Batches', url: '/pharmacy/stock' }]
                                : []),
                            ...(can('medications.view')
                                ? [{ title: 'Medication Catalog', url: '/pharmacy/medications' }]
                                : []),
                        ],
                    },
                ]
                : []),
            ...(can('admission.view')
                ? [{ title: 'Admissions', url: '/admissions', icon: BedDouble }]
                : []),
            ...(can('admission.view') || can('inpatient.medications.view') || can('inpatient-nursing.view')
                ? [
                    {
                        title: 'Inpatient Ward',
                        url: '/inpatient',
                        icon: Activity,
                        items: [
                            { title: 'Ward Overview', url: '/inpatient' },
                            ...(can('inpatient-nursing.view')
                                ? [{ title: 'Nursing Station', url: '/inpatient/nursing' }]
                                : []),
                        ],
                    },
                ]
                : []),
        ];

        // 3. FINANCE & INSURANCE
        const financeItems: NavItem[] = [
            ...(can('billing.view')
                ? [
                    {
                        title: 'Billing & Payments',
                        url: '/billing',
                        icon: Receipt,
                        items: [
                            { title: 'Overview', url: '/billing' },
                            ...(can('billing-accounts.view')
                                ? [{ title: 'Patient Accounts', url: '/billing/accounts' }]
                                : []),
                            ...(can('billing-invoices.view')
                                ? [{ title: 'Invoices', url: '/billing/invoices' }]
                                : []),
                            ...(can('billing-payments.view')
                                ? [{ title: 'Payments', url: '/billing/payments' }]
                                : []),
                            ...(can('billing-cashier.view')
                                ? [{ title: 'Cashier Sessions', url: '/billing/cashier' }]
                                : []),
                            ...(can('billing-adjustments.view')
                                ? [{ title: 'Adjustments', url: '/billing/adjustments' }]
                                : []),
                        ],
                    },
                ]
                : []),
            ...(can('insurance-providers.view') || can('insurance-schemes.view') || can('patient-insurance.view')
                ? [
                    {
                        title: 'Insurance',
                        url: '/insurance/providers',
                        icon: ShieldCheck,
                        items: [
                            ...(can('insurance-providers.view')
                                ? [{ title: 'Insurance Providers', url: '/insurance/providers' }]
                                : []),
                            ...(can('insurance-schemes.view')
                                ? [{ title: 'Insurance Schemes', url: '/insurance/schemes' }]
                                : []),
                            ...(can('patient-insurance.view')
                                ? [{ title: 'Patient Insurance', url: '/insurance/patient-insurance' }]
                                : []),
                        ],
                    },
                ]
                : []),
            ...(can('nhif-claims.view') ||
                can('nhif-authorization-context.lookup') ||
                can('nhif-claims.monthly-view') ||
                can('nhif-claims.view-reconciliation') ||
                can('nhif-utilization.view')
                ? [
                    {
                        title: 'NHIF Management',
                        url: '/nhif/claims',
                        icon: FileText,
                        items: [
                            ...(can('nhif-authorization-context.lookup')
                                ? [{ title: 'NHIF Verification & Lookup', url: '/nhif/authorization-context' }]
                                : []),
                            ...(can('admission.view')
                                ? [{ title: 'NHIF Admissions', url: '/nhif/admissions' }]
                                : []),
                            ...(can('nhif-claims.view')
                                ? [{ title: 'Individual Claims', url: '/nhif/claims' }]
                                : []),
                            ...(can('nhif-claims.monthly-view')
                                ? [{ title: 'Monthly Claims', url: '/nhif/claims/monthly' }]
                                : []),
                            ...(can('nhif-claims.view-reconciliation')
                                ? [{ title: 'Claim Reconciliation', url: '/nhif/reconciliation' }]
                                : []),
                            ...(can('nhif-utilization.view')
                                ? [{ title: 'Service Utilization', url: '/nhif/utilizations' }]
                                : []),
                        ],
                    },
                ]
                : []),
        ];

        // 4. PROCUREMENT & STORES
        const procurementItems: NavItem[] = [
            ...(can('procurement.view')
                ? [
                    {
                        title: 'Procurement',
                        url: '/procurement',
                        icon: ShoppingCart,
                        items: [
                            { title: 'Dashboard', url: '/procurement' },
                            ...(can('procurement-indents.view')
                                ? [{ title: 'Hospital Indents', url: '/procurement/indents' }]
                                : []),
                            ...(can('procurement-quotations.view')
                                ? [{ title: 'Vendors & Quotations', url: '/procurement/quotations' }]
                                : []),
                            ...(can('procurement-quotations.compare')
                                ? [{ title: 'Quote Comparison', url: '/procurement/quotations/compare' }]
                                : []),
                            ...(can('procurement-purchase-orders.view')
                                ? [{ title: 'Purchase Orders', url: '/procurement/orders' }]
                                : []),
                            ...(can('procurement-invoices.view')
                                ? [{ title: 'Vendor Invoices (3-Way)', url: '/procurement/invoices' }]
                                : []),
                            ...(can('procurement-vendors.view')
                                ? [{ title: 'Vendors Directory', url: '/procurement/vendors' }]
                                : []),
                            ...(can('procurement-items.view')
                                ? [{ title: 'Catalog Items', url: '/procurement/items' }]
                                : []),
                        ],
                    },
                ]
                : []),
            ...(can('stores.view')
                ? [
                    {
                        title: 'Stores & Inventory',
                        url: '/stores',
                        icon: Warehouse,
                        items: [
                            { title: 'Store Dashboard', url: '/stores' },
                            ...(can('stores-grn.view')
                                ? [{ title: 'Goods Receipt (GRN)', url: '/stores/grn' }]
                                : []),
                            ...(can('stores-requests.view')
                                ? [{ title: 'Department Requests', url: '/stores/requests' }]
                                : []),
                            ...(can('stores-issues.view')
                                ? [{ title: 'Department Issues', url: '/stores/issues' }]
                                : []),
                            ...(can('stores-transfers.view')
                                ? [{ title: 'Stock Transfers', url: '/stores/transfers' }]
                                : []),
                            ...(can('stores-inventory.view')
                                ? [{ title: 'Stock & Inventory', url: '/stores/inventory' }]
                                : []),
                            ...(can('stores-batches.view')
                                ? [{ title: 'Batches & Expiry', url: '/stores/batches' }]
                                : []),
                        ],
                    },
                ]
                : []),
        ];

        // 5. REPORTS & ANALYTICS
        const reportItems: NavItem[] = [
            ...(can('billing-reports.view')
                ? [{ title: 'Billing & Revenue', url: '/billing/reports', icon: Receipt }]
                : []),
            ...(can('pharmacy-reports.view')
                ? [{ title: 'Pharmacy Reports', url: '/pharmacy/reports', icon: Pill }]
                : []),
            ...(can('stores-reports.view')
                ? [{ title: 'Procurement & Stores', url: '/stores/reports', icon: Warehouse }]
                : []),
            ...(can('nhif-dashboard.view')
                ? [{ title: 'NHIF Analytics', url: '/nhif/dashboard', icon: LineChart }]
                : []),
            ...(can('audit-logs.view')
                ? [{ title: 'Audit Trail & Logs', url: '/audit-logs', icon: ScrollText }]
                : []),
        ];

        // 6. ADMINISTRATION
        const adminItems: NavItem[] = [
            ...(can('staff.view')
                ? [{ title: 'User Management', url: '/staff', icon: Users }]
                : []),
            ...(can('hospitals.view')
                ? [{ title: 'Hospitals / Facilities', url: '/hospitals', icon: Building2 }]
                : []),
            ...(can('departments.view')
                ? [{ title: 'Departments', url: '/departments', icon: Network }]
                : []),
            ...(can('practitioners.view')
                ? [{ title: 'Practitioners', url: '/practitioners', icon: Stethoscope }]
                : []),
            ...(can('billing-services.view')
                ? [{ title: 'Service Catalog', url: '/billing/services', icon: ScrollText }]
                : []),
            ...(can('billing-pricing.view')
                ? [{ title: 'Service Pricing', url: '/billing/pricing', icon: Receipt }]
                : []),
            ...(can('billing-insurance-rules.view')
                ? [{ title: 'Insurance Rules', url: '/billing/insurance-rules', icon: ShieldCheck }]
                : []),
            ...(can('nhif-service-mappings.view')
                ? [{ title: 'NHIF Service Mapping', url: '/nhif/service-mappings', icon: Network }]
                : []),
            ...(can('nhif-price-sync.view')
                ? [{ title: 'NHIF Price Sync', url: '/nhif/price-sync', icon: RefreshCw }]
                : []),
            ...(can('nhif-package-prices.view')
                ? [{ title: 'NHIF Package Prices', url: '/nhif/package-prices', icon: Layers }]
                : []),
            { title: 'System Settings', url: '/settings/profile', icon: Settings },
        ];

        const allGroups: NavGroup[] = [
            { title: 'Workspace', items: workspaceItems },
            { title: 'Patient Care', items: patientCareItems },
            { title: 'Finance & Insurance', items: financeItems },
            { title: 'Procurement & Stores', items: procurementItems },
            { title: 'Reports & Analytics', items: reportItems },
            { title: 'Administration', items: adminItems },
        ];

        // Filter out groups with 0 accessible items
        return allGroups.filter((g) => g.items.length > 0);
    }, [can]);

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href="/dashboard" prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <div className="px-2 pt-2 pb-1 group-data-[collapsible=icon]:hidden">
                    <div className="relative">
                        <Search className="absolute left-2.5 top-2.5 size-3.5 text-muted-foreground pointer-events-none" />
                        <SidebarInput
                            placeholder="Search navigation..."
                            value={searchQuery}
                            onChange={(e) => setSearchQuery(e.target.value)}
                            className="h-8 pl-8 pr-7 text-xs bg-sidebar-accent/40 border-sidebar-border focus-visible:ring-1 focus-visible:ring-sidebar-ring"
                        />
                        {searchQuery && (
                            <button
                                type="button"
                                onClick={() => setSearchQuery('')}
                                className="absolute right-2 top-2 size-4 flex items-center justify-center text-muted-foreground hover:text-foreground transition-colors"
                                title="Clear search"
                                aria-label="Clear search"
                            >
                                <X className="size-3" />
                            </button>
                        )}
                    </div>
                </div>

                <NavMain groups={navGroups} searchQuery={searchQuery} />
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
