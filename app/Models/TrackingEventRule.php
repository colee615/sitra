<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TrackingEventRule extends Model
{
    protected $fillable = [
        'source_db',
        'event_type_cd',
        'raw_name',
        'display_name',
        'is_visible',
        'append_source_context',
        'sort_order',
        'notes',
    ];

    protected $casts = [
        'event_type_cd' => 'integer',
        'is_visible' => 'boolean',
        'append_source_context' => 'boolean',
        'sort_order' => 'integer',
    ];
}
