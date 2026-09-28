<?php

namespace App\Http\Controllers\Instructor;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProfileRequest;
use App\Models\Instructor;
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
        $data = $request->validated();
        if ($data['highest_degree'] !== 'bachelor') {
            $data['experience_years'] = null;
        }

        $user->instructor()->updateOrCreate(['user_id' => $user->id], $data);

        return redirect()->route('instructor.home')->with('status', __('app.common.saved'));
    }
}
