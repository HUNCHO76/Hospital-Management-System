import AppearanceToggleDropdown from '@/components/appearance-dropdown';
import { BrandMark } from '@/components/brand-mark';
import { Button } from '@/components/ui/button';
import { brand } from '@/lib/brand';
import { type SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';
import {
    Activity,
    ArrowRight,
    Award,
    BadgeCheck,
    BedDouble,
    Building2,
    CalendarClock,
    CheckCircle2,
    ClipboardCheck,
    FileCheck2,
    FileSpreadsheet,
    FileText,
    FlaskConical,
    HeartPulse,
    HelpCircle,
    Layers,
    LayoutDashboard,
    Lock,
    LogIn,
    Pill,
    Receipt,
    Scan,
    Search,
    Shield,
    ShieldCheck,
    Sparkles,
    Stethoscope,
    Users,
    UsersRound,
    Warehouse,
    Zap,
} from 'lucide-react';
import { useMemo, useState } from 'react';

type ModuleCategory = 'all' | 'clinical' | 'diagnostics' | 'finance' | 'operations';

interface SystemModule {
    id: string;
    title: string;
    category: 'clinical' | 'diagnostics' | 'finance' | 'operations';
    badge: string;
    icon: typeof Activity;
    description: string;
    features: string[];
    color: string;
}

export default function Welcome() {
    const { auth } = usePage<SharedData>().props;
    const [selectedCategory, setSelectedCategory] = useState<ModuleCategory>('all');
    const [activeWorkflowStep, setActiveWorkflowStep] = useState<number>(0);

    const nhifSteps = [
        {
            step: '01',
            title: 'Biometric & Card Verification',
            description:
                'Instant member validation against NHIF national databases with real-time card status, active schemes, and dependent verification.',
            icon: ShieldCheck,
            badge: 'Real-time API',
        },
        {
            step: '02',
            title: 'Pre-Approval & Authorization',
            description:
                'Automated clinical pre-authorization checks for procedures, restricted tariffs, package pricing, and specialist consultations.',
            icon: ClipboardCheck,
            badge: 'Policy Engine',
        },
        {
            step: '03',
            title: 'Automated Charge & Tariff Match',
            description:
                'Clinical encounters, lab tests, radiology scans, and pharmacy items are auto-mapped to standard NHIF item codes and prices.',
            icon: FileCheck2,
            badge: 'ICD-10 & Tariffs',
        },
        {
            step: '04',
            title: 'Electronic Claim Reconciliation',
            description:
                'Batch claim generation, discrepancy detection, monthly submission packages, and automated remittance reconciliation.',
            icon: FileSpreadsheet,
            badge: 'Zero-Rejection Engine',
        },
    ];

    const modules: SystemModule[] = [
        {
            id: 'reception',
            title: 'Reception & Smart Triage',
            category: 'clinical',
            badge: 'Front Desk & Vitals',
            icon: UsersRound,
            description:
                'Unified patient registration, automated queue numbering, appointment scheduling, and digital vital sign capture with pediatric & adult triage thresholds.',
            features: [
                'Biometric & National ID / NHIF Lookup',
                'Vital Signs, BMI & Pain Scale Recording',
                'Specialty Clinic Queue Routing',
                'Emergency Triage Flagging',
            ],
            color: 'from-amber-500/10 to-orange-500/10 border-amber-500/20 text-amber-600 dark:text-amber-400',
        },
        {
            id: 'clinical',
            title: 'Clinical Encounters & EMR',
            category: 'clinical',
            badge: 'OPD & Consultations',
            icon: Stethoscope,
            description:
                'Comprehensive electronic medical record for physicians: structured SOAP notes, previous medical history, integrated ICD-10 diagnosis tagging, and referral pathways.',
            features: [
                'Structured SOAP Clinical Documentation',
                'Real-time ICD-10 Disease Code Search',
                'Instant Order Entry (Lab, Radiology, Rx)',
                'Practitioner Attendance & Biometric Log',
            ],
            color: 'from-blue-500/10 to-cyan-500/10 border-blue-500/20 text-blue-600 dark:text-blue-400',
        },
        {
            id: 'laboratory',
            title: 'Diagnostic Laboratory Hub',
            category: 'diagnostics',
            badge: 'Analyzer & Worklist',
            icon: FlaskConical,
            description:
                'End-to-end lab workflow management from barcode sample collection and multi-bench processing to automated reference range validation and digital result release.',
            features: [
                'Automated Sample Barcoding & Tracking',
                'Multi-Bench Worklist Management',
                'Age & Gender Reference Range Flags',
                'Consultant Review & Digital Release',
            ],
            color: 'from-purple-500/10 to-indigo-500/10 border-purple-500/20 text-purple-600 dark:text-purple-400',
        },
        {
            id: 'radiology',
            title: 'Radiology & Imaging Suite',
            category: 'diagnostics',
            badge: 'PACS & Scheduling',
            icon: Scan,
            description:
                'Integrated radiological examinations workflow with modality scheduling, patient prep tracking, image attachment, and structured radiologist reporting.',
            features: [
                'Modality Scheduling & Worklists (X-Ray, USG, CT)',
                'Reception & Patient Preparation Logs',
                'Diagnostic Impression Archiving',
                'NHIF Pre-Approval Verification Gate',
            ],
            color: 'from-teal-500/10 to-emerald-500/10 border-teal-500/20 text-teal-600 dark:text-teal-400',
        },
        {
            id: 'pharmacy',
            title: 'Pharmacy & Smart Dispensary',
            category: 'diagnostics',
            badge: 'Prescriptions & Batches',
            icon: Pill,
            description:
                'Digital prescription queue, pharmacist clinical verification, batch & lot expiry monitoring, automated FEFO dispensing, and inventory depletion.',
            features: [
                'Pharmacist Clinical Order Verification',
                'Batch Expiry & FEFO Inventory Control',
                'Split Co-Pay & Insurance Adjudication',
                'Automated Stock Depletion & Alerts',
            ],
            color: 'from-rose-500/10 to-pink-500/10 border-rose-500/20 text-rose-600 dark:text-rose-400',
        },
        {
            id: 'inpatient',
            title: 'Inpatient Ward & Bed Management',
            category: 'clinical',
            badge: 'Admissions & Care Plans',
            icon: BedDouble,
            description:
                'Complete inpatient lifecycle: admission review and approval, ward and bed occupancy tracking, nurse medication administration records (MAR), and discharge summaries.',
            features: [
                'Bed Occupancy & Ward Transfer Tracking',
                'Multi-Disciplinary Care Plans & Rounds',
                'Nurse Medication Administration (MAR)',
                'NHIF Inpatient Pre-Auth & Discharge Claims',
            ],
            color: 'from-indigo-500/10 to-sky-500/10 border-indigo-500/20 text-indigo-600 dark:text-indigo-400',
        },
        {
            id: 'billing',
            title: 'Billing, Cashier & Insurance',
            category: 'finance',
            badge: 'Revenue & NHIF Adjudication',
            icon: Receipt,
            description:
                'Real-time point-of-care charge capture, automated patient copay splits, cashier shift opening/closing sessions, multi-tender payment processing, and audit reconciliation.',
            features: [
                'Automated Split Billing (Cash vs NHIF/Private)',
                'Cashier Shift Handover & Balancing',
                'NHIF Tariff Validation & Price Protection',
                'Comprehensive Financial Audit Trails',
            ],
            color: 'from-emerald-500/10 to-green-500/10 border-emerald-500/20 text-emerald-600 dark:text-emerald-400',
        },
        {
            id: 'procurement',
            title: 'Stores & Medical Procurement',
            category: 'operations',
            badge: 'Supply Chain & Inventory',
            icon: Warehouse,
            description:
                'Central medical stores and ward distribution: departmental stock requisitions, purchase indents, vendor quotation tracking, and goods receipt notes (GRN).',
            features: [
                'Departmental Requisitions & Transfers',
                'Purchase Orders & Vendor Quotations',
                'Goods Receipt Note (GRN) Verification',
                'Real-Time Low Stock & Expiry Forecasting',
            ],
            color: 'from-amber-600/10 to-yellow-500/10 border-amber-600/20 text-amber-700 dark:text-amber-300',
        },
    ];

    const filteredModules = useMemo(() => {
        if (selectedCategory === 'all') return modules;
        return modules.filter((m) => m.category === selectedCategory);
    }, [selectedCategory]);

    const stats = [
        { label: 'NHIF Electronic Claim Accuracy', value: '99.8%', detail: 'Automated tariff & rule verification' },
        { label: 'Integrated Hospital Modules', value: '10+', detail: 'From triage to central stores' },
        { label: 'Diagnostic Turnaround', value: 'Fast-Track', detail: 'Automated lab & radiology worklists' },
        { label: 'Clinical Readiness', value: '24/7', detail: 'Real-time ward & inpatient tracking' },
    ];

    return (
        <>
            <Head title="St. Joseph DMI Hospital Management System & NHIF Integrated Platform">
                <link rel="preconnect" href="https://fonts.bunny.net" />
                <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600,700" rel="stylesheet" />
            </Head>

            <div className="min-h-screen bg-neutral-50 text-neutral-900 selection:bg-amber-500 selection:text-white dark:bg-neutral-950 dark:text-neutral-100">
                {/* 1. TOP ANNOUNCEMENT BAR */}
                <div className="border-b border-amber-200/60 bg-gradient-to-r from-amber-500/10 via-orange-500/10 to-emerald-500/10 px-4 py-2 text-xs font-medium text-amber-900 dark:border-amber-900/40 dark:text-amber-200">
                    <div className="mx-auto flex max-w-7xl items-center justify-between">
                        <div className="flex items-center gap-2">
                            <span className="flex h-2 w-2 rounded-full bg-emerald-500 animate-pulse" />
                            <span>St. Joseph DMI Healthcare System • NHIF Smart Electronic Claims Engine v3.1</span>
                        </div>
                        <div className="hidden sm:flex items-center gap-4 text-neutral-600 dark:text-neutral-400">
                            <span>Tanzania NHIF Direct Integration</span>
                            <span>•</span>
                            <span>Spatie RBAC Secure</span>
                        </div>
                    </div>
                </div>

                {/* 2. STICKY NAVIGATION BAR */}
                <header className="sticky top-0 z-50 border-b border-neutral-200/80 bg-white/85 backdrop-blur-md dark:border-neutral-800/80 dark:bg-neutral-950/85">
                    <div className="mx-auto flex h-16 max-w-7xl items-center justify-between px-4 sm:px-6 lg:px-8">
                        {/* Hospital Logo & Brand Title */}
                        <div className="flex items-center gap-3">
                            <div className="flex h-11 w-11 items-center justify-center rounded-xl bg-amber-500/10 p-1.5 ring-1 ring-amber-500/20 dark:bg-amber-500/15">
                                <BrandMark className="h-full w-full object-contain" />
                            </div>
                            <div>
                                <div className="flex items-center gap-2">
                                    <span className="text-base font-bold tracking-tight text-neutral-900 dark:text-white">
                                        {brand.hospitalName}
                                    </span>
                                    <span className="hidden rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-semibold text-amber-800 sm:inline-block dark:bg-amber-950/60 dark:text-amber-300">
                                        HMS + NHIF
                                    </span>
                                </div>
                                <p className="text-xs text-neutral-500 dark:text-neutral-400">
                                    Hospital Management System
                                </p>
                            </div>
                        </div>

                        {/* Navigation Links */}
                        <nav className="hidden md:flex items-center gap-6 text-sm font-medium text-neutral-600 dark:text-neutral-300">
                            <a href="#overview" className="transition hover:text-amber-600 dark:hover:text-amber-400">
                                Overview
                            </a>
                            <a href="#nhif-integration" className="transition hover:text-amber-600 dark:hover:text-amber-400">
                                NHIF Automation
                            </a>
                            <a href="#modules" className="transition hover:text-amber-600 dark:hover:text-amber-400">
                                Clinical Modules
                            </a>
                            <a href="#workflow" className="transition hover:text-amber-600 dark:hover:text-amber-400">
                                Patient Journey
                            </a>
                            <a href="#security" className="transition hover:text-amber-600 dark:hover:text-amber-400">
                                Security & Compliance
                            </a>
                        </nav>

                        {/* Action Buttons & Theme Toggle */}
                        <div className="flex items-center gap-3">
                            <AppearanceToggleDropdown className="text-neutral-600 dark:text-neutral-400" />

                            {auth.user ? (
                                <Link
                                    href={route('dashboard')}
                                    className="inline-flex items-center gap-2 rounded-lg bg-neutral-900 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-neutral-800 dark:bg-white dark:text-neutral-900 dark:hover:bg-neutral-200"
                                >
                                    <LayoutDashboard className="h-4 w-4" />
                                    <span>Dashboard</span>
                                </Link>
                            ) : (
                                <Link
                                    href={route('login')}
                                    className="inline-flex items-center gap-2 rounded-lg bg-amber-600 px-4 py-2 text-sm font-semibold text-white shadow-sm shadow-amber-600/20 transition hover:bg-amber-500 active:scale-95"
                                >
                                    <LogIn className="h-4 w-4" />
                                    <span>Staff Login</span>
                                </Link>
                            )}
                        </div>
                    </div>
                </header>

                {/* 3. HERO SECTION */}
                <section id="overview" className="relative overflow-hidden pt-12 pb-20 lg:pt-20 lg:pb-28">
                    {/* Ambient Glows */}
                    <div className="pointer-events-none absolute -top-40 right-1/4 -z-10 h-96 w-96 rounded-full bg-amber-500/15 blur-3xl" />
                    <div className="pointer-events-none absolute top-48 -left-20 -z-10 h-96 w-96 rounded-full bg-emerald-500/10 blur-3xl" />

                    <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                        <div className="grid gap-12 lg:grid-cols-12 lg:items-center">
                            {/* Left Column: Headlines & CTAs */}
                            <div className="lg:col-span-7">
                                <div className="inline-flex items-center gap-2 rounded-full border border-amber-300/80 bg-amber-50 px-3.5 py-1 text-xs font-semibold text-amber-900 shadow-sm dark:border-amber-800/60 dark:bg-amber-950/40 dark:text-amber-300">
                                    <Sparkles className="h-3.5 w-3.5 text-amber-600 dark:text-amber-400" />
                                    <span>St. Joseph DMI Healthcare & Insurance Ecosystem</span>
                                </div>

                                <h1 className="mt-5 text-4xl font-extrabold tracking-tight text-neutral-900 sm:text-5xl lg:text-6xl dark:text-white">
                                    Intelligent Clinical Care & Seamless{' '}
                                    <span className="bg-gradient-to-r from-amber-600 via-orange-600 to-amber-700 bg-clip-text text-transparent dark:from-amber-400 dark:via-orange-400 dark:to-amber-500">
                                        NHIF Claims
                                    </span>{' '}
                                    Integration.
                                </h1>

                                <p className="mt-6 text-lg leading-relaxed text-neutral-600 dark:text-neutral-300">
                                    Powering <strong>St. Joseph DMI</strong> with a modern digital health infrastructure:
                                    from outpatient registration and digital triage to computerized laboratory orders,
                                    radiology examinations, pharmacy dispensing, and real-time electronic NHIF claims
                                    adjudication.
                                </p>

                                <div className="mt-8 flex flex-wrap items-center gap-4">
                                    {auth.user ? (
                                        <Link
                                            href={route('dashboard')}
                                            className="inline-flex items-center gap-2.5 rounded-xl bg-amber-600 px-6 py-3.5 text-base font-semibold text-white shadow-lg shadow-amber-600/25 transition hover:bg-amber-500 active:scale-95"
                                        >
                                            <span>Enter Hospital Workspace</span>
                                            <ArrowRight className="h-5 w-5" />
                                        </Link>
                                    ) : (
                                        <Link
                                            href={route('login')}
                                            className="inline-flex items-center gap-2.5 rounded-xl bg-amber-600 px-6 py-3.5 text-base font-semibold text-white shadow-lg shadow-amber-600/25 transition hover:bg-amber-500 active:scale-95"
                                        >
                                            <span>Staff Portal Access</span>
                                            <ArrowRight className="h-5 w-5" />
                                        </Link>
                                    )}

                                    <a
                                        href="#nhif-integration"
                                        className="inline-flex items-center gap-2 rounded-xl border border-neutral-300 bg-white px-5 py-3.5 text-base font-medium text-neutral-700 shadow-sm transition hover:bg-neutral-50 hover:text-neutral-900 dark:border-neutral-800 dark:bg-neutral-900 dark:text-neutral-300 dark:hover:bg-neutral-800 dark:hover:text-white"
                                    >
                                        <ShieldCheck className="h-5 w-5 text-emerald-600 dark:text-emerald-400" />
                                        <span>Explore NHIF Automation</span>
                                    </a>
                                </div>

                                {/* Certification & Highlights */}
                                <div className="mt-10 flex flex-wrap items-center gap-6 border-t border-neutral-200/80 pt-6 text-xs text-neutral-500 dark:border-neutral-800 dark:text-neutral-400">
                                    <div className="flex items-center gap-2">
                                        <CheckCircle2 className="h-4 w-4 text-emerald-600 dark:text-emerald-400" />
                                        <span>Tanzania NHIF Direct Verification</span>
                                    </div>
                                    <div className="flex items-center gap-2">
                                        <CheckCircle2 className="h-4 w-4 text-emerald-600 dark:text-emerald-400" />
                                        <span>ICD-10 Clinical Diagnostics</span>
                                    </div>
                                    <div className="flex items-center gap-2">
                                        <CheckCircle2 className="h-4 w-4 text-emerald-600 dark:text-emerald-400" />
                                        <span>Role-Based Audit Logging</span>
                                    </div>
                                </div>
                            </div>

                            {/* Right Column: Live Clinical System Mockup */}
                            <div className="lg:col-span-5">
                                <div className="relative rounded-2xl border border-neutral-200/80 bg-white p-5 shadow-2xl dark:border-neutral-800 dark:bg-neutral-900">
                                    {/* Mock Window Header */}
                                    <div className="flex items-center justify-between border-b border-neutral-100 pb-3 dark:border-neutral-800">
                                        <div className="flex items-center gap-2">
                                            <div className="h-3 w-3 rounded-full bg-rose-500/80" />
                                            <div className="h-3 w-3 rounded-full bg-amber-500/80" />
                                            <div className="h-3 w-3 rounded-full bg-emerald-500/80" />
                                            <span className="ml-2 text-xs font-semibold text-neutral-500 dark:text-neutral-400">
                                                Live Encounter & Claim Preview
                                            </span>
                                        </div>
                                        <span className="inline-flex items-center gap-1.5 rounded-full bg-emerald-100 px-2.5 py-0.5 text-[11px] font-semibold text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300">
                                            <span className="h-1.5 w-1.5 rounded-full bg-emerald-600 animate-pulse" />
                                            NHIF Verified
                                        </span>
                                    </div>

                                    {/* Mock Patient Card */}
                                    <div className="mt-4 rounded-xl bg-neutral-50 p-4 dark:bg-neutral-800/50">
                                        <div className="flex items-start justify-between">
                                            <div>
                                                <p className="text-xs text-neutral-500 dark:text-neutral-400">
                                                    Patient MRN: <strong className="text-neutral-800 dark:text-neutral-200">SJD-2026-08412</strong>
                                                </p>
                                                <h4 className="mt-1 text-base font-bold text-neutral-900 dark:text-white">
                                                    Juma Abdallah Mwenda
                                                </h4>
                                                <p className="text-xs text-neutral-500">
                                                    Male • 42 Yrs • NHIF Card: <strong>23-9081273-01</strong>
                                                </p>
                                            </div>
                                            <span className="rounded-md bg-amber-100 px-2 py-1 text-xs font-semibold text-amber-800 dark:bg-amber-950/80 dark:text-amber-300">
                                                OPD General
                                            </span>
                                        </div>

                                        {/* Vitals Ribbon */}
                                        <div className="mt-3 grid grid-cols-4 gap-2 rounded-lg bg-white p-2.5 text-center text-xs shadow-xs dark:bg-neutral-900">
                                            <div>
                                                <span className="text-[10px] text-neutral-400">BP</span>
                                                <p className="font-semibold text-neutral-800 dark:text-neutral-200">120/80</p>
                                            </div>
                                            <div>
                                                <span className="text-[10px] text-neutral-400">Pulse</span>
                                                <p className="font-semibold text-neutral-800 dark:text-neutral-200">74 bpm</p>
                                            </div>
                                            <div>
                                                <span className="text-[10px] text-neutral-400">Temp</span>
                                                <p className="font-semibold text-neutral-800 dark:text-neutral-200">36.7 °C</p>
                                            </div>
                                            <div>
                                                <span className="text-[10px] text-neutral-400">SpO2</span>
                                                <p className="font-semibold text-emerald-600 dark:text-emerald-400">99%</p>
                                            </div>
                                        </div>
                                    </div>

                                    {/* Workflow Live Stages */}
                                    <div className="mt-4 space-y-2.5 text-xs">
                                        <div className="flex items-center justify-between rounded-lg border border-neutral-200/70 p-2.5 dark:border-neutral-800">
                                            <div className="flex items-center gap-2.5">
                                                <div className="flex h-7 w-7 items-center justify-center rounded-md bg-blue-100 text-blue-700 dark:bg-blue-950 dark:text-blue-300">
                                                    <Stethoscope className="h-4 w-4" />
                                                </div>
                                                <div>
                                                    <p className="font-medium text-neutral-800 dark:text-neutral-200">Consultation Encounter</p>
                                                    <p className="text-[11px] text-neutral-400">Dr. M. Kweka • ICD-10 J06.9</p>
                                                </div>
                                            </div>
                                            <span className="text-emerald-600 font-semibold dark:text-emerald-400">Completed</span>
                                        </div>

                                        <div className="flex items-center justify-between rounded-lg border border-neutral-200/70 p-2.5 dark:border-neutral-800">
                                            <div className="flex items-center gap-2.5">
                                                <div className="flex h-7 w-7 items-center justify-center rounded-md bg-purple-100 text-purple-700 dark:bg-purple-950 dark:text-purple-300">
                                                    <FlaskConical className="h-4 w-4" />
                                                </div>
                                                <div>
                                                    <p className="font-medium text-neutral-800 dark:text-neutral-200">Laboratory (Full Blood Picture)</p>
                                                    <p className="text-[11px] text-neutral-400">Automated Bench • Results Released</p>
                                                </div>
                                            </div>
                                            <span className="text-emerald-600 font-semibold dark:text-emerald-400">Released</span>
                                        </div>

                                        <div className="flex items-center justify-between rounded-lg border border-neutral-200/70 p-2.5 dark:border-neutral-800">
                                            <div className="flex items-center gap-2.5">
                                                <div className="flex h-7 w-7 items-center justify-center rounded-md bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300">
                                                    <Receipt className="h-4 w-4" />
                                                </div>
                                                <div>
                                                    <p className="font-medium text-neutral-800 dark:text-neutral-200">NHIF Electronic Claim</p>
                                                    <p className="text-[11px] text-neutral-400">Claim #CLM-TZ-98402 • Tariff Auto-Match</p>
                                                </div>
                                            </div>
                                            <span className="text-emerald-600 font-semibold dark:text-emerald-400">Ready</span>
                                        </div>
                                    </div>

                                    {/* Action button inside mockup */}
                                    <div className="mt-4 pt-3 border-t border-neutral-100 dark:border-neutral-800 flex items-center justify-between">
                                        <span className="text-[11px] text-neutral-500">St. Joseph DMI Clinical Core</span>
                                        <Link
                                            href={auth.user ? route('dashboard') : route('login')}
                                            className="inline-flex items-center gap-1.5 text-xs font-semibold text-amber-600 hover:text-amber-500 dark:text-amber-400"
                                        >
                                            <span>Open Encounter</span>
                                            <ArrowRight className="h-3.5 w-3.5" />
                                        </Link>
                                    </div>
                                </div>
                            </div>
                        </div>

                        {/* STATS STRIP */}
                        <div className="mt-16 grid grid-cols-2 gap-4 rounded-2xl border border-neutral-200/80 bg-white/70 p-6 backdrop-blur-md sm:grid-cols-4 lg:gap-8 dark:border-neutral-800 dark:bg-neutral-900/70">
                            {stats.map((s, idx) => (
                                <div key={idx} className="space-y-1">
                                    <p className="text-3xl font-extrabold text-neutral-900 dark:text-white">{s.value}</p>
                                    <p className="text-xs font-semibold text-neutral-700 dark:text-neutral-300">{s.label}</p>
                                    <p className="text-[11px] text-neutral-500 dark:text-neutral-400">{s.detail}</p>
                                </div>
                            ))}
                        </div>
                    </div>
                </section>

                {/* 4. NHIF INTEGRATION SPOTLIGHT (The Core Differentiator) */}
                <section id="nhif-integration" className="relative border-y border-neutral-200/80 bg-white py-16 lg:py-24 dark:border-neutral-800 dark:bg-neutral-900/60">
                    <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                        <div className="max-w-2xl">
                            <div className="inline-flex items-center gap-2 rounded-full bg-emerald-100 px-3 py-1 text-xs font-semibold text-emerald-800 dark:bg-emerald-950/80 dark:text-emerald-300">
                                <ShieldCheck className="h-4 w-4" />
                                <span>National Health Insurance Fund (NHIF) Tanzanian Gateway</span>
                            </div>
                            <h2 className="mt-4 text-3xl font-extrabold tracking-tight text-neutral-900 sm:text-4xl dark:text-white">
                                Automated, Error-Free Electronic NHIF Claims
                            </h2>
                            <p className="mt-3 text-neutral-600 dark:text-neutral-300">
                                Manual claims processing causes delayed reimbursements, tariff mismatches, and claim rejections.
                                St. Joseph DMI HMS embeds NHIF rules directly into every clinical and dispensing decision.
                            </p>
                        </div>

                        {/* Interactive Workflow Steps */}
                        <div className="mt-12 grid gap-6 lg:grid-cols-4">
                            {nhifSteps.map((step, idx) => {
                                const IconComponent = step.icon;
                                const isActive = activeWorkflowStep === idx;
                                return (
                                    <div
                                        key={idx}
                                        onMouseEnter={() => setActiveWorkflowStep(idx)}
                                        className={`group relative cursor-pointer rounded-2xl border p-6 transition-all duration-200 ${
                                            isActive
                                                ? 'border-emerald-500 bg-emerald-50/40 shadow-md dark:border-emerald-500/60 dark:bg-emerald-950/20'
                                                : 'border-neutral-200/80 bg-white hover:border-neutral-300 dark:border-neutral-800 dark:bg-neutral-900'
                                        }`}
                                    >
                                        <div className="flex items-center justify-between">
                                            <span className="font-mono text-2xl font-bold text-neutral-300 dark:text-neutral-700">
                                                {step.step}
                                            </span>
                                            <span className="rounded-full bg-neutral-100 px-2 py-0.5 text-[10px] font-semibold text-neutral-600 dark:bg-neutral-800 dark:text-neutral-400">
                                                {step.badge}
                                            </span>
                                        </div>

                                        <div className="mt-4 flex h-12 w-12 items-center justify-center rounded-xl bg-emerald-500/10 text-emerald-600 ring-1 ring-emerald-500/20 dark:bg-emerald-500/20 dark:text-emerald-400">
                                            <IconComponent className="h-6 w-6" />
                                        </div>

                                        <h3 className="mt-4 text-base font-bold text-neutral-900 dark:text-white">
                                            {step.title}
                                        </h3>
                                        <p className="mt-2 text-xs leading-relaxed text-neutral-600 dark:text-neutral-400">
                                            {step.description}
                                        </p>
                                    </div>
                                );
                            })}
                        </div>

                        {/* Feature comparison banner */}
                        <div className="mt-12 rounded-2xl border border-emerald-500/20 bg-gradient-to-r from-emerald-500/10 via-teal-500/5 to-transparent p-6 lg:p-8 dark:border-emerald-500/30">
                            <div className="grid gap-6 lg:grid-cols-3">
                                <div>
                                    <h4 className="font-bold text-neutral-900 dark:text-white">Real-Time Tariff Validation</h4>
                                    <p className="mt-1 text-xs text-neutral-600 dark:text-neutral-400">
                                        Procedures, medications, and lab tests automatically sync against NHIF package price sheets to prevent under- or over-billing.
                                    </p>
                                </div>
                                <div>
                                    <h4 className="font-bold text-neutral-900 dark:text-white">ICD-10 Diagnostic Compliance</h4>
                                    <p className="mt-1 text-xs text-neutral-600 dark:text-neutral-400">
                                        Clinical notes require validated ICD-10 diagnostic coding mapped to allowable NHIF interventions before claim submission.
                                    </p>
                                </div>
                                <div>
                                    <h4 className="font-bold text-neutral-900 dark:text-white">Monthly Batch Reconciliation</h4>
                                    <p className="mt-1 text-xs text-neutral-600 dark:text-neutral-400">
                                        Generate compliant monthly claim export packages with automated checks against patient signatures, prescriptions, and lab proof.
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>

                {/* 5. CLINICAL & OPERATIONAL MODULES (Interactive Filterable Grid) */}
                <section id="modules" className="py-16 lg:py-24">
                    <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                        <div className="flex flex-col md:flex-row md:items-end md:justify-between">
                            <div>
                                <span className="text-xs font-bold tracking-wider uppercase text-amber-600 dark:text-amber-400">
                                    Hospital Core Architecture
                                </span>
                                <h2 className="mt-2 text-3xl font-extrabold tracking-tight text-neutral-900 sm:text-4xl dark:text-white">
                                    Comprehensive Clinical & Hospital Modules
                                </h2>
                                <p className="mt-2 text-sm text-neutral-600 dark:text-neutral-400">
                                    Every department at St. Joseph DMI is connected in a single, high-performance database.
                                </p>
                            </div>

                            {/* Category Filter Pills */}
                            <div className="mt-6 flex flex-wrap gap-2 md:mt-0">
                                {[
                                    { key: 'all', label: 'All Modules' },
                                    { key: 'clinical', label: 'Clinical Care' },
                                    { key: 'diagnostics', label: 'Diagnostics & Rx' },
                                    { key: 'finance', label: 'Finance & NHIF' },
                                    { key: 'operations', label: 'Supply Chain' },
                                ].map((tab) => (
                                    <button
                                        key={tab.key}
                                        type="button"
                                        onClick={() => setSelectedCategory(tab.key as ModuleCategory)}
                                        className={`rounded-lg px-3.5 py-1.5 text-xs font-semibold transition ${
                                            selectedCategory === tab.key
                                                ? 'bg-neutral-900 text-white dark:bg-white dark:text-neutral-900'
                                                : 'bg-neutral-200/70 text-neutral-700 hover:bg-neutral-200 dark:bg-neutral-800 dark:text-neutral-300 dark:hover:bg-neutral-700'
                                        }`}
                                    >
                                        {tab.label}
                                    </button>
                                ))}
                            </div>
                        </div>

                        {/* Modules Grid */}
                        <div className="mt-10 grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
                            {filteredModules.map((mod) => {
                                const IconComponent = mod.icon;
                                return (
                                    <div
                                        key={mod.id}
                                        className="group relative flex flex-col justify-between rounded-2xl border border-neutral-200/80 bg-white p-6 shadow-xs transition hover:shadow-md dark:border-neutral-800 dark:bg-neutral-900"
                                    >
                                        <div>
                                            <div className="flex items-center justify-between">
                                                <div className={`flex h-12 w-12 items-center justify-center rounded-xl bg-gradient-to-br ring-1 ${mod.color}`}>
                                                    <IconComponent className="h-6 w-6" />
                                                </div>
                                                <span className="rounded-full bg-neutral-100 px-2 py-0.5 text-[10px] font-semibold text-neutral-600 dark:bg-neutral-800 dark:text-neutral-400">
                                                    {mod.badge}
                                                </span>
                                            </div>

                                            <h3 className="mt-5 text-lg font-bold text-neutral-900 dark:text-white">
                                                {mod.title}
                                            </h3>

                                            <p className="mt-2 text-xs leading-relaxed text-neutral-600 dark:text-neutral-400">
                                                {mod.description}
                                            </p>

                                            <ul className="mt-4 space-y-1.5 border-t border-neutral-100 pt-4 dark:border-neutral-800">
                                                {mod.features.map((feat, fIdx) => (
                                                    <li key={fIdx} className="flex items-center gap-2 text-[11px] text-neutral-600 dark:text-neutral-300">
                                                        <CheckCircle2 className="h-3 w-3 shrink-0 text-emerald-500" />
                                                        <span className="truncate">{feat}</span>
                                                    </li>
                                                ))}
                                            </ul>
                                        </div>

                                        <div className="mt-6 pt-3 border-t border-neutral-100 dark:border-neutral-800 flex items-center justify-between">
                                            <span className="text-[11px] font-medium text-neutral-400">St. Joseph DMI</span>
                                            <Link
                                                href={auth.user ? route('dashboard') : route('login')}
                                                className="inline-flex items-center gap-1 text-xs font-semibold text-amber-600 group-hover:text-amber-500 dark:text-amber-400"
                                            >
                                                <span>Access</span>
                                                <ArrowRight className="h-3.5 w-3.5 transition group-hover:translate-x-0.5" />
                                            </Link>
                                        </div>
                                    </div>
                                );
                            })}
                        </div>
                    </div>
                </section>

                {/* 6. PATIENT JOURNEY / WORKFLOW BANNER */}
                <section id="workflow" className="border-t border-neutral-200/80 bg-neutral-100/60 py-16 lg:py-20 dark:border-neutral-800 dark:bg-neutral-900/40">
                    <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                        <div className="text-center max-w-2xl mx-auto">
                            <span className="text-xs font-bold tracking-wider uppercase text-amber-600 dark:text-amber-400">
                                End-to-End Continuity
                            </span>
                            <h2 className="mt-2 text-3xl font-extrabold tracking-tight text-neutral-900 sm:text-4xl dark:text-white">
                                The St. Joseph DMI Care Pathway
                            </h2>
                            <p className="mt-2 text-sm text-neutral-600 dark:text-neutral-400">
                                Every step in the patient journey triggers automated charge capture, medical recording, and insurance validation.
                            </p>
                        </div>

                        <div className="mt-12 grid grid-cols-2 gap-4 md:grid-cols-6">
                            {[
                                { step: '1', title: 'Reception & Triage', desc: 'ID check, queue, vitals & NHIF verification' },
                                { step: '2', title: 'Consultation', desc: 'Physician review, SOAP notes, ICD-10 diagnosis' },
                                { step: '3', title: 'Diagnostics', desc: 'Automated Lab analyzer & Radiology PACS orders' },
                                { step: '4', title: 'Pharmacy', desc: 'Rx verification, FEFO batch dispensing' },
                                { step: '5', title: 'Billing & Cashier', desc: 'Split copay calculation & payment receipt' },
                                { step: '6', title: 'NHIF Submission', desc: 'Electronic claim generated with zero errors' },
                            ].map((s, idx) => (
                                <div key={idx} className="relative rounded-xl border border-neutral-200/80 bg-white p-4 shadow-2xs dark:border-neutral-800 dark:bg-neutral-900">
                                    <div className="flex h-7 w-7 items-center justify-center rounded-lg bg-amber-500/10 text-xs font-bold text-amber-600 dark:bg-amber-500/20 dark:text-amber-400">
                                        {s.step}
                                    </div>
                                    <h4 className="mt-3 text-sm font-bold text-neutral-900 dark:text-white">{s.title}</h4>
                                    <p className="mt-1 text-[11px] text-neutral-500 dark:text-neutral-400 leading-snug">{s.desc}</p>
                                </div>
                            ))}
                        </div>
                    </div>
                </section>

                {/* 7. SECURITY, GOVERNANCE & AUDIT TRAIL */}
                <section id="security" className="py-16 lg:py-24">
                    <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                        <div className="rounded-3xl border border-neutral-200/80 bg-gradient-to-br from-neutral-900 via-neutral-900 to-neutral-950 p-8 text-white shadow-2xl lg:p-12 dark:border-neutral-800">
                            <div className="grid gap-8 lg:grid-cols-12 lg:items-center">
                                <div className="lg:col-span-7">
                                    <div className="inline-flex items-center gap-2 rounded-full bg-emerald-500/20 px-3 py-1 text-xs font-semibold text-emerald-300">
                                        <Lock className="h-3.5 w-3.5" />
                                        <span>Enterprise Security & Spatie RBAC</span>
                                    </div>
                                    <h2 className="mt-4 text-3xl font-extrabold sm:text-4xl text-white">
                                        Clinical Privacy, Licensing & Strict Financial Governance
                                    </h2>
                                    <p className="mt-3 text-sm leading-relaxed text-neutral-300">
                                        Designed for healthcare compliance: every diagnostic entry, medication dispensation,
                                        and financial adjustment is tied to authenticated credentials with full audit log trails.
                                    </p>

                                    <div className="mt-6 grid grid-cols-2 gap-4 text-xs">
                                        <div className="rounded-xl bg-white/5 p-3.5 border border-white/10">
                                            <p className="font-semibold text-white">Granular Role Permissions</p>
                                            <p className="mt-1 text-neutral-400">Doctors, Nurses, Pharmacists, Lab Techs & Billing Cashiers</p>
                                        </div>
                                        <div className="rounded-xl bg-white/5 p-3.5 border border-white/10">
                                            <p className="font-semibold text-white">Practitioner Attendance</p>
                                            <p className="mt-1 text-neutral-400">NHIF practitioner attendance verification & duty logging</p>
                                        </div>
                                        <div className="rounded-xl bg-white/5 p-3.5 border border-white/10">
                                            <p className="font-semibold text-white">Audit Trails</p>
                                            <p className="mt-1 text-neutral-400">Immutable logs on sensitive medical and billing records</p>
                                        </div>
                                        <div className="rounded-xl bg-white/5 p-3.5 border border-white/10">
                                            <p className="font-semibold text-white">Data Protection</p>
                                            <p className="mt-1 text-neutral-400">Encrypted sessions & strict role authorization guards</p>
                                        </div>
                                    </div>
                                </div>

                                <div className="lg:col-span-5 text-center lg:text-right">
                                    <div className="inline-block rounded-2xl bg-white/5 p-6 border border-white/10 backdrop-blur-sm text-left w-full max-w-sm">
                                        <div className="flex items-center gap-3">
                                            <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-amber-500/20 text-amber-400">
                                                <Building2 className="h-5 w-5" />
                                            </div>
                                            <div>
                                                <h4 className="font-bold text-white">{brand.hospitalName}</h4>
                                                <p className="text-xs text-neutral-400">Hospital Management System</p>
                                            </div>
                                        </div>

                                        <p className="mt-4 text-xs text-neutral-300">
                                            Hospital staff, clinicians, and administrators can sign in with their authorized credentials.
                                        </p>

                                        <Link
                                            href={auth.user ? route('dashboard') : route('login')}
                                            className="mt-6 flex w-full items-center justify-center gap-2 rounded-xl bg-amber-500 px-4 py-3 text-sm font-bold text-neutral-950 transition hover:bg-amber-400 active:scale-95"
                                        >
                                            <LogIn className="h-4 w-4" />
                                            <span>{auth.user ? 'Go to Dashboard' : 'Sign In to Portal'}</span>
                                        </Link>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>

                {/* 8. FOOTER */}
                <footer className="border-t border-neutral-200/80 bg-white py-12 dark:border-neutral-800 dark:bg-neutral-950">
                    <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                        <div className="flex flex-col md:flex-row md:items-center md:justify-between gap-6">
                            <div className="flex items-center gap-3">
                                <div className="flex h-9 w-9 items-center justify-center rounded-lg bg-amber-500/10 p-1 ring-1 ring-amber-500/20">
                                    <BrandMark className="h-full w-full object-contain" />
                                </div>
                                <div>
                                    <p className="text-sm font-bold text-neutral-900 dark:text-white">
                                        {brand.hospitalName}
                                    </p>
                                    <p className="text-xs text-neutral-500 dark:text-neutral-400">
                                        Hospital Management System & NHIF Smart Electronic Claims Engine
                                    </p>
                                </div>
                            </div>

                            <div className="flex flex-wrap items-center gap-6 text-xs text-neutral-500 dark:text-neutral-400">
                                <a href="#overview" className="hover:text-amber-600 dark:hover:text-amber-400">
                                    Home
                                </a>
                                <a href="#nhif-integration" className="hover:text-amber-600 dark:hover:text-amber-400">
                                    NHIF Integration
                                </a>
                                <a href="#modules" className="hover:text-amber-600 dark:hover:text-amber-400">
                                    Modules
                                </a>
                                <Link href={route('login')} className="hover:text-amber-600 dark:hover:text-amber-400">
                                    Staff Login
                                </Link>
                                <span className="flex items-center gap-1.5 text-emerald-600 dark:text-emerald-400">
                                    <span className="h-2 w-2 rounded-full bg-emerald-500" />
                                    All Systems Operational
                                </span>
                            </div>
                        </div>

                        <div className="mt-8 border-t border-neutral-100 pt-6 text-center text-xs text-neutral-400 dark:border-neutral-900">
                            <p>© {new Date().getFullYear()} {brand.hospitalName}. All rights reserved.</p>
                        </div>
                    </div>
                </footer>
            </div>
        </>
    );
}
