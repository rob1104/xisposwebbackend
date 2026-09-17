<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use App\Models\DatabaseBackup;
use App\Jobs\CreateDatabaseBackupJob;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BackupController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('permission:Respaldar base de datos'),
        ];
    }

    public function index()
    {
        $backups = DatabaseBackup::with('user:id,name')->orderBy('created_at', 'desc')->get();
        return response()->json($backups);
    }

    public function store(Request $request, \App\Services\BackupService $backupService)
    {
        $running = DatabaseBackup::where('status', 'running')->exists();
        if ($running) {
            return response()->json(['message' => 'Ya existe un respaldo en ejecución.'], 422);
        }

        $backup = DatabaseBackup::create([
            'user_id' => $request->user()->id,
            'status' => 'running',
            'disk' => 'local',
        ]);

        try {
            $backupService->executeBackup($backup);
            return response()->json(['message' => 'Respaldo completado con éxito', 'backup' => $backup->fresh()], 201);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Error al ejecutar el respaldo: ' . $e->getMessage()], 500);
        }
    }

    public function show($id)
    {
        $backup = DatabaseBackup::with('user:id,name')->findOrFail($id);
        return response()->json($backup);
    }

    public function download($id)
    {
        $backup = DatabaseBackup::findOrFail($id);

        if ($backup->status !== 'completed' || !$backup->path) {
            return response()->json(['message' => 'El respaldo no está disponible para descarga.'], 404);
        }

        if (!Storage::disk($backup->disk)->exists($backup->path)) {
            return response()->json(['message' => 'El archivo no existe físicamente.'], 404);
        }

        return Storage::disk($backup->disk)->download($backup->path, $backup->filename);
    }

    public function destroy($id)
    {
        $backup = DatabaseBackup::findOrFail($id);

        if ($backup->status === 'running') {
            return response()->json(['message' => 'No se puede eliminar un respaldo en ejecución.'], 422);
        }

        if ($backup->path && Storage::disk($backup->disk)->exists($backup->path)) {
            Storage::disk($backup->disk)->delete($backup->path);
        }

        $backup->delete();

        return response()->json(['message' => 'Respaldo eliminado']);
    }
}
