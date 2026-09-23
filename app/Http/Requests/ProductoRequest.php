<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProductoRequest extends FormRequest
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
        $productoId = $this->route('producto') ? $this->route('producto')->id : null;

        return [
            'imagen_file' => 'nullable|image|max:10240',
            'status'              => 'required|boolean',
            'nombre'              => 'required|string|max:150',
            'codigo_barras'       => ['required', 'string', 'max:64', Rule::unique('productos')->ignore($productoId)],
            'categoria_id'        => 'required|exists:categorias,id',
            'clave_prod_serv'     => 'required|string|size:8',
            'clave_unidad'        => 'required|string',
            'objeto_imp'          => 'required|string|size:2',
            'tipo_producto'       => 'required|in:Inventariable,Compuesto,Servicio',
            'ultimo_costo_compra' => 'numeric|min:0',
            'merma'               => 'nullable|numeric|min:0|max:100',

            // Validaciones para relaciones
            'impuestos'           => 'nullable|array',
            'impuestos.*'         => 'exists:impuestos,id',

            'precios' => [
                'required',
                'array',
                'min:1',
                function ($attribute, $value, $fail) {
                    // Buscamos si existe "PRECIO PUBLICO" en el array de precios
                    $nombres = collect($value)->pluck('nombre_lista')->map(fn($n) => strtoupper($n))->toArray();
                    if (!in_array('PRECIO PUBLICO', $nombres)) {
                        $fail('Es requerido definir al menos el "PRECIO PUBLICO".');
                    }
                },
            ],
            'precios.*.nombre_lista' => 'required|string',
            'precios.*.precio'       => 'required|numeric|min:0',

            // Solo requerido si es Kit/Compuesto
            'componentes'         => 'required_if:tipo_producto,Compuesto|array',
            'componentes.*.id' => [
                'required',
                'exists:productos,id',
                function ($attribute, $value, $fail) use ($productoId) {
                    if (!$productoId) return; // Si es creacion nueva, no hay ciclo posible aun
                    
                    // Verificamos ciclos para no hacer loops infinitos
                    $verificarCiclo = function($hijoId, $buscadoId) use (&$verificarCiclo) {
                        if ($hijoId == $buscadoId) return true;
                        $nietos = \Illuminate\Support\Facades\DB::table('producto_composicion')
                            ->where('producto_padre_id', $hijoId)
                            ->pluck('producto_hijo_id');
                        foreach ($nietos as $n) {
                            if ($verificarCiclo($n, $buscadoId)) return true;
                        }
                        return false;
                    };
                    
                    if ($verificarCiclo($value, $productoId)) {
                        $fail('No se permite agregar este componente porque genera un ciclo infinito (el componente ya te contiene directa o indirectamente).');
                    }
                }
            ],
            'componentes.*.cantidad' => 'numeric'
        ];
    }
}
