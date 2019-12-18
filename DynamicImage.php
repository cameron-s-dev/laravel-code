<?php

namespace App;

use App\BaseModel;
use Illuminate\Support\Facades\Log;
use App\Traits\ImageUploadable;
use Storage;

class DynamicImage extends BaseModel
{
    use ImageUploadable;

    static protected $folderName = 'dynamic-images';

    protected $fillable = ['utm_content'];

    /**************************************
     *           Public methods           *
     **************************************/

    public function fillData($data, ...$args) {
        if (empty($this->image)) {
            $this->image = '';
        }
        parent::fillData($data);

        if (!empty($data['image_data'])) {
            if ($this->image) {
                try {
                    $this->deleteCreative($this->image);
                } catch (Exception $e) {
                    report($e);
                }
            }
            $imageUrl = $this->uploadCreative($data['image_data'], $this->utm_content);
            $this->image = $imageUrl;
        }

        if (!$this->save()) {
            throw new Exception('Failed to save dynamic image url');
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
            'utm_content' => 'required|string|max:50',
        ];
    }
}
