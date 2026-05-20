<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Abstract base for all domain exceptions in Little ISMS Helper.
 *
 * Hierarchy convention (Symfony BP item #10 — domain-specific exceptions):
 *
 *   \RuntimeException
 *       └── App\Exception\AppException                  (abstract)
 *               ├── Tenant\TenantMismatchException
 *               ├── Tenant\TenantNotFoundException
 *               ├── Tenant\TenantOrphanException
 *               ├── Import\ImportFailedException
 *               ├── Import\ImportRowInvalidException
 *               ├── Regulatory\RegulatoryDeadlineBreachedException
 *               ├── Regulatory\FrameworkNotActivatedException
 *               ├── Workflow\InvalidStatusTransitionException
 *               ├── Module\ModuleNotActiveException
 *               ├── Module\ModuleConfigurationException
 *               ├── Security\InsufficientPrivilegeException
 *               ├── Security\CsrfTokenInvalidException
 *               ├── Validation\DomainValidationException
 *               ├── BusinessRule\BusinessRuleException
 *               ├── Io\IoException
 *               └── InvalidArgument\InvalidArgumentException
 *
 * Use these instead of bare \RuntimeException/\InvalidArgumentException
 * whenever the failure has a clear domain meaning that downstream code
 * (controllers, event listeners, audit log) may want to catch separately.
 *
 * Internal/programmer-error throws (assertions, "should never happen",
 * low-level DBAL wrap-ups) MAY keep the generic SPL exception types.
 */
abstract class AppException extends \RuntimeException
{
}
