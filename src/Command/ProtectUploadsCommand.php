<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Upload\ProtectedUploads;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Moves uploaded evidence that still lies in public/uploads/ (documents, document
 * versions, training materials) below var/uploads/, outside the web root.
 *
 * The migration Version20261108140000_ProtectUploads does this once on update; the
 * scheduler repeats it nightly (04:10) for files that could not be moved then or that an
 * older backup put back. Nothing is overwritten. Operators need not call it by hand.
 */
#[AsCommand(
    name: 'app:uploads:protect',
    description: 'Move uploaded documents and training materials out of the web root',
)]
final class ProtectUploadsCommand
{
    public function __construct(
        private readonly ProtectedUploads $uploads,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $result = $this->uploads->moveLegacyFiles();
        $io->writeln(sprintf(
            'Moved %d, already present %d, failed %d.',
            $result['moved'],
            $result['kept'],
            $result['failed'],
        ));
        if ($result['blocked']) {
            $io->warning('public/uploads is a separate volume but var/ is not: mount var/ persistently, then run again.');
        }

        return $result['failed'] === 0 ? Command::SUCCESS : Command::FAILURE;
    }
}
