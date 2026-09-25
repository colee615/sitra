@extends('adminlte::page')
@section('title','Accesos del equipo | SITRA')
@section('content_header')<div class="postal-heading"><div><span class="postal-eyebrow">ADMINISTRACIÓN</span><h1>Accesos del equipo</h1><p>Define las tareas disponibles para cada rol. Los permisos se aplican al menú y a las rutas.</p></div></div>@stop
@section('content')
<div class="postal-workspace">
@if(session('success'))<div class="alert alert-success" role="status">{{ session('success') }}</div>@endif
@if($errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif
<form class="postal-panel" method="get"><label for="access-role">Rol del equipo</label><div class="input-group"><select id="access-role" name="role" class="form-control">@foreach($roles as $role)<option value="{{ $role->id }}" @selected($selectedRole?->id === $role->id)>{{ $role->name }}</option>@endforeach</select><div class="input-group-append"><button class="btn btn-primary">Ver permisos</button></div></div></form>
@if($selectedRole)
<form method="post" action="{{ route('postal.access.update', $selectedRole) }}" class="postal-panel">@csrf @method('PUT')
<div class="postal-section-heading"><h2><i class="fas fa-user-shield"></i> {{ $selectedRole->name }}</h2><span>{{ $selectedRole->permissions->count() }} permisos asignados</span></div>
<p class="postal-note">Consultar no permite registrar entregas. El rol administrador puede abrir el expediente y consultar CDS; las escrituras IPS mantienen sus permisos y validaciones operativas. Los permisos directos de cada usuario se conservan.</p>
@php($labels = ['ips.read'=>['Consultar IPS','Ver paquetes y movimientos postales.'], 'cds.read'=>['Consultar CDS','Ver declaraciones, artículos, respuestas y copias aduaneras.'], 'ips.create'=>['Registrar paquetes IPS','Habilitar el alta de paquetes.'], 'ips.events'=>['Registrar eventos IPS','Habilitar movimientos compatibles con el paquete.'], 'ips.deliver'=>['Registrar entrega IPS','Habilitar la baja con sus comprobaciones operativas.'], 'ips.operations'=>['Consultar operaciones IPS','Consultar operaciones de integración autorizadas.']])
<div class="postal-task-grid">@foreach($permissions as $permission)<label class="postal-task"><input type="checkbox" name="permissions[]" value="{{ $permission->id }}" @checked($selectedRole->permissions->contains('id', $permission->id))> <strong>{{ $labels[$permission->name][0] ?? $permission->name }}</strong><p>{{ $labels[$permission->name][1] ?? 'Permiso de aplicación.' }}</p><small>{{ $permission->name }}</small></label>@endforeach</div>
<button class="btn btn-primary"><i class="fas fa-save"></i> Guardar permisos de este rol</button></form>
@else<div class="postal-empty">Crea un rol para configurar sus permisos.</div>@endif
</div>@include('footer')@stop
