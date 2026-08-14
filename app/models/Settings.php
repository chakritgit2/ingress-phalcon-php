<?php

namespace App\Models;

use Phalcon\Mvc\Model;

class Settings extends Model
{
    public string $setting_key;
    public string $setting_value;
    public ?string $updated_at = null;

    public function initialize(): void
    {
        $this->setSource('settings');
    }
}
