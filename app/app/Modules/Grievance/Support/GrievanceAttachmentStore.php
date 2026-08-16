<?php

declare(strict_types=1);

namespace App\Modules\Grievance\Support;

use App\Modules\Grievance\Enums\TicketEventKind;
use App\Modules\Grievance\Models\Ticket;
use App\Modules\Grievance\Models\TicketAttachment;
use App\Modules\Grievance\Services\GrievanceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Puts grievance evidence on the private bucket and records it on the ticket
 * timeline.
 *
 * The stored filename is randomised and the original is kept only as a column:
 * complainants upload files called things like `aadhaar-card-scan.pdf`, and a
 * filename that descriptive should not survive into an object key that might
 * appear in a bucket listing or an access log.
 */
final class GrievanceAttachmentStore
{
    private const DISK = 'grievance';

    public function __construct(private readonly GrievanceService $grievances) {}

    /**
     * @param  array<int, UploadedFile|null>  $files
     * @return array<int, TicketAttachment>
     */
    public function storeMany(Ticket $ticket, array $files, ?int $uploadedByUserId): array
    {
        $stored = [];

        foreach ($files as $file) {
            if ($file instanceof UploadedFile) {
                $stored[] = $this->store($ticket, $file, $uploadedByUserId);
            }
        }

        return $stored;
    }

    public function store(Ticket $ticket, UploadedFile $file, ?int $uploadedByUserId): TicketAttachment
    {
        $extension = strtolower($file->getClientOriginalExtension() ?: 'bin');
        $path = sprintf('%s/%s.%s', $ticket->ticket_no, Str::random(32), $extension);

        Storage::disk(self::DISK)->put($path, $file->getContent(), 'private');

        $attachment = TicketAttachment::create([
            'ticket_id' => $ticket->id,
            'uploaded_by_user_id' => $uploadedByUserId,
            'disk' => self::DISK,
            'path' => $path,
            'original_name' => Str::limit($file->getClientOriginalName(), 250, ''),
            'mime_type' => $file->getMimeType() ?? 'application/octet-stream',
            'size_bytes' => $file->getSize() ?: 0,
            'created_at' => Carbon::now(),
        ]);

        $this->grievances->recordEvent(
            $ticket,
            TicketEventKind::Attachment,
            actorUserId: $uploadedByUserId,
            toValue: (string) $attachment->id,
            note: 'Evidence attached: '.$attachment->original_name,
        );

        return $attachment;
    }

    /**
     * Hands an attachment back to an authorised reader.
     *
     * For S3 this redirects to a short-lived signed URL rather than streaming
     * the bytes. Streaming via Storage::response() works locally but fails on
     * Cloudways PHP-FPM (output buffering vs. the S3 SDK stream wrapper) — the
     * same quirk AdminKycController documents, and the same fix.
     */
    public function downloadResponse(TicketAttachment $attachment): RedirectResponse|StreamedResponse
    {
        $disk = Storage::disk($attachment->disk);

        if (! $disk->exists($attachment->path)) {
            throw new NotFoundHttpException('Attachment file not found.');
        }

        if (config('filesystems.disks.'.$attachment->disk.'.driver') === 's3') {
            return redirect()->away((string) $disk->temporaryUrl(
                $attachment->path,
                Carbon::now()->addMinutes(15),
            ));
        }

        return $disk->download($attachment->path, $attachment->original_name);
    }
}
