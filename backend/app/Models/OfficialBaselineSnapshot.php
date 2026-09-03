<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class OfficialBaselineSnapshot extends Model
{
    use HasFactory;

    protected $fillable = [
        'source_name',
        'source_path',
        'source_type',
        'source_hash',
        'imported_by',
        'is_current',
        'total_rows',
        'unique_domains',
        'notes',
        'imported_at',
    ];

    protected $casts = [
        'is_current' => 'boolean',
        'total_rows' => 'integer',
        'unique_domains' => 'integer',
        'imported_at' => 'immutable_datetime',
    ];

    public function sites(): HasMany
    {
        return $this->hasMany(OfficialBaselineSite::class, 'snapshot_id');
    }

    public function importer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by');
    }
}
