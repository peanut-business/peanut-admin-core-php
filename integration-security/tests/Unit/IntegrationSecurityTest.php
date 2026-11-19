<?php

declare(strict_types=1);

namespace PeanutAdmin\IntegrationSecurity\Tests\Unit;

use PeanutAdmin\IntegrationSecurity\Application\IntegrationSecurityException;
use PeanutAdmin\IntegrationSecurity\Crypto\AesGcmWebhookSecretProtector;
use PeanutAdmin\IntegrationSecurity\Webhook\HostAddressResolver;
use PeanutAdmin\IntegrationSecurity\Webhook\WebhookDestinationPolicy;
use PHPUnit\Framework\TestCase;

final class IntegrationSecurityTest extends TestCase
{
    public function testWebhookDestinationPolicyApprovesPublicHttpsAndRejectsPrivateAddresses(): void
    {
        $resolver = new class implements HostAddressResolver {
            public function resolve(string $host): array
            {
                return match ($host) {
                    'hooks.example.com' => ['93.184.216.34'],
                    'private.example.com' => ['10.0.0.8'],
                    'mixed.example.com' => ['93.184.216.34', '127.0.0.1'],
                    'v6private.example.com' => ['fd12:3456::1'],
                    default => [],
                };
            }
        };

        $policy = new WebhookDestinationPolicy($resolver);
        $approved = $policy->approve('https://HOOKS.EXAMPLE.COM/callback?v=1');
        self::assertSame('https://hooks.example.com/callback?v=1', $approved->url);
        self::assertSame('hooks.example.com', $approved->host);
        self::assertSame(['93.184.216.34'], $approved->approvedAddresses);

        foreach ([
            'http://hooks.example.com/callback',
            'https://localhost/callback',
            'https://metadata.google.internal/computeMetadata',
            'https://private.example.com/hook',
            'https://mixed.example.com/hook',
            'https://v6private.example.com/hook',
            'https://169.254.169.254/latest/meta-data',
        ] as $deniedUrl) {
            try {
                $policy->approve($deniedUrl);
                self::fail("Expected destination to be denied: {$deniedUrl}");
            } catch (IntegrationSecurityException $exception) {
                self::assertSame('WEBHOOK_DESTINATION_DENIED', $exception->problemCode);
            }
        }
    }

    public function testAesGcmWebhookSecretProtectorSealsOpensAndRejectsTampering(): void
    {
        $key = base64_encode(str_repeat('k', 32));
        $protector = new AesGcmWebhookSecretProtector('v1', $key);
        $binding = '7:webhook_0123456789abcdef0123456789abcdef';
        $secret = str_repeat('s', 40);

        $sealed = $protector->seal($secret, $binding);
        self::assertSame('v1', $sealed['key_id']);
        self::assertSame($secret, $protector->open($sealed['ciphertext'], $sealed['key_id'], $binding));

        $this->expectException(IntegrationSecurityException::class);
        $protector->open($sealed['ciphertext'], $sealed['key_id'], '8:webhook_0123456789abcdef0123456789abcdef');
    }
}
