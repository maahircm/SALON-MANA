<?php

namespace App\Http\Controllers;

use App\Models\Service;
use App\Models\Skill;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SkillController extends Controller
{
    public function index()
    {
        $this->authorize('employees.view');

        return view('employees.skills', ['skills' => Skill::withCount('employees')->orderBy('name')->get()]);
    }

    public function store(Request $request)
    {
        $this->authorize('employees.manage');
        Skill::create($request->validate(['name' => ['required', 'string', 'max:100', Rule::unique('skills', 'name')]]));

        return back()->with('success', 'Skill added.');
    }

    public function destroy(Skill $skill)
    {
        $this->authorize('employees.manage');
        if ($skill->employees()->exists() || Service::where('required_skill_id', $skill->id)->exists()) {
            throw ValidationException::withMessages(['skill' => 'This skill is assigned to employees or required by a service.']);
        }
        $skill->delete();

        return back()->with('success', 'Skill removed.');
    }
}
