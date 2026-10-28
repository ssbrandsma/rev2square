<?php

namespace App\Http\Requests;

use App\Enums\ProjectActivity;
use App\Enums\ProjectPhase;
use App\Enums\PublicationStatus;
use App\Models\Project;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Project::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'pitch' => ['nullable', 'string', 'max:500'],
            'body' => ['nullable', 'string', 'max:20000'],
            'phase' => ['required', Rule::enum(ProjectPhase::class)],
            'activity' => ['required', Rule::enum(ProjectActivity::class)],
            'publication_status' => ['required', Rule::enum(PublicationStatus::class)],
            'device_manufacturer' => ['nullable', 'string', 'max:255'],
            'device_model' => ['nullable', 'string', 'max:255', 'required_with:device_manufacturer,device_hardware_revision,device_firmware_version'],
            'device_hardware_revision' => ['nullable', 'string', 'max:255'],
            'device_firmware_version' => ['nullable', 'string', 'max:255'],
        ];
    }
}
