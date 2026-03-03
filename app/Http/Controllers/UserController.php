<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{

    public function index()
    {
        $users = User::withTrashed()->paginate(); // Incluye usuarios eliminados

        return view('user.index', compact('users'))
            ->with('i', (request()->input('page', 1) - 1) * $users->perPage());
    }

    public function create()
    {
        $user = new User();
        $roles = Role::all();
        return view('user.create', compact('user','roles'));
    }

   public function store(Request $request)
{
    $request->validate([
        'name'   => 'required|string|max:255',
        'email'  => 'required|email|unique:users,email',
        'password' => 'required|min:8',
        'ciudad' => 'required|string|max:255', // <-- antes Regional
        'ci'     => 'required|string|max:255',
        'roles'  => 'nullable|array',
        'roles.*'=> 'integer|exists:roles,id',
    ]);

    $user = new User();
    $user->name     = $request->name;
    $user->email    = $request->email;
    $user->password = bcrypt($request->password);
    $user->ciudad   = $request->ciudad;   // ✅ correcto
    $user->ci       = $request->ci;
    $user->save();

    // ✅ Asignar roles por ID (convertimos a nombres)
    if ($request->filled('roles')) {
        $roleNames = Role::whereIn('id', $request->roles)->pluck('name')->toArray();
        $user->syncRoles($roleNames);
    }

    return redirect()->route('users.index')
        ->with('success', 'Usuario creado correctamente');
}


    public function show($id)
    {
        $user = User::find($id);

        return view('user.show', compact('user'));
    }

    public function edit($id)
    {
        $user = User::find($id);
        $roles = Role::all();

        return view('user.edit', compact('user','roles'));
    }

    public function update(Request $request, User $user)
{
    $request->validate([
        'name'   => 'required|string|max:255',
        'email'  => 'required|email|unique:users,email,' . $user->id,
        'ciudad' => 'required|string|max:255',
        'ci'     => 'required|string|max:255',
        'roles'  => 'nullable|array',
        'roles.*'=> 'integer|exists:roles,id',
        'password' => 'nullable|min:8',
    ]);

    $user->name   = $request->name;
    $user->email  = $request->email;
    $user->ciudad = $request->ciudad;
    $user->ci     = $request->ci;

    // password opcional en edit
    if ($request->filled('password')) {
        $user->password = bcrypt($request->password);
    }

    $user->save();

    // ✅ sincronizar roles
    $roleNames = $request->filled('roles')
        ? Role::whereIn('id', $request->roles)->pluck('name')->toArray()
        : [];
    $user->syncRoles($roleNames);

    return redirect()->route('users.index')
        ->with('success', 'Usuario actualizado correctamente');
}


    public function destroy($id)
    {
        $user = User::findOrFail($id);
        $user->delete();

        return redirect()->route('users.index')
            ->with('success', 'Usuario dado de baja correctamente');
    }

    public function restore($id)
    {
        $user = User::withTrashed()->findOrFail($id);
        $user->restore();

        return redirect()->route('users.index')
            ->with('success', 'Usuario reactivado correctamente');
    }
}
