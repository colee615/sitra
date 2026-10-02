<div class="box box-info padding-1">
    <div class="box-body">
        
        <div class="form-group">
            <label for="permission-name">Nombre del permiso</label>
            <input id="permission-name" name="name" value="{{ old('name', $permission->name) }}" class="form-control @error('name') is-invalid @enderror" required maxlength="255" placeholder="Nombre del permiso">
            {!! $errors->first('name', '<div class="invalid-feedback">:message</div>') !!}
        </div>
        <div class="form-group">
            <label for="permission-guard">Ámbito de acceso</label>
            <input id="permission-guard" name="guard_name" value="web" class="form-control" readonly>
            {!! $errors->first('guard_name', '<div class="invalid-feedback">:message</div>') !!}
        </div>
    </div>
    <div class="box-footer mt20">
        <div class="text-right">
            <a href="{{ route('permissions.index') }}" class="btn btn-outline-secondary mr-2">Volver a permisos</a>
            <button type="submit" class="btn btn-primary">{{ __('Guardar') }}</button>
        </div>
    </div>
</div>
