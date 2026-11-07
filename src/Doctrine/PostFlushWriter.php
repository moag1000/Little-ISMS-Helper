<?php

declare(strict_types=1);

namespace App\Doctrine;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Writes rows that entity listeners derive from a flush, after that flush.
 *
 * Calling flush() inside postPersist/postUpdate re-enters the UnitOfWork while
 * it is still committing: the nested commit runs the outer flush's remaining
 * updates and listeners a second time and clears its queues, and a failure
 * closes the EntityManager in the middle of the user's save. Listeners queue
 * their new or changed entities here instead; postFlush persists them and
 * flushes once (the pattern of DocumentApprovalListener).
 *
 * Rows queued by listeners of the drain flush itself are drained in the same
 * call, a few passes at most.
 */
#[AsDoctrineListener(event: Events::postFlush)]
final class PostFlushWriter implements ResetInterface
{
    private const int MAX_PASSES = 3;

    /** @var array<int, object> */
    private array $queue = [];

    private bool $draining = false;

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    public function persist(object ...$entities): void
    {
        foreach ($entities as $entity) {
            $this->queue[spl_object_id($entity)] = $entity;
        }
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        if ($this->draining || $this->queue === []) {
            return;
        }

        $em = $args->getObjectManager();
        $this->draining = true;
        try {
            for ($pass = 0; $pass < self::MAX_PASSES && $this->queue !== [] && $em->isOpen(); $pass++) {
                $batch = $this->queue;
                $this->queue = [];
                foreach ($batch as $entity) {
                    $em->persist($entity);
                }
                $em->flush();
            }
        } catch (\Throwable $e) {
            fwrite(STDERR, 'DBG '.$e->getMessage()."\n");
            // The user's own flush has already committed; a derived row must not undo it.
            $this->logger->warning('Derived rows after flush could not be written', ['error' => $e->getMessage()]);
            $this->queue = [];
        } finally {
            $this->draining = false;
        }
    }

    public function reset(): void
    {
        $this->queue = [];
        $this->draining = false;
    }
}
