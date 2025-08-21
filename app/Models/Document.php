<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class Document extends Model
{
    use HasFactory;

    protected $primaryKey = 'pk_document_id';

    protected $fillable = [
        'source_id',
        'source_type',
        'document_type',
        'reg_date',
        'document_no',
        'name',
        'version_no',
        'size_kb',
        'ext',
        'original_name',
        'path',
        'has_expired',
        'expired_date',
        'storage',
        's3_key',
        's3_bucket',
        'status',
        'created_date',
        'created_by',
    ];

    protected $casts = [
        'reg_date' => 'datetime',
        'expired_date' => 'datetime',
        'has_expired' => 'boolean',
        'created_date' => 'datetime',
    ];

    // helper to get url
    public function url()
    {
        if ($this->storage === 's3' && $this->s3_key) {
            return Storage::disk('s3')->url($this->s3_key);
        }
        if ($this->path) {
            return url('/storage/'.ltrim($this->path, '/'));
        }
        return null;
    }
}
