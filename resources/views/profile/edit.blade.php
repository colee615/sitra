@extends('adminlte::page')
@section('title', 'Mi perfil | SITRA')
@section('content_header')
<div class="postal-heading"><div><span class="postal-eyebrow">MI CUENTA</span><h1>Perfil y seguridad</h1><p>Administra tus datos personales y protege el acceso a tu cuenta.</p></div></div>
@stop
@section('content')
<div class="sitra-profile-grid">
    <aside class="postal-panel sitra-profile-summary"><span class="sitra-profile-avatar"><i class="fas fa-user"></i></span><h2>{{ $user->name }}</h2><p>{{ $user->email }}</p><span class="badge badge-primary">Cuenta institucional</span><nav aria-label="Secciones del perfil"><a href="#profile-information">Información personal</a><a href="#profile-password">Cambiar contraseña</a><a href="#profile-delete">Administrar cuenta</a></nav></aside>
    <div>
        <section class="postal-panel" id="profile-information"><div class="postal-section-heading"><h2>Información personal</h2><i class="fas fa-id-card"></i></div><p class="postal-note">Actualiza tu nombre y el correo asociado a tu cuenta.</p>
            <form id="send-verification" method="post" action="{{ route('verification.send') }}">@csrf</form>
            <form action="{{ route('profile.update') }}" method="post">@csrf @method('patch')
                <div class="form-group"><label for="name">Nombre completo</label><input class="form-control @error('name') is-invalid @enderror" id="name" name="name" value="{{ old('name', $user->name) }}" required autocomplete="name">@error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                <div class="form-group"><label for="email">Correo electrónico</label><input class="form-control @error('email') is-invalid @enderror" type="email" id="email" name="email" value="{{ old('email', $user->email) }}" required autocomplete="username">@error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                @if($user instanceof \Illuminate\Contracts\Auth\MustVerifyEmail && !$user->hasVerifiedEmail())<div class="alert alert-warning">Tu correo aún no está verificado. <button class="btn btn-link" form="send-verification">Reenviar correo de verificación</button></div>@endif
                @if(session('status') === 'verification-link-sent')<p class="text-success" role="status">Se envió un nuevo enlace de verificación.</p>@endif
                <div class="sitra-form-actions"><button class="btn btn-primary">Guardar información</button>@if(session('status') === 'profile-updated')<span class="text-success" role="status">Información actualizada.</span>@endif</div>
            </form>
        </section>
        <section class="postal-panel" id="profile-password"><div class="postal-section-heading"><h2>Cambiar contraseña</h2><i class="fas fa-lock"></i></div><p class="postal-note">Utiliza una contraseña larga y exclusiva para esta cuenta.</p>
            <form action="{{ route('password.update') }}" method="post">@csrf @method('put')
                @foreach(['current_password'=>'Contraseña actual', 'password'=>'Nueva contraseña', 'password_confirmation'=>'Confirmar nueva contraseña'] as $field=>$label)<div class="form-group"><label for="update_{{ $field }}">{{ $label }}</label><input class="form-control {{ $errors->updatePassword->has($field) ? 'is-invalid' : '' }}" id="update_{{ $field }}" type="password" name="{{ $field }}" autocomplete="{{ $field === 'current_password' ? 'current-password' : 'new-password' }}" required>@foreach($errors->updatePassword->get($field) as $message)<div class="invalid-feedback">{{ $message }}</div>@endforeach</div>@endforeach
                <div class="sitra-form-actions"><button class="btn btn-primary">Actualizar contraseña</button>@if(session('status') === 'password-updated')<span class="text-success" role="status">Contraseña actualizada.</span>@endif</div>
            </form>
        </section>
        <section class="postal-panel" id="profile-delete"><h2>Administrar cuenta</h2><p class="postal-note">La eliminación de tu cuenta es permanente. Conserva la información que necesites antes de continuar.</p><button class="btn btn-outline-danger" data-toggle="modal" data-target="#confirm-user-deletion">Eliminar mi cuenta</button></section>
    </div>
</div>
<div class="modal fade" id="confirm-user-deletion" tabindex="-1" aria-labelledby="deletion-title" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><form method="post" action="{{ route('profile.destroy') }}">@csrf @method('delete')<div class="modal-header"><h2 class="modal-title h5" id="deletion-title">¿Eliminar tu cuenta?</h2><button class="close" type="button" data-dismiss="modal" aria-label="Cerrar"><span aria-hidden="true">&times;</span></button></div><div class="modal-body"><p>Esta acción es permanente. Ingresa tu contraseña para confirmar la eliminación.</p><label for="deletion-password">Contraseña</label><input id="deletion-password" name="password" type="password" class="form-control {{ $errors->userDeletion->isNotEmpty() ? 'is-invalid' : '' }}" autocomplete="current-password" required>@foreach($errors->userDeletion->get('password') as $message)<div class="invalid-feedback">{{ $message }}</div>@endforeach</div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-dismiss="modal">Cancelar</button><button class="btn btn-danger">Eliminar cuenta</button></div></form></div></div></div>
@include('footer')
@stop
@section('js')
@if($errors->userDeletion->isNotEmpty())<script>$(function () { $('#confirm-user-deletion').modal('show'); });</script>@endif
@stop
