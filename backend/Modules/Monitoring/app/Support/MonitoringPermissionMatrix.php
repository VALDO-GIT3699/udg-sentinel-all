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
            'monitoring.delete_sites',
            'monitoring.run_mass_scan',
            'monitoring.manage_comments',
            'monitoring.manage_users',
            'monitoring.view_audit_log',
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
            'monitoring.run_mass_scan',
            'monitoring.manage_comments',
        ];
    }

    /**
     * Etiquetas en español para el panel de administración: permite mostrar
     * checkboxes entendibles sin que el admin tenga que leer el slug tecnico.
     *
     * @return array<string, string>
     */
    public static function permissionLabels(): array
    {
        return [
            'monitoring.view_dashboard' => 'Ver el dashboard',
            'monitoring.view_site_detail' => 'Ver el detalle de cada sitio',
            'monitoring.manage_sites' => 'Agregar y editar sitios',
            'monitoring.manage_groups' => 'Administrar grupos de sitios',
            'monitoring.manage_alerts' => 'Administrar alertas',
            'monitoring.manage_settings' => 'Administrar configuración del sistema',
            'monitoring.view_horizon' => 'Ver colas de trabajo (Horizon)',
            'monitoring.delete_sites' => 'Eliminar sitios',
            'monitoring.run_mass_scan' => 'Ejecutar escaneo masivo',
            'monitoring.manage_comments' => 'Agregar, editar y eliminar comentarios',
            'monitoring.manage_users' => 'Administrar usuarios y permisos',
            'monitoring.view_audit_log' => 'Ver el registro de auditoría',
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

    /**
     * @return array<string, string>
     */
    public static function roleLabels(): array
    {
        return [
            self::ADMIN_ROLE => 'Administrador',
            self::OPERATOR_ROLE => 'Operador',
            self::VIEWER_ROLE => 'Visualizador',
        ];
    }

    /**
     * Preset de permisos por rol, usado como punto de partida al crear un
     * usuario (el admin puede personalizarlo por-usuario despues).
     *
     * @return list<string>
     */
    public static function defaultPermissionsForRole(string $role): array
    {
        return match ($role) {
            self::ADMIN_ROLE => self::adminPermissions(),
            self::OPERATOR_ROLE => self::operatorPermissions(),
            self::VIEWER_ROLE => self::viewerPermissions(),
            default => [],
        };
    }
}
