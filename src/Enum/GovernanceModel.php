<?php

namespace App\Enum;

/**
 * Defines how ISMS responsibilities are managed in a corporate structure
 */
enum GovernanceModel: string
{
    /**
     * Hierarchical: Parent company has 100% control and responsibility
     * - ISMS context is inherited from parent
     * - Subsidiaries follow parent policies
     * - Centralized decision making
     */
    case HIERARCHICAL = 'hierarchical';

    /**
     * Shared: Responsibilities are shared between parent and subsidiaries
     * - Each subsidiary can have its own ISMS context
     * - Parent provides framework and oversight
     * - Subsidiaries implement within their scope
     */
    case SHARED = 'shared';

    /**
     * Independent: Subsidiary operates completely independently
     * - Full autonomy in ISMS management
     * - Parent has no direct control
     * - Used for legally separate entities
     */
    case INDEPENDENT = 'independent';

    public function getLabel(): string
    {
        return match($this) {
            self::HIERARCHICAL => 'Hierarchisch (100% Muttergesellschaft)',
            self::SHARED => 'Geteilte Verantwortung',
            self::INDEPENDENT => 'Unabhängig',
        };
    }

    public function getDescription(): string
    {
        return match($this) {
            self::HIERARCHICAL => 'Die Muttergesellschaft hat vollständige Kontrolle und Verantwortung für das ISMS. Tochtergesellschaften übernehmen die Richtlinien und den Kontext der Muttergesellschaft.',
            self::SHARED => 'Verantwortung wird zwischen Mutter- und Tochtergesellschaft geteilt. Die Muttergesellschaft gibt den Rahmen vor, Tochtergesellschaften implementieren im eigenen Kontext.',
            self::INDEPENDENT => 'Tochtergesellschaft operiert vollständig unabhängig mit eigenem ISMS.',
        };
    }
}
