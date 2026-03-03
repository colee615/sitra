<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Permission extends Model
{
    
    static $rules = [
		'name' => 'required',
		'guard_name' => 'required',
    ];

    protected $perPage = 20;

    protected $fillable = ['name','guard_name'];

    public function modelHasPermission()
    {
        return $this->hasOne('App\Models\ModelHasPermission', 'permission_id', 'id');
    }

    public function roleHasPermission()
    {
        return $this->hasOne('App\Models\RoleHasPermission', 'permission_id', 'id');
    }
    

}
