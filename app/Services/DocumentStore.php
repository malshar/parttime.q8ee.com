<?php

namespace App\Services;

use App\Models\Application;
use App\Models\ChecklistItem;
use App\Models\Document;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentStore
{
    public const DISK = 'local';

    /**
     * @param  list<UploadedFile>  $files  one version, parts 1..n; returns the head (part 1)
     *
     * Each file is written to disk before its row is inserted, so a mid-loop failure (a bad file,
     * a constraint violation, …) can leave files on disk with no `documents` row. `DB::transaction`
     * only rolls back the rows; any stored path is tracked in `$paths` and deleted on the way out if
     * the whole operation did not complete, so sensitive files never linger unreferenced.
     */
    public function store(Application $application, ChecklistItem $item, array $files): Document
    {
        $paths = [];
        try {
            return DB::transaction(function () use ($application, $item, $files, &$paths) {
                $version = (int) $application->documents()->where('checklist_item_id', $item->id)->max('version') + 1;
                $head = null;
                foreach (array_values($files) as $i => $file) {
                    $ext = strtolower($file->getClientOriginalExtension() ?: $file->extension());
                    $path = $file->storeAs("applications/{$application->id}", Str::random(40).'.'.$ext, self::DISK);
                    $paths[] = $path;
                    $doc = $application->documents()->create([
                        'checklist_item_id' => $item->id,
                        'path' => $path,
                        'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
                        'mime' => $file->getMimeType() ?? $file->getClientMimeType(),
                        'size' => $file->getSize(),
                        'status' => Document::STATUS_PENDING,
                        'version' => $version,
                        'part' => $i + 1,
                    ]);
                    $head ??= $doc;
                }

                return $head;
            });
        } catch (\Throwable $e) {
            Storage::disk(self::DISK)->delete($paths);
            throw $e;
        }
    }

    public function download(Document $document, bool $inline = false): StreamedResponse
    {
        $disk = Storage::disk(self::DISK);

        return $inline
            ? $disk->response($document->path, $document->original_name, ['Content-Type' => $document->mime])
            : $disk->download($document->path, $document->original_name);
    }
}
