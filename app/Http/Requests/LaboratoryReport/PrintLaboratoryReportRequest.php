<?php

namespace App\Http\Requests\LaboratoryReport;

use Illuminate\Foundation\Http\FormRequest;

class PrintLaboratoryReportRequest extends FormRequest
{
    /**
     * The 'laboratory-reports.print' permission plus hospital scoping is
     * enforced by LaboratoryReportPolicy in the controller. Printing
     * carries no body of its own — it only stamps printed_by/printed_at.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
