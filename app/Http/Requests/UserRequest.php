<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UserRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $userId = $this->route('user');

        return [
            'name'  => 'required|string|max:255',
            'email' => [
                'required', 'email', 'max:255',
                Rule::unique('users', 'email')->ignore($userId),
            ],
            // Contraseña obligatoria solo en creación
            'password' => $this->isMethod('post') ? 'required|min:8' : 'nullable|min:8',
            'role'     => 'required|string|exists:roles,name',
            'status'   => 'required|boolean',
            'sucursal_activa_id' => 'nullable|exists:sucursales,id'
        ];
    }
}
