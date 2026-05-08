<?php

declare(strict_types=1);

namespace App\Service\PolicyWizard\Mode;

use App\Entity\WizardRun;
use App\Service\PolicyWizard\WizardStepKeys;

/**
 * Policy-Wizard W2-C — handler-lookup for {@see WizardRun} mode.
 *
 * The registry maps the {@see WizardRun::getMode()} string onto the
 * matching {@see ModeHandlerInterface} implementation, or returns
 * null for the default 'full' mode (which is driven directly by the
 * orchestrator without a special handler).
 *
 * Multiple handlers for the same mode is a programming error and
 * raises at construction time — registry-lookup is hot-path and we
 * prefer fail-fast over silent overwrite.
 */
final class ModeRegistry
{
    /** @var array<string, ModeHandlerInterface> mode-string indexed map */
    private array $handlers = [];

    /**
     * @param iterable<ModeHandlerInterface> $handlers Autowired by
     *   Symfony — every implementation of ModeHandlerInterface is
     *   collected via the `policy_wizard.mode_handler` tag.
     */
    public function __construct(iterable $handlers)
    {
        foreach ($handlers as $handler) {
            $mode = $handler->mode();
            if (isset($this->handlers[$mode])) {
                throw new \LogicException(sprintf(
                    'Duplicate ModeHandler registered for mode "%s": %s and %s.',
                    $mode,
                    $this->handlers[$mode]::class,
                    $handler::class,
                ));
            }
            $this->handlers[$mode] = $handler;
        }
    }

    /**
     * Resolve the handler for a given run. Returns null when the run
     * is in default 'full' mode — callers MUST treat null as "no
     * special handling required, run the canonical orchestrator path".
     */
    public function forRun(WizardRun $run): ?ModeHandlerInterface
    {
        return $this->forMode($run->getMode());
    }

    /**
     * Resolve the handler for a mode string directly. Useful in tests
     * + admin tooling where no full WizardRun is available.
     */
    public function forMode(string $mode): ?ModeHandlerInterface
    {
        if ($mode === WizardStepKeys::MODE_FULL) {
            return null;
        }
        return $this->handlers[$mode] ?? null;
    }

    /**
     * Whether a given mode is recognised by the registry. Full mode
     * is always recognised (it has no handler, but is a valid mode).
     */
    public function supports(string $mode): bool
    {
        return $mode === WizardStepKeys::MODE_FULL || isset($this->handlers[$mode]);
    }

    /**
     * @return list<string>
     */
    public function registeredModes(): array
    {
        return array_values(array_keys($this->handlers));
    }
}
