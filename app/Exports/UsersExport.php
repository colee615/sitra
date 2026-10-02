<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithHeadings;
use PhpOffice\PhpSpreadsheet\Cell\StringValueBinder;

class UsersExport extends StringValueBinder implements FromArray, WithCustomValueBinder, WithHeadings
{
    public function __construct(private Collection $users) {}

    public function headings(): array
    {
        return ['Nombre', 'Correo electrónico', 'Estado', 'Roles'];
    }

    public function array(): array
    {
        return $this->users->map(fn ($user) => [
            $user->name, $user->email, $user->trashed() ? 'Inactivo' : 'Activo', $user->roles->pluck('name')->implode(', '),
        ])->all();
    }
}
