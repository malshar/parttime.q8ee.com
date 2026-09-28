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
        return view('instructor.profile', ['instructor' => $request->user()->instructor ?? new Instructor]);
    }

    public function update(ProfileRequest $request): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validated();
        if ($data['highest_degree'] !== 'bachelor') {
            $data['experience_years'] = null;
        }

        $user->instructor()->updateOrCreate(['user_id' => $user->id], $data);

        return redirect()->route('instructor.home')->with('status', __('app.common.saved'));
    }
}
