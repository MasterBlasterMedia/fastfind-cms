<?php

namespace App\Http\Requests;

use App\Services\PageDataRules;
use Illuminate\Foundation\Http\FormRequest;

final class SavePageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('page')) ?? false;
    }

    public function rules(): array
    {
        return ['version' => ['required', 'integer', 'min:1'], ...PageDataRules::for($this->route('page'))];
    }
}
