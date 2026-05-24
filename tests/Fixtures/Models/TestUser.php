<?php

namespace AleMian95\Datatable\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TestUser extends Model
{
    protected $table = 'test_users';

    protected $guarded = [];

    public $timestamps = true;

    public function posts(): HasMany
    {
        return $this->hasMany(TestPost::class, 'test_user_id');
    }
}
