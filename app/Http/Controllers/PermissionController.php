<?php

namespace App\Http\Controllers;

use Spatie\Permission\Models\Role;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Permission;
use Illuminate\Http\Request;

class PermissionController extends Controller
{

    public function index()
    {
        $permissions = Permission::paginate();

        return view('permission.index', compact('permissions'))
            ->with('i', (request()->input('page', 1) - 1) * $permissions->perPage());
    }

    public function create()
    {
        $permission = new Permission();
        return view('permission.create', compact('permission'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|unique:roles',
            // Otras reglas de validación según tus necesidades
        ]);

        $permission = Permission::create($request->all());

        return redirect()->route('permissions.index')
            ->with('success', 'Permiso creado correctamente.');
    }

    public function show($id)
    {
        $permission = Permission::find($id);

        return view('permission.show', compact('permission'));
    }

    public function edit($id)
    {
        $permission = Permission::find($id);

        return view('permission.edit', compact('permission'));
    }

    public function update(Request $request, Permission $permission)
    {
        $request->validate([
            'name' => [
                'required',
                Rule::unique('permissions')->ignore($permission->id),
            ],
        ]);

        $permission->update($request->only('name'));

        return redirect()->route('permissions.index')
            ->with('success', 'Permiso actualizado correctamente.');
    }

    public function destroy($id)
    {
        $permission = Permission::find($id)->delete();

        return redirect()->route('permissions.index')
            ->with('success', 'Permiso eliminado correctamente');
    }
}
