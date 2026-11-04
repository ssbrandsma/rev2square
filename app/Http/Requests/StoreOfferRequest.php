<?php

namespace App\Http\Requests;

use App\Enums\OfferType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOfferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('view', $this->route('project'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(OfferType::class)],
            'contributor_task_id' => [
                Rule::requiredIf($this->input('type') === OfferType::ClaimTask->value),
                'nullable',
                'integer',
                Rule::exists('contributor_tasks', 'id')->where('project_id', $this->route('project')->id),
            ],
            'message' => ['required', 'string', 'max:2000'],
        ];
    }
}
