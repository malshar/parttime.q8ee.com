<?php

namespace App\Http\Controllers\Instructor;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProfileRequest;
use App\Http\Requests\SalaryRequest;
use App\Models\AuditLog;
use App\Models\Instructor;
use App\Support\ProfileDiff;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProfileController extends Controller
{
    public function edit(Request $request): View
    {
        $instructor = $request->user()->instructor ?? new Instructor;

        return view('instructor.profile', [
            'instructor' => $instructor,
            'locked' => $instructor->exists && $instructor->hasLockedApplication(),
        ]);
    }

    public function update(ProfileRequest $request): RedirectResponse
    {
        $user = $request->user();
        if ($user->instructor?->hasLockedApplication()) {
            return back()->withErrors(['profile' => __('app.profile.locked')]);
        }
        $data = $request->profileAttributes();
        if ($data['highest_degree'] !== 'bachelor') {
            $data['experience_years'] = null;
        }

        $changed = ProfileDiff::changedFields($user->instructor()->first() ?? new Instructor, $data);
        $instructor = $user->instructor()->updateOrCreate(['user_id' => $user->id], $data);
        // Keep the request's user object consistent with what was just saved (it may have cached a
        // null "instructor" above, before this profile existed).
        $user->setRelation('instructor', $instructor);
        if ($changed !== []) {
            // Field names only, never values (spec §4.2 rule 4b reads these).
            AuditLog::record($user->id, 'edit_profile', $instructor, null, implode(',', $changed));
        }

        return redirect()->route('instructor.home')->with('status', __('app.common.saved'));
    }

    /** Spec 5b §7: salary is entered after approval, while the rest of the profile is locked. */
    public function updateSalary(SalaryRequest $request): RedirectResponse
    {
        $instructor = $request->user()->instructor;
        abort_unless($instructor && $instructor->hasApprovedApplicationInOpenTerm(), 403);

        $instructor->update($request->only('basic_salary', 'total_salary'));
        AuditLog::record($request->user()->id, 'edit_profile', $instructor, null, 'basic_salary,total_salary');

        return back()->with('status', __('app.profile.salary_saved'));
    }
}
