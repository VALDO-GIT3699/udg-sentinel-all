// ============================================================
// UDG Sentinel — Tipos globales del sistema
// ============================================================

// ── Sitio ─────────────────────────────────────────────────
export type SiteStatus = 'up' | 'down' | 'degraded' | 'unknown'

export type ScoreLevel = 'excellent' | 'good' | 'medium' | 'low' | 'critical' | 'down'

export interface SiteGroup {
  id: number
  name: string
  slug: string
  description: string | null
  responsibleName: string | null
  responsibleEmail: string | null
  color: string
  sitesCount?: number
}

export interface Site {
  id: number
  siteGroupId: number
  siteGroup?: SiteGroup
  name: string
  slug: string
  domain: string
  url: string
  isActive: boolean
  isMonitored: boolean
  priority: 1 | 2 | 3
  currentStatus: SiteStatus
  currentScore: number
  currentScoreLevel: ScoreLevel
  lastCheckedAt: string | null
  checkIntervalMin: number
  notes: string | null
  tags: string[]
  createdAt: string
  updatedAt: string
}

// ── SSL ───────────────────────────────────────────────────
export interface SslCertificate {
  id: number
  siteId: number
  commonName: string | null
  issuer: string | null
  validFrom: string | null
  validUntil: string | null
  daysRemaining: number | null
  isValid: boolean
  isExpired: boolean
  algorithm: string | null
  lastCheckedAt: string | null
}

// ── Chequeo de sitio ──────────────────────────────────────
export interface SiteCheck {
  id: number
  siteId: number
  checkedAt: string
  status: 'up' | 'down' | 'degraded' | 'timeout'
  httpCode: number | null
  responseTimeMs: number | null
  errorMessage: string | null
}

// ── Score de seguridad ────────────────────────────────────
export interface SecurityScore {
  id: number
  siteId: number
  score: number
  level: ScoreLevel
  calculatedAt: string
  breakdown: Record<string, { points: number; reason: string }>
  recommendations: string[]
}

// ── Vulnerabilidad ────────────────────────────────────────
export type VulnerabilitySeverity = 'critical' | 'high' | 'medium' | 'low' | 'info'

export interface Vulnerability {
  id: number
  siteId: number
  title: string
  description: string | null
  severity: VulnerabilitySeverity
  category: string | null
  cveId: string | null
  affectedComponent: string | null
  affectedVersion: string | null
  remediation: string | null
  isActive: boolean
  detectedAt: string
  resolvedAt: string | null
}

// ── Alerta ────────────────────────────────────────────────
export type AlertStatus = 'open' | 'acknowledged' | 'resolved'
export type AlertSeverity = 'critical' | 'high' | 'medium' | 'low'

export interface Alert {
  id: number
  siteId: number | null
  title: string
  message: string | null
  severity: AlertSeverity
  status: AlertStatus
  triggeredAt: string
  acknowledgedAt: string | null
  resolvedAt: string | null
  context: Record<string, unknown>
}

// ── Evento del sitio (línea del tiempo) ──────────────────
export interface SiteEvent {
  id: number
  siteId: number
  eventType: string
  title: string
  description: string | null
  severity: 'info' | 'warning' | 'error' | 'critical'
  metadata: Record<string, unknown>
  occurredAt: string
  createdBy: number | null
}

// ── Tecnología detectada ──────────────────────────────────
export interface CmsDetail {
  id: number
  siteId: number
  cmsType: 'drupal' | 'wordpress' | 'laravel' | 'joomla' | 'other' | null
  cmsVersion: string | null
  dbType: string | null
  dbVersion: string | null
  phpVersion: string | null
  phpIsVulnerable: boolean
  serverSoftware: string | null
  themeName: string | null
  themeVersion: string | null
  modulesCount: number
  hasUpdates: boolean
  hasSecurityUpdates: boolean
  lastScannedAt: string | null
}

// ── Usuario autenticado ───────────────────────────────────
export interface AuthUser {
  id: number
  name: string
  email: string
  avatar: string | null
  department: string | null
  roles: string[]
  permissions: string[]
}

// ── Paginación Laravel ────────────────────────────────────
export interface PaginatedResponse<T> {
  data: T[]
  current_page: number
  last_page: number
  per_page: number
  total: number
  from: number | null
  to: number | null
  links: Array<{
    url: string | null
    label: string
    active: boolean
  }>
}

// ── Props compartidas por Inertia (shared) ────────────────
export interface SharedProps {
  auth: {
    user: AuthUser
  }
  flash: {
    success?: string
    error?: string
    warning?: string
    info?: string
  }
  errors: Record<string, string>
}

// ── Métricas del dashboard ────────────────────────────────
export interface DashboardMetrics {
  totalSites: number
  sitesUp: number
  sitesDown: number
  sitesDegraded: number
  sitesUnknown: number
  sslExpiringSoon: number
  sslExpired: number
  openAlerts: number
  criticalAlerts: number
  averageScore: number
  sitesWithUpdates: number
}
