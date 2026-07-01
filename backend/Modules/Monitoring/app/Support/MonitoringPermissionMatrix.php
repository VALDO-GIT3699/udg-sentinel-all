<?php

declare(strict_types=1);

namespace Modules\Monitoring\Support;

final class MonitoringPermissionMatrix
{
    public const ADMIN_ROLE = 'monitoring-admin';

    public const OPERATOR_ROLE = 'monitoring-operator';

    public const VIEWER_ROLE = 'monitoring-viewer';

    /**
     * @return list<string>
     */
    public static function allPermissions(): array
    {
        return [
            'monitoring.view_dashboard',
            'monitoring.view_site_detail',
            'monitoring.manage_sites',
            'monitoring.manage_groups',
            'monitoring.manage_alerts',
            'monitoring.manage_settings',
            'monitoring.view_horizon',
        ];
    }

    /**
     * @return list<string>
     */
    public static function adminPermissions(): array
    {
        return self::allPermissions();
    }

    /**
     * @return list<string>
     */
    public static function operatorPermissions(): array
    {
        return [
            'monitoring.view_dashboard',
            'monitoring.view_site_detail',
            'monitoring.manage_sites',
            'monitoring.manage_groups',
            'monitoring.manage_alerts',
        ];
    }

    /**
     * @return list<string>
     */
    public static function viewerPermissions(): array
    {
        return [
            'monitoring.view_dashboard',
            'monitoring.view_site_detail',
        ];
    }
}
