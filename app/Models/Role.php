<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    
    static $rules = [
		'name' => 'required',
		'guard_name' => 'required',
    ];

    protected $perPage = 20;

    protected $fillable = ['name','guard_name'];

    public function modelHasRole()
    {
        return $this->hasOne('App\Models\ModelHasRole', 'role_id', 'id');
    }
    
    public function roleHasPermissions()
    {
        return $this->hasMany('App\Models\RoleHasPermission', 'role_id', 'id');
    }
    

}
