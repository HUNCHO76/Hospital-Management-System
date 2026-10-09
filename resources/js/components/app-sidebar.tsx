import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { usePermissions } from '@/hooks/use-permissions';
import { type NavItem } from '@/types';
import { Link } from '@inertiajs/react';
import {
    Activity,
    BedDouble,
    Building2,
    CalendarClock,
    ClipboardCheck,
    ClipboardList,
    FileText,
    FlaskConical,
    LayoutGrid,
    LineChart,
    Network,
    Pill,
    Receipt,
    Scan,
    ScrollText,
    ShieldCheck,
    Stethoscope,
    Users,
    UsersRound,
} from 'lucide-react';
import AppLogo from './app-logo';

export function AppSidebar() {
    const { can } = usePermissions();

    const mainNavItems: NavItem[] = [
        ...(can('dashboard.view')
            ? [
                  {
                      title: 'Dashboard',
                      url: '/dashboard',
                      icon: LayoutGrid,
                  },
              ]
            : []),
        ...(can('hospitals.view') ? [{ title: 'Hospitals', url: '/hospitals', icon: Building2 }] : []),
        ...(can('departments.view') ? [{ title: 'Departments', url: '/departments', icon: Network }] : []),
        ...(can('staff.view') ? [{ title: 'Staff', url: '/staff', icon: Users }] : []),
        ...(can('practitioners.view') ? [{ title: 'Practitioners', url: '/practitioners', icon: Stethoscope }] : []),
        ...(can('patients.view') ? [{ title: 'Patients', url: '/patients', icon: UsersRound }] : []),
        ...(can('appointments.view') ? [{ title: 'Appointments', url: '/appointments', icon: CalendarClock }] : []),
        ...(can('reception.dashboard') ? [{ title: 'Reception', url: '/reception', icon: ClipboardList }] : []),
        ...(can('clinical-encounters.view') ? [{ title: 'Clinical Encounters', url: '/clinical-encounters', icon: Activity }] : []),
        ...(can('admission.view') ? [{ title: 'Admissions', url: '/admissions', icon: BedDouble }] : []),
        ...(can('admission.view') ? [{ title: 'Inpatient Ward', url: '/inpatient', icon: BedDouble }] : []),
        ...(can('laboratory.view') ? [{ title: 'Laboratory', url: '/laboratory', icon: FlaskConical }] : []),
        ...(can('radiology.view') ? [{ title: 'Radiology', url: '/radiology', icon: Scan }] : []),
        ...(can('pharmacy.view')
            ? [
                  {
                      title: 'Pharmacy',
                      url: '/pharmacy',
                      icon: Pill,
                      items: [
                          ...(can('pharmacy-reports.view') ? [{ title: 'Reports', url: '/pharmacy/reports' }] : []),
                      ],
                  },
              ]
            : []),
        ...(can('billing.view')
            ? [
                  {
                      title: 'Billing',
                      url: '/billing',
                      icon: Receipt,
                      items: [
                          ...(can('billing-services.view') ? [{ title: 'Services', url: '/billing/services' }] : []),
                          ...(can('billing-pricing.view') ? [{ title: 'Pricing', url: '/billing/pricing' }] : []),
                          ...(can('billing-reports.view') ? [{ title: 'Reports', url: '/billing/reports' }] : []),
                      ],
                  },
              ]
            : []),
        ...(can('insurance-providers.view') ? [{ title: 'Insurance Providers', url: '/insurance/providers', icon: ShieldCheck }] : []),
        ...(can('insurance-schemes.view') ? [{ title: 'Insurance Schemes', url: '/insurance/schemes', icon: ShieldCheck }] : []),
        ...(can('patient-insurance.view') ? [{ title: 'Patient Insurance', url: '/insurance/patient-insurance', icon: ShieldCheck }] : []),
        ...(can('nhif-dashboard.view') ? [{ title: 'NHIF Dashboard', url: '/nhif/dashboard', icon: LineChart }] : []),
        ...(can('admission.view') ? [{ title: 'NHIF Admissions', url: '/nhif/admissions', icon: BedDouble }] : []),
        ...(can('nhif-utilization.view') ? [{ title: 'NHIF Utilization', url: '/nhif/utilizations', icon: Activity }] : []),
        ...(can('nhif-claims.view')
            ? [
                  {
                      title: 'NHIF Claims',
                      url: '/nhif/claims',
                      icon: FileText,
                      items: [
                          { title: 'Individual Claims', url: '/nhif/claims' },
                          ...(can('nhif-claims.monthly-view') ? [{ title: 'Monthly Claims', url: '/nhif/claims/monthly' }] : []),
                      ],
                  },
              ]
            : []),
        ...(can('nhif-claims.view-reconciliation') ? [{ title: 'NHIF Reconciliation', url: '/nhif/reconciliation', icon: ClipboardCheck }] : []),
        ...(can('audit-logs.view') ? [{ title: 'Audit Logs', url: '/audit-logs', icon: ScrollText }] : []),
    ];

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
                <NavMain items={mainNavItems} />
            </SidebarContent>

            <SidebarFooter>
                {/*<NavFooter items={footerNavItems} className="mt-auto" />*/}
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
