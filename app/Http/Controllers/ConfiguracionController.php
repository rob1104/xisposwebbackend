<?php

namespace App\Http\Controllers;

use App\Models\Producto;
use App\Models\Setting;
use App\Models\Sucursal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ConfiguracionController extends Controller
{
    public function index()
    {
        return response()->json(Setting::all());
    }

    /**
     * Actualiza el nombre de la tienda y procesa la carga del logotipo.
     */
    public function update(Request $request)
    {
        // 1. Validamos los datos recibidos
        $request->validate([
            'nombre_tienda' => 'required|string|max:100',
            'logo' => 'nullable|image|mimes:jpeg,png,jpg,svg|max:2048' // Máximo 2MB
        ]);


        return DB::transaction(function () use ($request) {

            // 2. Actualizamos o creamos el nombre de la tienda
            Setting::updateOrCreate(
                ['clave' => 'nombre_tienda'],
                ['valor' => $request->nombre_tienda]
            );

            // 3. Procesamos el logotipo si se envió un archivo nuevo
            if ($request->hasFile('logo')) {

                $file = $request->file('logo');

                // Generamos un nombre único para evitar conflictos de caché
                $nombreArchivo = time() . '_' . $file->getClientOriginalName();

                // 2. GUARDAR DIRECTAMENTE EN LA RUTA PÚBLICA
                // Esto lo guardará en: D:\xampp82\htdocs\tu-proyecto\public\uploads\logo\
                $file->move(public_path('uploads/logo'), $nombreArchivo);

                // 3. GENERAR URL ACCESIBLE (Sin el prefijo /storage)
                // Esto generará: http://localhost/tu-proyecto/public/uploads/logo/archivo.jpg
                $url = asset('uploads/logo/' . $nombreArchivo);

                // Borrar el logo anterior físicamente si existe (opcional pero recomendado)
                $configOld = Setting::where('clave', 'logo_url')->first();
                if ($configOld && $configOld->valor) {
                    $oldPath = public_path(str_replace(asset(''), '', $configOld->valor));
                    if (file_exists($oldPath)) {
                        @unlink($oldPath);
                    }
                }

                Setting::updateOrCreate(
                    ['clave' => 'logo_url'],
                    ['valor' => $url]
                );
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Configuración visual actualizada correctamente'
            ]);
        });
    }

    public function sincronizarInventariosCero()
    {
        try {
            $sucursales = Sucursal::pluck('id');
            $productos = Producto::pluck('id');
            $insertados = 0;

            foreach ($sucursales as $sucursalId) {
                foreach ($productos as $productoId) {
                    // Verificamos si ya existe el registro en la tabla de inventarios
                    $existe = \DB::table('sucursal_productos')
                        ->where('sucursal_id', $sucursalId)
                        ->where('producto_id', $productoId)
                        ->exists();

                    if (!$existe) {
                        \DB::table('sucursal_productos')->insert([
                            'sucursal_id'    => $sucursalId,
                            'producto_id'    => $productoId,
                            'cantidad'       => 0.000000,
                            'stock_actual'   => 0.000000,
                            'stock_minimo'   => 0.000000,
                            'stock_maximo'   => 0.000000,
                            'costo_promedio' => 0.000000,
                            'created_at'     => now(),
                            'updated_at'     => now(),
                        ]);
                        $insertados++;
                    }
                }
            }

            return response()->json([
                'success' => true,
                'message' => "Sincronización completada. Se crearon $insertados registros de inventario."
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
}
