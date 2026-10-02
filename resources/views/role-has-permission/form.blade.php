<div class="box box-info padding-1">
    <div class="box-body">
        <div class="form-group">
            <label for="permission_id">Permiso</label>
            <select id="permission_id" name="permission_id" class="form-control @error('permission_id') is-invalid @enderror" required><option value="">Selecciona un permiso</option>@foreach(\App\Models\Permission::pluck('name', 'id') as $id=>$name)<option value="{{ $id }}" @selected(old('permission_id', $roleHasPermission->permission_id) == $id)>{{ $name }}</option>@endforeach</select>
            {!! $errors->first('permission_id', '<div class="invalid-feedback">:message</div>') !!}
        </div>
        
        <div class="form-group">
            <label for="role_id">Rol</label>
            <select id="role_id" name="role_id" class="form-control @error('role_id') is-invalid @enderror" required><option value="">Selecciona un rol</option>@foreach(\App\Models\Role::pluck('name', 'id') as $id=>$name)<option value="{{ $id }}" @selected(old('role_id', $roleHasPermission->role_id) == $id)>{{ $name }}</option>@endforeach</select>
            {!! $errors->first('role_id', '<div class="invalid-feedback">:message</div>') !!}
        </div>
    </div>
    <div class="box-footer mt20">
        <a href="{{ route('role-has-permissions.index') }}" class="btn btn-outline-secondary mr-2">Volver a asignaciones</a>
        <button type="submit" class="btn btn-primary">Guardar asignación</button>
    </div>
</div>
