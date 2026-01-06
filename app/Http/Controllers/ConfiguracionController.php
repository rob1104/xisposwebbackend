<?php

namespace App\Http\Controllers;

use App\Models\Setting;
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
}
