<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class PostalAccessController extends Controller
{
    public function index(Request $request)
    {
        $request->validate(['role'=>['nullable','integer']]);
        $roles = Role::where('guard_name', 'web')->orderBy('name')->get();
        $selectedRole = $request->filled('role') ? $roles->firstWhere('id', $request->integer('role')) : $roles->first();
        if ($request->filled('role')) abort_unless($selectedRole, 404);
        $selectedRole?->load('permissions');
        return view('postal.access', ['roles'=>$roles, 'selectedRole'=>$selectedRole,
            'permissions'=>Permission::where('guard_name','web')->orderBy('name')->get()]);
    }

    public function update(Request $request, Role $role)
    {
        abort_unless($role->guard_name === 'web', 404);
        $data = $request->validate(['permissions'=>['sometimes','array'], 'permissions.*'=>['integer','distinct', Rule::exists('permissions','id')->where('guard_name','web')]]);
        DB::transaction(fn () => $role->syncPermissions(Permission::whereIn('id', $data['permissions'] ?? [])->where('guard_name','web')->get()));
        return redirect()->route('postal.access.index', ['role'=>$role->id])->with('success', 'Permisos del rol actualizados.');
    }
}
