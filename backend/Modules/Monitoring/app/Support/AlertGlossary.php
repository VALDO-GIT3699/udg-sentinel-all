<?php

declare(strict_types=1);

namespace Modules\Monitoring\Support;

/**
 * Traduce los titulos tecnicos de alertas a explicaciones en lenguaje llano,
 * con un ejemplo concreto de lo que podria pasar si no se corrigen. Pensado
 * para lectores sin formacion tecnica (reporte ejecutivo, PDF institucional).
 */
final class AlertGlossary
{
    /**
     * @param  array<int, string>  $titles
     * @return array<int, array{title: string, explanation: string, consequence: string}>
     */
    public static function forTitles(array $titles): array
    {
        $entries = self::entries();
        $result = [];

        foreach (array_values(array_unique($titles)) as $title) {
            if (! isset($entries[$title])) {
                continue;
            }

            $result[] = array_merge(['title' => $title], $entries[$title]);
        }

        return $result;
    }

    /**
     * @return array<string, array{explanation: string, consequence: string}>
     */
    private static function entries(): array
    {
        return [
            'Sitio expuesto por cabeceras de seguridad' => [
                'explanation' => 'Al sitio le faltan unas instrucciones de seguridad que el navegador espera recibir junto con la página (se llaman "cabeceras" o "headers"). Es como enviar una carta sin sobre cerrado: cualquiera que la intercepte en el camino puede leerla o alterarla más fácil.',
                'consequence' => 'Por ejemplo: un atacante podría insertar código falso dentro de la página (algo llamado "clickjacking") para engañar a un usuario y hacerlo hacer clic en algo que no quería, o robar información que el usuario escribió en un formulario, sin que el sitio se dé cuenta.',
            ],
            'Incidente: sitio degradado' => [
                'explanation' => 'El sitio sigue respondiendo, pero de forma lenta o con errores parciales — como un elevador que funciona pero se detiene entre pisos de vez en cuando.',
                'consequence' => 'Los visitantes pueden abandonar la página antes de que cargue, o encontrarse con partes que no funcionan bien (imágenes que no cargan, formularios que fallan), lo que da una mala impresión del servicio.',
            ],
            'SSL requiere atencion' => [
                'explanation' => 'El certificado digital que garantiza que la conexión al sitio es privada y segura (el candado que aparece en el navegador) tiene un problema menor que conviene revisar pronto.',
                'consequence' => 'Si no se atiende, el problema puede crecer hasta que el navegador empiece a mostrar advertencias de "sitio no seguro" a los visitantes, lo que asusta a la gente y hace que no confíen en la página.',
            ],
            'SSL en estado critico' => [
                'explanation' => 'El certificado de seguridad del sitio (el "candado" del navegador) tiene un problema grave — puede estar mal configurado, ser inválido, o no coincidir con el sitio.',
                'consequence' => 'Los navegadores modernos (Chrome, Edge, Firefox) bloquean el acceso al sitio y muestran una pantalla roja de advertencia. Los visitantes no podrán entrar sin saltarse un aviso de peligro, y muchos simplemente se irán.',
            ],
            'SSL por vencer' => [
                'explanation' => 'El certificado de seguridad del sitio tiene fecha de caducidad, como una identificación oficial, y esa fecha se acerca.',
                'consequence' => 'Si se deja vencer, el sitio deja de tener el "candado" verde y los navegadores muestran una advertencia fuerte de "conexión no privada" — el sitio prácticamente deja de ser accesible para la mayoría de las personas hasta que se renueve.',
            ],
            'Incidente critico: sitio caido' => [
                'explanation' => 'El sitio no está respondiendo en absoluto — es como si alguien tocara la puerta de una oficina y nadie abriera, ni siquiera para decir "ahorita vuelvo".',
                'consequence' => 'Nadie puede usar el servicio mientras dure la caída: ni consultar información, ni hacer trámites, ni acceder a lo que ofrece el sitio. Cada minuto caído es un minuto sin servicio para toda la comunidad universitaria.',
            ],
        ];
    }
}
