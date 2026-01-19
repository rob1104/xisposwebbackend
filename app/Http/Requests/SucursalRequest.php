<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SucursalRequest extends FormRequest
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
        $sucursalId = $this->route('sucursale') ? $this->route('sucursale')->id : null;
        return [
            'nombre' => 'required|string|max:255',
            'direccion' => 'nullable|string',
            'telefono' => 'nullable|string',
            'prefijo' => ['required', 'string', 'max:5', Rule::unique('sucursales')->ignore($sucursalId)],
            'bascula' => 'nullable|string|max:5'
        ];
    }
}
