<?php

namespace App\Http\Requests;

use App\Enums\ProjectActivity;
use App\Enums\ProjectPhase;
use App\Enums\PublicationStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('project'));
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
        ];
    }
}
