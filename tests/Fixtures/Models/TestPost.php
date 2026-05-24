<?php

namespace AleMian95\Datatable\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TestPost extends Model
{
    protected $table = 'test_posts';

    protected $guarded = [];

    public $timestamps = true;

    public function author(): BelongsTo
    {
        return $this->belongsTo(TestUser::class, 'test_user_id');
    }
}
