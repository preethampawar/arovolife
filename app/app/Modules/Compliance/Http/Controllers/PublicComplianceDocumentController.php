<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Http\Controllers;

use App\Modules\Compliance\Models\ComplianceDocument;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Public-facing compliance documents: a listing page anyone can view and
 * download. Only published documents are exposed; the file is streamed
 * from the private disk so unpublished files can never be guessed at.
 */
final class PublicComplianceDocumentController extends Controller
{
    private const DISK = 'local';

    public function index(): View
    {
        return view('compliance.documents', [
            'documents' => ComplianceDocument::query()
                ->published()
                ->latest()
                ->get(),
        ]);
    }

    public function download(ComplianceDocument $document): StreamedResponse
    {
        // Never expose an unpublished document, even by direct id.
        if (! $document->is_published) {
            throw new NotFoundHttpException;
        }

        if (! Storage::disk(self::DISK)->exists($document->file_path)) {
            throw new NotFoundHttpException;
        }

        return Storage::disk(self::DISK)->download($document->file_path, $document->original_name);
    }
}
