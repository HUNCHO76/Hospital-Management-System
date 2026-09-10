<?php

namespace App\Http\Requests\LaboratoryReport;

use Illuminate\Foundation\Http\FormRequest;

class DownloadLaboratoryReportRequest extends FormRequest
{
    /**
     * The 'laboratory-reports.download' permission plus hospital scoping is
     * enforced by LaboratoryReportPolicy in the controller. Downloading
     * carries no body of its own — it only stamps downloaded_by/downloaded_at.
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
