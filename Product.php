<?php

namespace App;

use App\BaseModel;

class Product extends BaseModel
{
    protected $fillable = ['title', 'ima_product_id', 'content', 'wp_link', 'post_id', 'utm_content'];

    /**************************************
     *           Public methods           *
     **************************************/

    public function fillData($data, ...$args) {
        $this->fill($data);
        $this->image = '';

        if (!$this->save()) {
            throw new Exception('Failed to save product');
        }
    }

    public function fullData() {
        return $this;
    }

    /**************************************
     *          Validation Rules          *
     **************************************/

    public function validationRules(...$args) {
        return [
            'title' => 'required|string|max:300',
            'ima_product_id' => 'required|numeric',
            'content' => 'required|string',
            'wp_link' => 'required|string|max:300',
            'post_id' => 'required|numeric',
            'utm_content' => 'string|max:50',
        ];
    }
}
