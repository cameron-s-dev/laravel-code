<?php

namespace App;

use App\BaseModel;

class PlatformFilter extends BaseModel
{
    protected $fillable = ['active_desktop', 'active_mobile', 'active_ios', 'active_android'];
    protected $hidden = ['id', 'soi_id', 'linkout_id'];
    public $timestamps = false;

    /**************************************
     *           Public methods           *
     **************************************/

    public function getActiveDesktopAttribute($value) {
        return boolval($value);
    }

    public function getActiveMobileAttribute($value) {
        return boolval($value);
    }

    public function getActiveIosAttribute($value) {
        return boolval($value);
    }

    public function getActiveAndroidAttribute($value) {
        return boolval($value);
    }

    /**************************************
     *          Validation Rules          *
     **************************************/

    public function validationRules(...$args) {
        return [
            'active_desktop' => 'required|boolean',
            'active_mobile' => 'required|boolean',
            'active_ios' => 'required|boolean',
            'active_android' => 'required|boolean',
        ];
    }
}
