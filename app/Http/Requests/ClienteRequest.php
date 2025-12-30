<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ClienteRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation()
    {
        $this->merge([
            'dias_credito'   => $this->dias_credito ?? 0,
            'vender_vencido' => filter_var($this->vender_vencido, FILTER_VALIDATE_BOOLEAN),
        ]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $clienteId = $this->route('cliente') ? $this->route('cliente')->id : null;

        return [
            'nombre_comercial' => 'required|string|max:100',
            'razon_social'     => 'nullable|string|max:100',
            'rfc'              => 'nullable|string|max:24',
            'telefono'         => 'nullable|string|max:30',
            'telefono2'        => 'nullable|string|max:30',
            'contacto'         => 'nullable|string|max:64',
            'calle'            => 'nullable|string|max:99',
            'no_exterior'      => 'nullable|string|max:8',
            'no_interior'      => 'nullable|string|max:8',
            'colonia'          => 'nullable|string|max:100',
            'codigo_postal'    => 'nullable|string|max:5',
            'ciudad'           => 'nullable|string|max:100',
            'estado'           => 'nullable|string|max:100',
            'pais'             => 'nullable|string|max:50',
            'limite_credito'   => 'nullable|numeric',
            'saldo_actual'     => 'nullable|numeric',
            'tipo_pago'        => 'required|integer|in:0,1,2,3',
            'dias_credito'   => 'required|integer|min:0',
            'vender_vencido' => 'required|boolean',
            'obs'              => 'nullable|string',
            'tax_regime_id'    => 'nullable|exists:tax_regimes,id',
            'email' => [
                'required',
                'email',
                Rule::unique('clientes', 'email')->ignore($clienteId)
            ],
        ];
    }
}
