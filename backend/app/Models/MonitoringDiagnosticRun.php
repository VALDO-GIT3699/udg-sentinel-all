<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class MonitoringDiagnosticRun extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'status',
        'summary',
        'reason',
        'steps',
        'issues',
        'queue_before',
        'queue_after',
    ];

    protected $casts = [
        'steps' => 'array',
        'issues' => 'array',
        'queue_before' => 'array',
        'queue_after' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
