<?php

namespace App\Services;

use App\Models\Application;
use App\Models\ChecklistItem;
use App\Models\Document;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentStore
{
    public const DISK = 'local';

    public function store(Application $application, ChecklistItem $item, UploadedFile $file): Document
    {
        $ext = strtolower($file->getClientOriginalExtension() ?: $file->extension());
        $path = $file->storeAs("applications/{$application->id}", Str::random(40).'.'.$ext, self::DISK);
        $version = (int) $application->documents()->where('checklist_item_id', $item->id)->max('version') + 1;

        return $application->documents()->create([
            'checklist_item_id' => $item->id,
            'path' => $path,
            'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
            'mime' => $file->getMimeType() ?? $file->getClientMimeType(),
            'size' => $file->getSize(),
            'status' => Document::STATUS_PENDING,
            'version' => $version,
        ]);
    }

    public function download(Document $document, bool $inline = false): StreamedResponse
    {
        $disk = Storage::disk(self::DISK);

        return $inline
            ? $disk->response($document->path, $document->original_name, ['Content-Type' => $document->mime])
            : $disk->download($document->path, $document->original_name);
    }
}
