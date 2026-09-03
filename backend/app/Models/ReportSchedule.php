<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

final class ReportSchedule extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'report_type',
        'scope',
        'scope_id',
        'frequency',
        'delivery_channels',
        'is_active',
        'last_run_at',
        'next_run_at',
    ];

    protected $casts = [
        'scope_id' => 'integer',
        'delivery_channels' => 'array',
        'is_active' => 'boolean',
        'last_run_at' => 'immutable_datetime',
        'next_run_at' => 'immutable_datetime',
    ];

    // -----------------------------------------------------------------
    // Scopes
    // -----------------------------------------------------------------

    /**
     * @param  Builder<ReportSchedule>  $query
     * @return Builder<ReportSchedule>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @param  Builder<ReportSchedule>  $query
     * @return Builder<ReportSchedule>
     */
    public function scopeDue(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->where(function ($q): void {
                $q->whereNull('next_run_at')
                    ->orWhere('next_run_at', '<=', now());
            });
    }
}
