# UDG Sentinel – Solicitud de Implementación (Spec Kit)

## Objetivo

Quiero que actúes como un **Software Architect**, **Senior Laravel Developer**, **Senior DevOps Engineer**, **Senior Security Engineer**, **QA Engineer** y **Technical Writer** al mismo tiempo.

No eres únicamente un generador de código.

Tu trabajo consiste en evolucionar UDG Sentinel como si fuera un sistema institucional que permanecerá en producción durante muchos años.

La prioridad absoluta del proyecto es:

> **La confiabilidad de la información por encima de la cantidad de información obtenida.**

Nunca debes sacrificar precisión por velocidad.

Si alguna información no puede demostrarse técnicamente, deberá mostrarse como:

> **No determinado**

antes que devolver un dato incorrecto.

---

# REGLAS OBLIGATORIAS

Estas reglas tienen prioridad sobre cualquier otra instrucción.

## Está estrictamente prohibido

* Inventar tecnologías.
* Inferir versiones sin evidencia.
* Adivinar CMS.
* Mostrar datos cuya fuente no sea verificable.
* Ocultar errores mediante valores por defecto.
* Generar falsos positivos.
* Generar falsos negativos por malas heurísticas.
* Duplicar lógica.
* Romper compatibilidad con funcionalidades existentes.
* Modificar arquitectura sin analizar previamente su impacto.

Si existe alguna duda o ambigüedad, detente y documenta el problema antes de continuar.

Nunca asumas información.

Nunca improvises.

---

# FASE 1 — ANÁLISIS

Antes de modificar un solo archivo deberás:

* Analizar completamente la arquitectura actual.
* Entender el flujo completo del sistema.
* Identificar dependencias.
* Identificar riesgos.
* Detectar código duplicado.
* Detectar posibles mejoras arquitectónicas.
* Revisar Jobs.
* Revisar Queues.
* Revisar Eventos.
* Revisar Servicios.
* Revisar Repositorios.
* Revisar DTOs.
* Revisar Migraciones.
* Revisar Seeders.
* Revisar documentación existente.
* Revisar archivos .md.
* Revisar tareas pendientes.

No debes comenzar a programar hasta comprender completamente la arquitectura existente.

Toda modificación deberá ser incremental.

---

# CALIDAD DEL CÓDIGO

Todo el proyecto deberá cumplir con:

* SOLID
* Clean Architecture
* Repository Pattern
* Service Layer
* DTOs
* Dependency Injection
* Events
* Listeners
* Value Objects donde aplique
* PSR-12
* Tipado estricto
* Código autodocumentado
* Alta cohesión
* Bajo acoplamiento

No se permite:

* Código duplicado.
* Controladores enormes.
* Consultas N+1.
* Lógica de negocio en Controllers.
* Código temporal.
* Comentarios innecesarios.
* Hacks.

---

# CAMBIO 1

## Nueva fuente base de verdad

Actualmente el sistema monitorea 127 sitios.

Existe un nuevo archivo:

/docs/right_sites/true_sites.csv

Contiene aproximadamente 291 sitios.

Este archivo NO está completamente actualizado al día de hoy, pero su información tiene menos de un mes de antigüedad.

Debe convertirse en la:

# BASELINE OF TRUTH

No deberá utilizarse únicamente como Seeder.

Debe convertirse en la referencia oficial contra la cual el sistema compare los resultados del escaneo.

El CSV deberá importarse mediante Seeders únicamente para crear la línea base inicial.

Posteriormente el sistema deberá ser completamente autónomo.

Toda la información útil contenida dentro del CSV deberá aprovecharse.

---

# CAMBIO 2

## Drift Detection

Después de cada escaneo el sistema deberá comparar los resultados detectados contra la línea base proveniente del CSV.

Si existe una diferencia significativa deberá generar una alerta.

Ejemplo:

CSV:

Drupal 9

Escaneo:

WordPress

Resultado:

Alerta:

"Incongruencia con Base Real"

Nunca deberá modificar automáticamente la línea base.

Siempre requerirá validación humana.

---

# CAMBIO 3

## Rediseño del Dashboard

Las gráficas deberán colocarse inmediatamente después de los indicadores principales.

Rediseñar completamente la sección de certificados próximos a vencer.

Mostrar únicamente los 5 certificados más urgentes.

Cada registro deberá mostrar:

* Dominio
* Fecha de expiración
* Días restantes

Agregar botón:

"Ver más"

para desplegar el resto.

---

# CAMBIO 4

## Estado de cada sitio

Agregar una columna llamada:

Estatus

Opciones:

* 1ra Etapa
* 2da Etapa
* Migrando
* Migrado
* Eliminado
* Solicitud de baja
* Migrado y publicado
* N/A
* Migración de CMS
* Sistema
* Otro

Cada estado deberá tener un color consistente y accesible.

Prioridad visual:

Verde

↓

Azul

↓

Amarillo

↓

Naranja

↓

Rojo

Siendo:

Migrado y publicado

el menos crítico.

Eliminado

el más crítico.

Si el estado es:

Eliminado

deberá habilitarse un campo obligatorio:

Número de Ticket.

Para cualquier otro estado dicho campo deberá permanecer oculto.

---

# CAMBIO 5

## Comentarios por sitio

Dentro del detalle de cada sitio agregar un panel independiente para comentarios.

Cada comentario deberá almacenar:

* Usuario
* Fecha
* Hora
* Texto
* Estado
* Visibilidad

Estados:

* Completada
* Incompleta
* Cancelada

Cada uno con su color correspondiente.

Cada comentario podrá:

* ocultarse
* eliminarse
* editarse

Manteniendo historial cuando corresponda.

---

# CAMBIO 6

## Escaneo

Eliminar completamente la ejecución automática.

El sistema únicamente podrá ejecutarse mediante:

* Iniciar Escaneo Masivo
* Escanear Seleccionados

No deberá existir ningún Scheduler que lance escaneos automáticamente.

---

# CAMBIO 7

## Rediseño del motor de detección

El sistema actual presenta errores al identificar tecnologías y versiones.

Debe rediseñarse completamente.

Pipeline sugerido:

Discovery

↓

HTTP

↓

Headers

↓

Cookies

↓

Redirects

↓

TLS

↓

SSL

↓

HTML

↓

Meta Tags

↓

Generator

↓

JavaScript

↓

Assets

↓

Framework Detection

↓

CMS Detection

↓

Version Validation

↓

Cross Validation

↓

Confidence Score

↓

Persistencia

Nunca confiar en una única evidencia.

Toda tecnología deberá estar respaldada por múltiples evidencias independientes.

Si no puede verificarse:

"No determinado"

---

# CAMBIO 8

## Confidence Score

Cada tecnología detectada deberá mostrar un porcentaje de confianza.

Además deberá registrar las evidencias utilizadas para llegar a dicha conclusión.

No deberán mostrarse versiones con baja confianza como si fueran definitivas.

---

# CAMBIO 9

## Información del entorno

Cuando sea posible detectar información del entorno deberá registrarse:

* PHP
* Versión PHP
* Versiones compatibles
* Base de datos
* Versión Base de datos
* Servidor Web
* Framework
* CMS

Nunca inventar datos.

---

# CAMBIO 10

## Ciclo de vida

Después de detectar una versión el sistema deberá calcular:

* Días restantes de soporte
* Fecha de fin de soporte
* Estado

No utilizar fechas hardcodeadas.

Crear una tabla de ciclo de vida para cada tecnología.

Debe poder actualizarse fácilmente.

---

# CAMBIO 11

## Seguridad

Fortalecer completamente el sistema.

Implementar buenas prácticas de producción.

Incluyendo entre otras:

* Rate Limiting
* CSRF
* CSP
* HSTS
* Cookies Seguras
* HttpOnly
* SameSite
* Políticas de contraseña
* Bloqueo por intentos fallidos
* Historial de sesiones
* Auditoría
* Policies
* Gates
* RBAC
* Protección contra escalación de privilegios

---

# CAMBIO 12

## Auto mantenimiento

Eliminar basura del sistema mediante una herramienta administrativa oculta.

Activación:

Ctrl + Shift + M

Solo administradores.

Acciones:

* Limpiar cachés.
* Optimizar cachés.
* Eliminar logs de escaneos mayores a tres meses.
* Eliminar temporales.
* Optimizar índices.
* Limpiar archivos huérfanos.
* Ejecutar tareas seguras de mantenimiento.

Nunca eliminar información importante.

Registrar auditoría completa.

---

# CAMBIO 13

## Agregar nuevos sitios

Agregar funcionalidad para registrar nuevos sitios.

Proceso:

Usuario escribe URL

↓

Sistema analiza

↓

Obtiene información

↓

Registra sitio

Antes de registrarlo deberá solicitar la palabra clave:

CGTA

Si no coincide:

Cancelar operación.

---

# CAMBIO 14

## Historial

El sistema deberá conservar historial de escaneos.

El usuario podrá eliminar historiales antiguos cuando así lo decida.

---

# CAMBIO 15

## Reportes

Al finalizar un escaneo masivo deberá poder generar:

* PDF Ejecutivo
* Excel

Incluyendo:

* Sitios escaneados
* Tecnologías detectadas
* Cambios
* Alertas
* SSL
* Versiones
* Sitios modificados
* Estadísticas

---

# CAMBIO 16

## Filtros avanzados

Permitir combinar múltiples filtros.

Ejemplo:

Estado = "2da Etapa"

AND

PHP = 7.4

AND

SSL < 30 días

AND

Drupal 9

---

# CAMBIO 17

## Telemetría

Agregar una sección administrativa discreta que muestre:

* Jobs pendientes
* Jobs ejecutándose
* Jobs fallidos
* Tiempo promedio por sitio
* Tiempo promedio por escaneo
* Uso de memoria
* Uso de CPU (si es posible)
* Estado de Redis
* Estado de Queue
* Último escaneo
* Último mantenimiento
* Próximo mantenimiento sugerido

Esta sección deberá integrarse con el módulo de mantenimiento y no estar visible para usuarios sin privilegios.

---

# DOCUMENTACIÓN

Toda modificación deberá reflejarse en los archivos correspondientes:

* Arquitectura
* Contexto
* Planeación
* Roadmap
* Tareas
* Cambios
* Decisiones Técnicas
* Base de Datos
* API
* Seguridad
* Deployment

No dejar documentación desactualizada.

---

# RESULTADO ESPERADO

No quiero únicamente código funcionando.

Quiero una evolución arquitectónica del proyecto.

Cada cambio deberá:

* ser escalable,
* mantenible,
* seguro,
* desacoplado,
* documentado,
* fácilmente extensible,
* preparado para producción,
* preparado para soportar cientos o miles de sitios en el futuro.

La calidad del diseño es tan importante como la funcionalidad.

Antes de implementar cualquier cambio, verifica que no rompa funcionalidades existentes, que mantenga compatibilidad hacia atrás cuando sea posible y que todo cambio esté respaldado por una justificación técnica sólida. La confiabilidad, la trazabilidad y la mantenibilidad del sistema son los criterios principales para aceptar cualquier implementación.
