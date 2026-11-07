<?php

declare(strict_types=1);

namespace App\Tests\Doctrine;

use App\Doctrine\PostFlushWriter;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostFlushEventArgs;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class PostFlushWriterTest extends TestCase
{
    #[Test]
    public function queuedRowsAreWrittenOnceAfterTheFlush(): void
    {
        $writer = new PostFlushWriter(new NullLogger());
        $row = new \stdClass();
        $writer->persist($row, $row);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('isOpen')->willReturn(true);
        $em->expects(self::once())->method('persist')->with($row);
        $em->expects(self::once())->method('flush')
            // The drain flush fires postFlush again — it must not recurse.
            ->willReturnCallback(static fn() => $writer->postFlush(new PostFlushEventArgs($em)));

        $writer->postFlush(new PostFlushEventArgs($em));
        $writer->postFlush(new PostFlushEventArgs($em)); // queue is empty now
    }

    #[Test]
    public function rowsQueuedDuringTheDrainAreWrittenToo(): void
    {
        $writer = new PostFlushWriter(new NullLogger());
        $first = new \stdClass();
        $second = new \stdClass();
        $writer->persist($first);

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('isOpen')->willReturn(true);
        $persisted = [];
        $em->method('persist')->willReturnCallback(static function (object $o) use (&$persisted): void {
            $persisted[] = $o;
        });
        $calls = 0;
        $em->method('flush')->willReturnCallback(static function () use ($writer, $second, &$calls): void {
            if (++$calls === 1) {
                $writer->persist($second); // a listener of the drain flush derives another row
            }
        });

        $writer->postFlush(new PostFlushEventArgs($em));

        self::assertSame([$first, $second], $persisted);
    }

    #[Test]
    public function aFailingDrainDoesNotEscape(): void
    {
        $writer = new PostFlushWriter(new NullLogger());
        $writer->persist(new \stdClass());

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('isOpen')->willReturn(true);
        $em->method('flush')->willThrowException(new \RuntimeException('constraint'));

        $writer->postFlush(new PostFlushEventArgs($em));
        $this->addToAssertionCount(1);
    }
}
