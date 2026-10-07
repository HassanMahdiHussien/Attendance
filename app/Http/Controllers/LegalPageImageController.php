<?php

namespace App\Http\Controllers;

use App\Models\LegalImportPage;
use App\Models\LegalImportRun;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class LegalPageImageController
{
    public function __invoke(Request $request, int $run, int $page): BinaryFileResponse
    {
        $import = LegalImportRun::query()->findOrFail($run);
        abort_unless($request->user()->can('viewPage', $import->law), 404);
        $image = LegalImportPage::query()->where('run_id', $import->id)
            ->where('page_number', $page)->firstOrFail();
        abort_unless($image->image_path === "legal-library/runs/{$import->id}/pages/{$page}.png"
            && Storage::disk('local')->exists($image->image_path), 404);

        return response()->file(Storage::disk('local')->path($image->image_path), [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
