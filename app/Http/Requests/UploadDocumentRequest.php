<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UploadDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // FR-001a: 50 МБ = 51200 КБ
            'file' => ['required', 'file', 'mimes:pdf', 'max:'.(config('rag.max_upload_size_mb') * 1024)],
        ];
    }
}
