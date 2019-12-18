<?php

namespace App;

use DateTime;
use App\BaseModel;

class LeadUser extends BaseModel
{
    protected $fillable = ['first_name', 'last_name', 'zip_code', 'address', 'city', 'state', 'phone', 'gender', 'email', 'birthday'];
    public $timestamps = false;

    /**************************************
     *              Relations             *
     **************************************/

    public function lead() {
        return $this->belongsTo('App\LeadUser');
    }

    /**************************************
     *           Public methods           *
     **************************************/

    public function fillData($data, ...$args) {
        $this->birthday = new DateTime($data['birthday']);
        parent::fillData($data);
    }

    /**************************************
     *          Validation Rules          *
     **************************************/

    public function validationRules(...$args) {
        return [
            'first_name' => 'required|string|max:300',
            'last_name' => 'required|string|max:300',
            'zip_code' => 'required|string|max:100',
            'address' => 'required|string|max:300',
            'city' => 'required|string|max:300',
            'state' => 'required|string|max:300',
            'birthday' => 'required|string|max:300',
            'phone' => 'required|string|max:300',
            'gender' => 'required|string|max:300',
            'email' => 'required|string|max:300',
        ];
    }
}
