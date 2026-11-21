<?php

declare(strict_types=1);

namespace App\Service\Suggestion;

/**
 * A draft kind only the assistant connection offers: no "Entwurf vorschlagen" button in the UI (a person changes
 * the record there directly), no nightly run — the proposal exists because an assistant asked for a decision.
 */
interface AssistantOnlyKind extends RecordDraftKind
{
}
