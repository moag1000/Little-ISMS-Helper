<?php

namespace App\Service;

use App\Repository\AuditLogRepository;
use App\Repository\CryptographicOperationRepository;
use App\Repository\IncidentRepository;
use App\Repository\PhysicalAccessLogRepository;
use App\Repository\ThreatIntelligenceRepository;

/**
 * SIEM Export Service
 *
 * Exports security events in SIEM-compatible formats (CEF, JSON, Syslog)
 * Supports integration with enterprise SIEM solutions for ISO 27001 compliance
 */
class SiemExportService
{
    public function __construct(
        private AuditLogRepository $auditLogRepository,
        private IncidentRepository $incidentRepository,
        private CryptographicOperationRepository $cryptoRepository,
        private ThreatIntelligenceRepository $threatRepository,
        private PhysicalAccessLogRepository $physicalAccessRepository
    ) {}

    /**
     * Export events in Common Event Format (CEF)
     * Used by ArcSight, QRadar, Splunk and other SIEM solutions
     */
    public function exportToCef(string $eventType, ?\DateTime $startDate = null, ?\DateTime $endDate = null): array
    {
        $events = $this->getEvents($eventType, $startDate, $endDate);
        $cefEvents = [];

        foreach ($events as $event) {
            $cefEvents[] = $this->convertToCef($event, $eventType);
        }

        return $cefEvents;
    }

    /**
     * Export events in JSON format
     * Universal format for modern SIEM solutions
     */
    public function exportToJson(string $eventType, ?\DateTime $startDate = null, ?\DateTime $endDate = null): string
    {
        $events = $this->getEvents($eventType, $startDate, $endDate);
        $jsonEvents = [];

        foreach ($events as $event) {
            $jsonEvents[] = $this->convertToJsonEvent($event, $eventType);
        }

        return json_encode([
            'events' => $jsonEvents,
            'event_type' => $eventType,
            'export_timestamp' => (new \DateTime())->format('c'),
            'total_events' => count($jsonEvents),
            'date_range' => [
                'start' => $startDate?->format('c'),
                'end' => $endDate?->format('c'),
            ]
        ], JSON_PRETTY_PRINT);
    }

    /**
     * Export events in Syslog format (RFC 5424)
     */
    public function exportToSyslog(string $eventType, ?\DateTime $startDate = null, ?\DateTime $endDate = null): array
    {
        $events = $this->getEvents($eventType, $startDate, $endDate);
        $syslogEvents = [];

        foreach ($events as $event) {
            $syslogEvents[] = $this->convertToSyslog($event, $eventType);
        }

        return $syslogEvents;
    }

    /**
     * Get aggregated security statistics for SIEM dashboards
     */
    public function getSecurityStatistics(?\DateTime $startDate = null, ?\DateTime $endDate = null): array
    {
        return [
            'timestamp' => (new \DateTime())->format('c'),
            'period' => [
                'start' => $startDate?->format('c') ?? 'all_time',
                'end' => $endDate?->format('c') ?? 'now',
            ],
            'incidents' => [
                'total' => count($this->incidentRepository->findAll()),
                'critical' => count($this->incidentRepository->findBy(['severity' => 'critical'])),
                'high' => count($this->incidentRepository->findBy(['severity' => 'high'])),
                'open' => count($this->incidentRepository->findBy(['status' => 'open'])),
            ],
            'threats' => $this->threatRepository->getStatistics(),
            'cryptographic_operations' => $this->cryptoRepository->getStatistics($startDate, $endDate),
            'physical_access' => $this->physicalAccessRepository->getStatistics($startDate, $endDate),
            'audit_events' => [
                'total' => count($this->auditLogRepository->findAll()),
            ],
        ];
    }

    /**
     * Get events by type
     */
    private function getEvents(string $eventType, ?\DateTime $startDate = null, ?\DateTime $endDate = null): array
    {
        return match ($eventType) {
            'incidents' => $this->incidentRepository->findAll(),
            'threats' => $this->threatRepository->findAll(),
            'crypto_operations' => $this->cryptoRepository->findAll(),
            'physical_access' => $this->physicalAccessRepository->findAll(),
            'audit_logs' => $this->auditLogRepository->findAll(),
            'security_incidents' => array_merge(
                $this->incidentRepository->findBy(['severity' => ['critical', 'high']]),
                $this->physicalAccessRepository->findRecentSecurityIncidents(30)
            ),
            default => [],
        };
    }

    /**
     * Convert event to CEF format
     * Format: CEF:Version|Device Vendor|Device Product|Device Version|Signature ID|Name|Severity|Extension
     */
    private function convertToCef($event, string $eventType): string
    {
        $cefVersion = 0;
        $vendor = 'Little-ISMS-Helper';
        $product = 'ISMS';
        $version = '1.0';

        [$signatureId, $name, $severity, $extensions] = match ($eventType) {
            'incidents' => $this->buildCefFromIncident($event),
            'threats' => $this->buildCefFromThreat($event),
            'crypto_operations' => $this->buildCefFromCrypto($event),
            'physical_access' => $this->buildCefFromPhysicalAccess($event),
            'audit_logs' => $this->buildCefFromAuditLog($event),
            default => ['UNKNOWN', 'Unknown Event', 0, ''],
        };

        return sprintf(
            'CEF:%d|%s|%s|%s|%s|%s|%d|%s',
            $cefVersion,
            $vendor,
            $product,
            $version,
            $signatureId,
            $name,
            $severity,
            $extensions
        );
    }

    /**
     * Build CEF format from incident
     */
    private function buildCefFromIncident($incident): array
    {
        $severityMap = ['critical' => 10, 'high' => 8, 'medium' => 5, 'low' => 3, 'info' => 1];
        $severity = $severityMap[$incident->getSeverity()] ?? 0;

        $extensions = sprintf(
            'src=%s suser=%s cat=%s cs1=%s cs1Label=IncidentType cs2=%s cs2Label=Status rt=%s',
            $incident->getSource() ?? 'unknown',
            $incident->getReportedBy() ?? 'system',
            $incident->getCategory() ?? 'security',
            $incident->getIncidentType() ?? 'general',
            $incident->getStatus() ?? 'open',
            $incident->getDetectedAt()?->format('c') ?? ''
        );

        return [
            'INCIDENT-' . $incident->getId(),
            $this->sanitizeCefField($incident->getTitle() ?? 'Security Incident'),
            $severity,
            $extensions
        ];
    }

    /**
     * Build CEF format from threat intelligence
     */
    private function buildCefFromThreat($threat): array
    {
        $severityMap = ['critical' => 10, 'high' => 8, 'medium' => 5, 'low' => 3, 'info' => 1];
        $severity = $severityMap[$threat->getSeverity()] ?? 0;

        $extensions = sprintf(
            'cat=%s cs1=%s cs1Label=CVE cs2=%s cs2Label=Status cn1=%s cn1Label=CVSS rt=%s',
            $threat->getThreatType() ?? 'unknown',
            $threat->getCveId() ?? 'N/A',
            $threat->getStatus() ?? 'new',
            $threat->getCvssScore() ?? 0,
            $threat->getDetectionDate()?->format('c') ?? ''
        );

        return [
            'THREAT-' . $threat->getId(),
            $this->sanitizeCefField($threat->getTitle() ?? 'Security Threat'),
            $severity,
            $extensions
        ];
    }

    /**
     * Build CEF format from cryptographic operation
     */
    private function buildCefFromCrypto($crypto): array
    {
        $severity = $crypto->getStatus() === 'success' ? 1 : 5;

        $extensions = sprintf(
            'act=%s cs1=%s cs1Label=Algorithm cn1=%d cn1Label=KeyLength suser=%s outcome=%s rt=%s',
            $crypto->getOperationType() ?? 'unknown',
            $crypto->getAlgorithm() ?? 'unknown',
            $crypto->getKeyLength() ?? 0,
            $crypto->getUser()?->getEmail() ?? 'system',
            $crypto->getStatus() ?? 'unknown',
            $crypto->getTimestamp()?->format('c') ?? ''
        );

        return [
            'CRYPTO-' . $crypto->getId(),
            sprintf('Cryptographic Operation: %s', $crypto->getOperationType()),
            $severity,
            $extensions
        ];
    }

    /**
     * Build CEF format from physical access log
     */
    private function buildCefFromPhysicalAccess($access): array
    {
        $severity = match ($access->getAccessType()) {
            'forced_entry' => 10,
            'denied' => 7,
            'exit', 'entry' => $access->isAuthorized() ? 1 : 8,
            default => 1
        };

        $extensions = sprintf(
            'suser=%s cs1=%s cs1Label=Location cs2=%s cs2Label=AccessType cs3=%s cs3Label=AuthMethod outcome=%s rt=%s',
            $this->sanitizeCefField($access->getEffectivePersonName() ?? 'unknown'),
            $this->sanitizeCefField($access->getEffectiveLocation() ?? 'unknown'),
            $access->getAccessType() ?? 'unknown',
            $access->getAuthenticationMethod() ?? 'unknown',
            $access->isAuthorized() ? 'success' : 'failure',
            $access->getAccessTime()?->format('c') ?? ''
        );

        return [
            'PHYSICAL-' . $access->getId(),
            sprintf('Physical Access: %s', $access->getAccessType()),
            $severity,
            $extensions
        ];
    }

    /**
     * Build CEF format from audit log
     */
    private function buildCefFromAuditLog($log): array
    {
        $extensions = sprintf(
            'suser=%s act=%s src=%s rt=%s',
            $log->getUser()?->getEmail() ?? 'system',
            $log->getAction() ?? 'unknown',
            $log->getIpAddress() ?? 'unknown',
            $log->getCreatedAt()?->format('c') ?? ''
        );

        return [
            'AUDIT-' . $log->getId(),
            sprintf('Audit Event: %s', $log->getAction()),
            1,
            $extensions
        ];
    }

    /**
     * Convert event to JSON format
     */
    private function convertToJsonEvent($event, string $eventType): array
    {
        return match ($eventType) {
            'incidents' => $this->buildJsonFromIncident($event),
            'threats' => $this->buildJsonFromThreat($event),
            'crypto_operations' => $this->buildJsonFromCrypto($event),
            'physical_access' => $this->buildJsonFromPhysicalAccess($event),
            'audit_logs' => $this->buildJsonFromAuditLog($event),
            default => ['type' => 'unknown'],
        };
    }

    /**
     * Build JSON from incident
     */
    private function buildJsonFromIncident($incident): array
    {
        return [
            'event_type' => 'incident',
            'id' => $incident->getId(),
            'title' => $incident->getTitle(),
            'severity' => $incident->getSeverity(),
            'status' => $incident->getStatus(),
            'category' => $incident->getCategory(),
            'incident_type' => $incident->getIncidentType(),
            'detected_at' => $incident->getDetectedAt()?->format('c'),
            'reported_by' => $incident->getReportedBy(),
            'source' => $incident->getSource(),
        ];
    }

    /**
     * Build JSON from threat
     */
    private function buildJsonFromThreat($threat): array
    {
        return [
            'event_type' => 'threat',
            'id' => $threat->getId(),
            'title' => $threat->getTitle(),
            'threat_type' => $threat->getThreatType(),
            'severity' => $threat->getSeverity(),
            'cve_id' => $threat->getCveId(),
            'cvss_score' => $threat->getCvssScore(),
            'status' => $threat->getStatus(),
            'affects_organization' => $threat->isAffectsOrganization(),
            'detection_date' => $threat->getDetectionDate()?->format('c'),
        ];
    }

    /**
     * Build JSON from crypto operation
     */
    private function buildJsonFromCrypto($crypto): array
    {
        return [
            'event_type' => 'cryptographic_operation',
            'id' => $crypto->getId(),
            'operation_type' => $crypto->getOperationType(),
            'algorithm' => $crypto->getAlgorithm(),
            'key_length' => $crypto->getKeyLength(),
            'status' => $crypto->getStatus(),
            'compliance_relevant' => $crypto->isComplianceRelevant(),
            'timestamp' => $crypto->getTimestamp()?->format('c'),
            'user' => $crypto->getUser()?->getEmail(),
        ];
    }

    /**
     * Build JSON from physical access
     */
    private function buildJsonFromPhysicalAccess($access): array
    {
        return [
            'event_type' => 'physical_access',
            'id' => $access->getId(),
            'person_name' => $access->getEffectivePersonName(),
            'person_type' => $access->getPerson()?->getPersonType(),
            'location' => $access->getEffectiveLocation(),
            'location_type' => $access->getLocationEntity()?->getLocationType(),
            'access_type' => $access->getAccessType(),
            'authentication_method' => $access->getAuthenticationMethod(),
            'authorized' => $access->isAuthorized(),
            'after_hours' => $access->isAfterHours(),
            'access_time' => $access->getAccessTime()?->format('c'),
        ];
    }

    /**
     * Build JSON from audit log
     */
    private function buildJsonFromAuditLog($log): array
    {
        return [
            'event_type' => 'audit',
            'id' => $log->getId(),
            'action' => $log->getAction(),
            'entity_type' => $log->getEntityType(),
            'entity_id' => $log->getEntityId(),
            'user' => $log->getUser()?->getEmail(),
            'ip_address' => $log->getIpAddress(),
            'created_at' => $log->getCreatedAt()?->format('c'),
        ];
    }

    /**
     * Convert event to Syslog format (RFC 5424)
     */
    private function convertToSyslog($event, string $eventType): string
    {
        $facility = 13; // Security/authorization messages
        $severity = 6; // Informational
        $hostname = gethostname() ?: 'little-isms-helper';
        $appName = 'ISMS';
        $timestamp = (new \DateTime())->format('c');

        $message = $this->buildSyslogMessage($event, $eventType);

        $priority = ($facility * 8) + $severity;

        return sprintf(
            '<%d>1 %s %s %s - - - %s',
            $priority,
            $timestamp,
            $hostname,
            $appName,
            $message
        );
    }

    /**
     * Build syslog message
     */
    private function buildSyslogMessage($event, string $eventType): string
    {
        return match ($eventType) {
            'incidents' => sprintf('INCIDENT id=%d severity=%s title="%s"',
                $event->getId(),
                $event->getSeverity(),
                $this->sanitizeSyslogField($event->getTitle())
            ),
            'threats' => sprintf('THREAT id=%d type=%s severity=%s cve=%s',
                $event->getId(),
                $event->getThreatType(),
                $event->getSeverity(),
                $event->getCveId() ?? 'N/A'
            ),
            'crypto_operations' => sprintf('CRYPTO id=%d operation=%s algorithm=%s status=%s',
                $event->getId(),
                $event->getOperationType(),
                $event->getAlgorithm(),
                $event->getStatus()
            ),
            'physical_access' => sprintf('PHYSICAL_ACCESS id=%d person="%s" location="%s" type=%s authorized=%s',
                $event->getId(),
                $this->sanitizeSyslogField($event->getEffectivePersonName()),
                $this->sanitizeSyslogField($event->getEffectiveLocation()),
                $event->getAccessType(),
                $event->isAuthorized() ? 'true' : 'false'
            ),
            default => 'UNKNOWN event_type=' . $eventType,
        };
    }

    /**
     * Sanitize field for CEF format
     */
    private function sanitizeCefField(string $value): string
    {
        // Escape special characters for CEF
        return str_replace(['|', '\\', '='], ['\\|', '\\\\', '\\='], $value);
    }

    /**
     * Sanitize field for Syslog format
     */
    private function sanitizeSyslogField(string $value): string
    {
        // Remove newlines and limit length
        return substr(str_replace(["\r", "\n"], ' ', $value), 0, 255);
    }
}
