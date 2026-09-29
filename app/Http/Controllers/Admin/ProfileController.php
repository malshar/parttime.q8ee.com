<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\AdminProfileRequest;
use App\Models\Application;
use App\Models\AuditLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class ProfileController extends Controller
{
    /** Date fields whose cast original must be formatted before comparing to the submitted string. */
    private const DATE_FIELDS = ['civil_id_expires_on', 'degree_obtained_on'];

    public function edit(Application $application): View
    {
        $this->authorize('review', $application);

        return view('admin.applications.profile', ['application' => $application, 'instructor' => $application->instructor]);
    }

    public function update(AdminProfileRequest $request, Application $application): RedirectResponse
    {
        $this->authorize('review', $application);
        $instructor = $application->instructor;
        $data = $request->validated();
        if ($data['highest_degree'] !== 'bachelor') {
            $data['experience_years'] = null;
        }

        // Encrypted attributes always report dirty (ciphertext differs per set), so the changed-field
        // list is computed from the decrypted originals before fill(), never from getDirty() after.
        $changed = [];
        foreach ($data as $field => $value) {
            $original = $instructor->getOriginal($field);
            $originalValue = in_array($field, self::DATE_FIELDS, true) ? optional($original)->format('Y-m-d') : $original;
            if ((string) ($originalValue ?? '') !== (string) ($value ?? '')) {
                $changed[] = $field;
            }
        }

        $instructor->fill($data)->save();

        sort($changed);
        AuditLog::record($request->user()->id, 'admin_edit_profile', $instructor, null, $changed === [] ? null : implode(',', $changed));

        return redirect()->route('admin.applications.show', $application)->with('status', __('app.common.saved'));
    }
}
