<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Http\Controllers\Admin;

use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Compliance\Models\ComplianceDocument;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

/**
 * Admin management of publicly-listed compliance documents.
 *
 * Files are stored on the private 'local' disk and only ever reach the
 * public via the streamed download route — never from the web root. Every
 * upload / publish-toggle / delete writes an audit-log entry.
 */
final class AdminComplianceDocumentController extends Controller
{
    private const DISK = 'local';
    private const DIR = 'compliance-documents';

    public function index(): View
    {
        return view('admin.compliance-documents.index', [
            'documents' => ComplianceDocument::query()
                ->with('uploader')
                ->latest()
                ->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:512'],
            'document' => ['required', 'file', 'mimes:pdf,doc,docx,xls,xlsx,jpg,jpeg,png', 'max:20480'],
        ], [
            'document.required' => 'Please choose a file to upload.',
            'document.mimes' => 'Allowed formats: PDF, Word, Excel or image (JPG/PNG).',
            'document.max' => 'The file must not be larger than 20 MB.',
        ]);

        $file = $request->file('document');
        $path = $file->store(self::DIR, self::DISK);

        $doc = ComplianceDocument::create([
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'file_path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime' => $file->getClientMimeType(),
            'size_bytes' => $file->getSize(),
            'is_published' => true,
            'uploaded_by' => Auth::id(),
        ]);

        AuditLog::create([
            'actor_id' => Auth::id(),
            'action' => 'admin.compliance_document.uploaded',
            'subject_type' => 'compliance_document',
            'subject_id' => $doc->id,
            'details' => ['title' => $doc->title, 'original_name' => $doc->original_name],
            'ip' => $request->ip(),
        ]);

        return back()->with('status', 'Compliance document uploaded and published.');
    }

    public function togglePublish(Request $request, ComplianceDocument $document): RedirectResponse
    {
        $document->update(['is_published' => ! $document->is_published]);

        AuditLog::create([
            'actor_id' => Auth::id(),
            'action' => 'admin.compliance_document.'.($document->is_published ? 'published' : 'unpublished'),
            'subject_type' => 'compliance_document',
            'subject_id' => $document->id,
            'details' => ['title' => $document->title],
            'ip' => $request->ip(),
        ]);

        return back()->with('status', $document->is_published
            ? 'Document is now visible to the public.'
            : 'Document is now hidden from the public.');
    }

    public function destroy(Request $request, ComplianceDocument $document): RedirectResponse
    {
        Storage::disk(self::DISK)->delete($document->file_path);

        AuditLog::create([
            'actor_id' => Auth::id(),
            'action' => 'admin.compliance_document.deleted',
            'subject_type' => 'compliance_document',
            'subject_id' => $document->id,
            'details' => ['title' => $document->title, 'original_name' => $document->original_name],
            'ip' => $request->ip(),
        ]);

        $document->delete();

        return back()->with('status', 'Compliance document deleted.');
    }
}
