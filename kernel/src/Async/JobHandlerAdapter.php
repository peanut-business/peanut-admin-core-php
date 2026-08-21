<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Async;

final readonly class JobHandlerAdapter
{
    public function __construct(
        private TrustedEnvelopeCodec $codec,
        private AsyncAuthorizationRevalidator $authorization,
    ) {}

    /**
     * @template T
     * @param callable(\PeanutAdmin\Kernel\Context\AuthorizedOperationContext, VerifiedJobEnvelope): T $handler
     * @return T
     */
    public function handle(string $encodedEnvelope, callable $handler): mixed
    {
        $envelope = $this->codec->verify($encodedEnvelope);
        $authorized = $this->authorization->reauthorize($envelope);

        return $handler($authorized, $envelope);
    }
}
