<?php

declare(strict_types=1);

namespace App\Tests\Deployment;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * B1 (2026-11): the Docker image's worker consumed only the scheduler transports while
 * every e-mail (SendEmailMessage: digest, reminders, escalations) is routed to `async`
 * — nothing was ever sent from the image. Every transport messages are routed to must
 * be consumed by a worker in docker/supervisor/supervisord.conf.
 */
final class DockerWorkerConsumesMailQueueTest extends TestCase
{
    #[Test]
    public function everyRoutedTransportHasAWorkerInTheImage(): void
    {
        $root = \dirname(__DIR__, 2);
        $messenger = Yaml::parseFile($root . '/config/packages/messenger.yaml');
        $routing = $messenger['framework']['messenger']['routing'] ?? [];
        self::assertSame('async', $routing['Symfony\Component\Mailer\Messenger\SendEmailMessage'] ?? null, 'mail routing changed — update this test');

        $routed = [];
        foreach ($routing as $transports) {
            foreach ((array) $transports as $transport) {
                if ($transport !== 'sync') {
                    $routed[$transport] = true;
                }
            }
        }

        $supervisor = (string) file_get_contents($root . '/docker/supervisor/supervisord.conf');
        preg_match_all('/^command=.*messenger:consume\s+([^"\n]*)/m', $supervisor, $matches);
        $consumed = [];
        foreach ($matches[1] as $arguments) {
            foreach (preg_split('/\s+/', trim($arguments)) ?: [] as $word) {
                if ($word === '' || str_starts_with($word, '-')) {
                    break;
                }
                $consumed[$word] = true;
            }
        }

        foreach (array_keys($routed) as $transport) {
            self::assertArrayHasKey($transport, $consumed, sprintf('no Docker worker consumes the "%s" transport', $transport));
        }
    }
}
