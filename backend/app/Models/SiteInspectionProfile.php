<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class SiteInspectionProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'site_id',
        'scan_run_id',
        'analysis_version',
        'dns_status',
        'dns_error',
        'dns_records',
        'http_status',
        'https_status',
        'redirect_chain',
        'response_time_ms',
        'ttfb_ms',
        'ssl_status',
        'ssl_error',
        'ssl_payload',
        'security_headers_status',
        'security_headers_payload',
        'body_status',
        'body_error',
        'fingerprint_status',
        'fingerprint_payload',
        'cms_name',
        'cms_version',
        'cms_confidence',
        'server_signature',
        'runtime_name',
        'runtime_version',
        'js_frameworks',
        'risk_score',
        'risk_level',
        'essential_checks_complete',
        'analysis_errors',
        'inspected_at',
        'scan_interrupted_at',
    ];

    protected $casts = [
        'dns_records' => 'array',
        'redirect_chain' => 'array',
        'ssl_payload' => 'array',
        'security_headers_payload' => 'array',
        'fingerprint_payload' => 'array',
        'js_frameworks' => 'array',
        'analysis_errors' => 'array',
        'essential_checks_complete' => 'boolean',
        'response_time_ms' => 'integer',
        'ttfb_ms' => 'integer',
        'risk_score' => 'integer',
        'inspected_at' => 'immutable_datetime',
        'scan_interrupted_at' => 'immutable_datetime',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
