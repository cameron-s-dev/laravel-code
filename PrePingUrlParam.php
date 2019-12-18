<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class PrePingUrlParam extends BaseModel
{
    protected $fillable = ['field', 'client_field', 'format_or_value', 'url_encode'];
    public $timestamps = false;

    /**************************************
     *              Relations             *
     **************************************/

    public function prePingConfig() {
        return $this->belongsTo('App\PrePingConfig');
    }

    /**************************************
     *          Validation Rules          *
     **************************************/

    public function validationRules(...$args) {
        return [
            'field' => 'string|max:50',
            'client_field' => 'string|max:50',
            // 'format_or_value' => 'string|max:100',
            'url_encode' => 'boolean',
        ];
    }
}
