<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\AdminProfileRequest;
use App\Models\Application;
use App\Models\AuditLog;
use App\Support\ProfileDiff;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProfileController extends Controller
{
    public function edit(Request $request, Application $application): View
    {
        $this->authorize('review', $application);
        // The form pre-fills decrypted civil ID / IBAN / salaries, so every render is a sensitive reveal.
        AuditLog::record($request->user()->id, 'reveal_sensitive', $application, null, 'profile_edit_form');

        return view('admin.applications.profile', ['application' => $application, 'instructor' => $application->instructor]);
    }

    public function update(AdminProfileRequest $request, Application $application): RedirectResponse
    {
        $this->authorize('review', $application);
        $instructor = $application->instructor;
        $data = $request->profileAttributes();
        if ($data['highest_degree'] !== 'bachelor') {
            $data['experience_years'] = null;
        }

        $changed = ProfileDiff::changedFields($instructor, $data);
        $instructor->fill($data)->save();

        AuditLog::record($request->user()->id, 'admin_edit_profile', $instructor, null, $changed === [] ? null : implode(',', $changed));

        return redirect()->route('admin.applications.show', $application)->with('status', __('app.common.saved'));
    }
}
