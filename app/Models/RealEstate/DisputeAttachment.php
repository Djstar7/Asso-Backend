<?php

namespace App\Models\RealEstate;

use Illuminate\Database\Eloquent\Model;

class DisputeAttachment extends Model
{
    protected $table = 'real_estate_dispute_attachments';

    protected $fillable = ['dispute_id', 'author', 'path'];
}
