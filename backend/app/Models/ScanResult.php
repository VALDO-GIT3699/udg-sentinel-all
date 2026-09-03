<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class ScanResult extends Model
{
    use HasFactory;

    protected $fillable = [
        'site_id',
        'scan_type',
        'started_at',
        'completed_at',
        'status',
        'findings_count',
        'critical_count',
        'high_count',
        'medium_count',
        'low_count',
        'raw_output',
    ];

    protected $casts = [
        'started_at' => 'immutable_datetime',
        'completed_at' => 'immutable_datetime',
        'findings_count' => 'integer',
        'critical_count' => 'integer',
        'high_count' => 'integer',
        'medium_count' => 'integer',
        'low_count' => 'integer',
        'raw_output' => 'array',
    ];

    // -----------------------------------------------------------------
    // Relations
    // -----------------------------------------------------------------

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function vulnerabilities(): HasMany
    {
        return $this->hasMany(Vulnerability::class);
    }

    // -----------------------------------------------------------------
    // Scopes
    // -----------------------------------------------------------------

    /**
     * @param  Builder<ScanResult>  $query
     * @return Builder<ScanResult>
     */
    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', 'completed');
    }

    /**
     * @param  Builder<ScanResult>  $query
     * @return Builder<ScanResult>
     */
    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('scan_type', $type);
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function hasCriticalFindings(): bool
    {
        return $this->critical_count > 0;
    }

    public function getDurationSeconds(): ?int
    {
        if ($this->completed_at === null || $this->started_at === null) {
            return null;
        }

        return (int) $this->completed_at->diffInSeconds($this->started_at);
    }
}
