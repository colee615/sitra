<div class="box box-info padding-1">
    <div class="box-body">

        <div class="form-group mb-3">
            <label for="name">Nombre Completo</label>
            <input type="text" id="name" name="name" value="{{ old('name', $user->name ?? '') }}" class="form-control @error('name') is-invalid @enderror" placeholder="Nombre Completo">
            @error('name')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>

        <div class="form-group mb-3">
            <label for="email">Email</label>
            <input type="email" id="email" name="email" value="{{ old('email', $user->email ?? '') }}" class="form-control @error('email') is-invalid @enderror" placeholder="Email">
            @error('email')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>

        <div class="form-group mb-3">
            <label for="password">Contrasena</label>
            <input type="password" id="password" name="password" class="form-control @error('password') is-invalid @enderror" placeholder="Contrasena">
            @error('password')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror

            @if(!empty($user?->id))
                <small class="text-muted">Deja en blanco si no quieres cambiar la contrasena.</small>
            @endif
        </div>

        <h2 class="h5 mt-4">Listado de Roles</h2>

        @php
            $oldRoles = old('roles', null);
        @endphp

        @foreach ($roles as $role)
            @php
                $checked = false;

                if (is_array($oldRoles)) {
                    $checked = in_array($role->id, $oldRoles);
                } else {
                    $checked = !empty($user?->id) ? $user->hasRole($role->name) : false;
                }
            @endphp

            <div class="form-check">
                <input class="form-check-input" type="checkbox" name="roles[]" id="role_{{ $role->id }}" value="{{ $role->id }}" {{ $checked ? 'checked' : '' }}>
                <label class="form-check-label" for="role_{{ $role->id }}">{{ $role->name }}</label>
            </div>
        @endforeach

        @error('roles')
            <div class="text-danger mt-2">{{ $message }}</div>
        @enderror

    </div>

    <div class="box-footer mt20">
        <button type="submit" class="btn btn-primary">{{ __('Listo') }}</button>
    </div>
</div>
