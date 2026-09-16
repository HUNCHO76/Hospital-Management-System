<?php

namespace App\Http\Controllers;

use App\Http\Requests\Billing\CancelInsuranceCoverageRuleRequest;
use App\Http\Requests\Billing\CreateInsuranceCoverageRuleRequest;
use App\Http\Resources\InsuranceCoverageRuleResource;
use App\Models\InsuranceCoverageRule;
use App\Models\InsuranceScheme;
use App\Models\Service;
use App\Services\InsuranceCoverageRuleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Backend-configured insurance coverage rules (Phase 7, Step 56, spec §13)
 * — kept thin: every query/write lives in InsuranceCoverageRuleService,
 * mirroring ServicePricingController's own shape exactly. Create + cancel
 * only, no update route — a rule is never edited in place (see the
 * create_insurance_coverage_rules_table migration's own docblock).
 */
class InsuranceCoverageRuleController extends Controller
{
    public function index(Request $request, InsuranceCoverageRuleService $rules): Response
    {
        $this->authorize('viewAny', InsuranceCoverageRule::class);

        $filters = $request->only(['insurance_scheme_id', 'status']);

        $coverageRules = $rules->query($filters)->paginate(20)->withQueryString()
            ->through(fn (InsuranceCoverageRule $rule) => (new InsuranceCoverageRuleResource($rule))->resolve($request));

        return Inertia::render('billing/insurance-rules/index', [
            'rules' => $coverageRules,
            'filters' => $filters,
            'schemes' => InsuranceScheme::query()->where('status', InsuranceScheme::STATUS_ACTIVE)->orderBy('name')->get(['id', 'name', 'code']),
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('create', InsuranceCoverageRule::class);

        return Inertia::render('billing/insurance-rules/create', [
            'schemes' => InsuranceScheme::query()->where('status', InsuranceScheme::STATUS_ACTIVE)->orderBy('name')->get(['id', 'name', 'code']),
            'services' => Service::query()->where('status', Service::STATUS_ACTIVE)->where('is_billable', true)->orderBy('name')->get(['id', 'code', 'name', 'category']),
            'categories' => Service::CATEGORIES,
        ]);
    }

    public function store(CreateInsuranceCoverageRuleRequest $request, InsuranceCoverageRuleService $rules): RedirectResponse
    {
        $this->authorize('create', InsuranceCoverageRule::class);

        $rules->create($request->validated(), $request);

        return to_route('billing-insurance-rules.index')->with('success', 'Coverage rule created.');
    }

    public function cancel(CancelInsuranceCoverageRuleRequest $request, InsuranceCoverageRule $insuranceCoverageRule, InsuranceCoverageRuleService $rules): RedirectResponse
    {
        $this->authorize('cancel', $insuranceCoverageRule);

        $rules->cancel($insuranceCoverageRule, $request->validated()['reason'], $request);

        return back()->with('success', 'Coverage rule cancelled.');
    }
}
