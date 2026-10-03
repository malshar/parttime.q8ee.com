<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\AuditLog;
use App\Models\Instructor;
use App\Services\AcademicBundle;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class InstructorController extends Controller
{
    public function show(Instructor $instructor): View
    {
        $this->authorize('viewAny', Application::class);

        return view('admin.instructors.show', [
            'instructor' => $instructor,
            'approvals' => $instructor->approvals()->orderByDesc('academic_year')->get(),
            'applications' => $instructor->applications()->with('term')->orderByDesc('created_at')->get(),
        ]);
    }

    public function bundle(Request $request, Instructor $instructor, AcademicBundle $bundle): BinaryFileResponse
    {
        $this->authorize('viewAny', Application::class);

        $path = $bundle->build($instructor, $request->user());
        AuditLog::record($request->user()->id, 'export_academic_bundle', $instructor);

        return response()->download($path, "academic-{$instructor->id}.zip", [
            'Content-Type' => 'application/zip',
        ])->deleteFileAfterSend(true);
    }
}
