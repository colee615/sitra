<x-app-layout>
    <x-slot name="header">
        <div>
            <p class="mb-1 text-xs font-bold uppercase tracking-[0.14em] text-teal-700">Cuenta</p>
            <h1 class="text-2xl font-bold leading-tight text-slate-900">Mi perfil</h1>
            <p class="mt-1 text-sm text-slate-600">Administra tus datos personales y la seguridad de tu cuenta.</p>
        </div>
    </x-slot>

    <div class="px-4 py-7 sm:px-6 lg:px-8">
        <div class="sitra-profile-page space-y-5">
            <div class="sitra-profile-card p-5 sm:p-7">
                <div class="max-w-2xl">
                    @include('profile.partials.update-profile-information-form')
                </div>
            </div>

            <div class="sitra-profile-card p-5 sm:p-7">
                <div class="max-w-2xl">
                    @include('profile.partials.update-password-form')
                </div>
            </div>

            <div class="sitra-profile-card p-5 sm:p-7">
                <div class="max-w-2xl">
                    @include('profile.partials.delete-user-form')
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
