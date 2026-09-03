<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class Report extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'type',
        'scope',
        'scope_id',
        'generated_by',
        'period_start',
        'period_end',
        'file_path',
        'status',
    ];

    protected $casts = [
        'scope_id' => 'integer',
        'period_start' => 'date',
        'period_end' => 'date',
    ];

    // -----------------------------------------------------------------
    // Relations
    // -----------------------------------------------------------------

    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    // -----------------------------------------------------------------
    // Scopes
    // -----------------------------------------------------------------

    /**
     * @param  Builder<Report>  $query
     * @return Builder<Report>
     */
    public function scopeReady(Builder $query): Builder
    {
        return $query->where('status', 'ready');
    }

    /**
     * @param  Builder<Report>  $query
     * @return Builder<Report>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    public function isReady(): bool
    {
        return $this->status === 'ready';
    }

    public function hasFile(): bool
    {
        return $this->file_path !== null;
    }
}
