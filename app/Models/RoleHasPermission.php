<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RoleHasPermission extends Model
{
    
    static $rules = [
		'permission_id' => 'required',
		'role_id' => 'required',
    ];

    protected $perPage = 20;

    protected $fillable = ['permission_id','role_id'];

    public function permission()
    {
        return $this->hasOne('App\Models\Permission', 'id', 'permission_id');
    }

    public function role()
    {
        return $this->hasOne('App\Models\Role', 'id', 'role_id');
    }
    
    public $timestamps = false;
}
