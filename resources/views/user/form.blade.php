<div class="box box-info padding-1">
    <div class="box-body">

        {{-- Nombre --}}
        <div class="form-group mb-3">
            <label for="name">Nombre Completo</label>
            <input
                type="text"
                id="name"
                name="name"
                value="{{ old('name', $user->name ?? '') }}"
                class="form-control @error('name') is-invalid @enderror"
                placeholder="Nombre Completo"
            >
            @error('name')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>

        {{-- Email --}}
        <div class="form-group mb-3">
            <label for="email">Email</label>
            <input
                type="email"
                id="email"
                name="email"
                value="{{ old('email', $user->email ?? '') }}"
                class="form-control @error('email') is-invalid @enderror"
                placeholder="Email"
            >
            @error('email')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>

        {{-- Password --}}
        <div class="form-group mb-3">
            <label for="password">Contraseña</label>
            <input
                type="password"
                id="password"
                name="password"
                class="form-control @error('password') is-invalid @enderror"
                placeholder="Contraseña"
            >
            @error('password')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror

            {{-- Tip para edición --}}
            @if(!empty($user?->id))
                <small class="text-muted">Deja en blanco si no quieres cambiar la contraseña.</small>
            @endif
        </div>

        {{-- Regional (ciudad) --}}
        <div class="form-group mb-3">
            <label for="ciudad">Regional</label>
            <select
                id="ciudad"
                name="ciudad"
                class="form-control @error('ciudad') is-invalid @enderror"
            >
                <option value="" disabled {{ old('ciudad', $user->ciudad ?? '') === '' ? 'selected' : '' }}>
                    Seleccione la Regional
                </option>

                @php
                    $regionales = ['LA PAZ','COCHABAMBA','SANTA CRUZ','ORURO','POTOSI','TARIJA','SUCRE','BENI','PANDO'];
                    $selectedRegional = old('ciudad', $user->ciudad ?? '');
                @endphp

                @foreach($regionales as $r)
                    <option value="{{ $r }}" {{ $selectedRegional === $r ? 'selected' : '' }}>
                        {{ $r }}
                    </option>
                @endforeach
            </select>

            @error('ciudad')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>

        {{-- CI --}}
        <div class="form-group mb-3">
            <label for="ci">Carnet de Identidad</label>
            <input
                type="text"
                id="ci"
                name="ci"
                value="{{ old('ci', $user->ci ?? '') }}"
                class="form-control @error('ci') is-invalid @enderror"
                placeholder="Carnet de Identidad"
            >
            @error('ci')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>

        {{-- Roles --}}
        <h2 class="h5 mt-4">Listado de Roles</h2>

        @php
            // Para que funcione en create y edit:
            // - en create: $user puede ser null o nuevo
            // - en edit: $user existe
            $oldRoles = old('roles', null); // si viene de validación fallida
        @endphp

        @foreach ($roles as $role)
            @php
                $checked = false;

                // 1) Si hay old('roles'), respeta eso
                if (is_array($oldRoles)) {
                    $checked = in_array($role->id, $oldRoles);
                } else {
                    // 2) Si no hay old, usa roles del usuario (Spatie)
                    $checked = !empty($user?->id) ? $user->hasRole($role->name) : false;
                }
            @endphp

            <div class="form-check">
                <input
                    class="form-check-input"
                    type="checkbox"
                    name="roles[]"
                    id="role_{{ $role->id }}"
                    value="{{ $role->id }}"
                    {{ $checked ? 'checked' : '' }}
                >
                <label class="form-check-label" for="role_{{ $role->id }}">
                    {{ $role->name }}
                </label>
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
