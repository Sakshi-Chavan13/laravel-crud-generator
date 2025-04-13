<?php

declare(strict_types=1);

namespace App\Http\Requests\Post;

use Illuminate\Foundation\Http\FormRequest;
use App\Constants\Permissions\PostPermissions;

class StorePostRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
         return $this->user()?->can(PostPermissions::CREATE_POST['name']) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title' => ['string', 'required'],
            'content' => ['text', 'required'],
            'user_id' => ['integer', 'required', 'exists:users,id'],
            'owner_id' => ['integer', 'required', 'exists:owners,id'],
            'is_restricted' => ['boolean', 'required'],
        ];
    }
}
