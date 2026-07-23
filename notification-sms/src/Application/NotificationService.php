<?php

declare(strict_types=1);

namespace PeanutAdmin\NotificationSms\Application;

use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;
use PeanutAdmin\NotificationSms\Package;
use PeanutAdmin\NotificationSms\Persistence\NotificationRepository;

final readonly class NotificationService
{
    public function __construct(
        private NotificationRepository $repository,
        private RecipientResolver $recipients,
        private AttachmentResolver $attachments,
        private TemplateRenderer $renderer,
    ) {}

    /**
     * @param list<string> $channels
     * @param list<string> $variables
     * @return array<string, mixed>
     */
    public function putTemplate(
        AuthorizedOperationContext $context,
        string $templateKey,
        string $name,
        string $subjectTemplate,
        string $bodyTemplate,
        array $channels,
        array $variables,
        ?int $expectedRevision,
    ): array {
        $this->assertOperation($context, 'manage');
        $this->assertTemplateInput($templateKey, $name, $subjectTemplate, $bodyTemplate, $channels, $variables);

        return $this->repository->putTemplate(
            $context->tenantContext,
            $templateKey,
            trim($name),
            $subjectTemplate,
            $bodyTemplate,
            array_values(array_unique($channels)),
            array_values(array_unique($variables)),
            $expectedRevision,
        );
    }

    /**
     * @param list<array{member_id: int, variables: array<string, scalar|null>}> $recipientInputs
     * @param list<string> $fileKeys
     * @return array{messages: list<NotificationMessage>, outbox: list<OutboxRecord>}
     */
    public function publish(
        AuthorizedOperationContext $context,
        string $templateKey,
        array $recipientInputs,
        array $fileKeys = [],
    ): array {
        $this->assertOperation($context, 'manage');
        if (strlen($templateKey) > 64 || preg_match('/^[a-z][a-z0-9]*(?:[.-][a-z0-9]+)*$/D', $templateKey) !== 1
            || count($recipientInputs) < 1 || count($recipientInputs) > 100
            || count($fileKeys) > 10
        ) {
            throw NotificationException::invalid();
        }
        $seenFiles = [];
        foreach ($fileKeys as $fileKey) {
            if (!is_string($fileKey) || isset($seenFiles[$fileKey])) {
                throw NotificationException::attachmentUnavailable();
            }
            $seenFiles[$fileKey] = true;
        }

        return $this->repository->transaction(function () use ($context, $templateKey, $recipientInputs, $fileKeys): array {
            $template = $this->repository->activeTemplate($context->tenantContext->tenantId, $templateKey);
            $channels = $template['channels'];
            $requiresSms = in_array('sms', $channels, true);
            $attachmentSnapshots = [];
            foreach ($fileKeys as $fileKey) {
                $attachmentSnapshots[] = $this->attachments->snapshot($context->tenantContext, $fileKey);
            }

            $messages = [];
            $outbox = [];
            $seenMembers = [];
            foreach ($recipientInputs as $input) {
                if (!is_array($input) || count($input) !== 2
                    || !array_key_exists('member_id', $input) || !array_key_exists('variables', $input)
                    || !is_int($input['member_id']) || !is_array($input['variables'])
                    || isset($seenMembers[$input['member_id']])
                ) {
                    throw NotificationException::invalid();
                }
                $seenMembers[$input['member_id']] = true;
                $recipient = $this->recipients->snapshot($context->tenantContext, $input['member_id'], $requiresSms);
                $subject = $this->renderer->render(
                    $template['subject_template'],
                    $template['variables'],
                    $input['variables'],
                    255,
                );
                $body = $this->renderer->render(
                    $template['body_template'],
                    $template['variables'],
                    $input['variables'],
                    $requiresSms ? 1000 : 10000,
                );
                $created = $this->repository->createMessage(
                    $context->tenantContext,
                    'notice_' . bin2hex(random_bytes(16)),
                    $template,
                    $recipient,
                    $subject,
                    $body,
                    $attachmentSnapshots,
                );
                $messages[] = $created['message'];
                array_push($outbox, ...$created['outbox']);
            }

            return ['messages' => $messages, 'outbox' => $outbox];
        });
    }

    /** @return array{items: list<NotificationMessage>, page: int, page_size: int, total: int} */
    public function inbox(AuthorizedOperationContext $context, string $status, int $page, int $pageSize): array
    {
        $this->assertOperation($context, 'read');
        if (!in_array($status, ['unread', 'read', 'archived', 'all'], true)
            || $page < 1 || $pageSize < 1 || $pageSize > 100
        ) {
            throw NotificationException::invalid();
        }

        return $this->repository->inbox(
            $context->tenantContext->tenantId,
            $context->tenantContext->memberId,
            $status,
            $page,
            $pageSize,
        );
    }

    public function markRead(AuthorizedOperationContext $context, string $messageKey, int $revision): NotificationMessage
    {
        $this->assertOperation($context, 'read');
        $this->assertMessageKey($messageKey);

        return $this->repository->changeInbox($context->tenantContext, $messageKey, 'read', $revision);
    }

    /** @param list<string> $messageKeys */
    public function bulk(AuthorizedOperationContext $context, array $messageKeys, string $action): int
    {
        $this->assertOperation($context, 'read');
        if (!in_array($action, ['read', 'archive'], true)
            || count($messageKeys) < 1 || count($messageKeys) > 100
        ) {
            throw NotificationException::invalid();
        }
        $seenMessages = [];
        foreach ($messageKeys as $messageKey) {
            if (!is_string($messageKey) || isset($seenMessages[$messageKey])) {
                throw NotificationException::invalid();
            }
            $seenMessages[$messageKey] = true;
            $this->assertMessageKey($messageKey);
        }

        return $this->repository->bulkChangeInbox($context->tenantContext, $messageKeys, $action);
    }

    private function assertOperation(AuthorizedOperationContext $context, string $operation): void
    {
        if (!hash_equals(Package::RESOURCE_KEY, $context->resourceKey) || !hash_equals($operation, $context->operation)) {
            throw NotificationException::denied();
        }
    }

    /** @param list<string> $channels @param list<string> $variables */
    private function assertTemplateInput(
        string $key,
        string $name,
        string $subject,
        string $body,
        array $channels,
        array $variables,
    ): void {
        if (preg_match('/^[a-z][a-z0-9]*(?:[.-][a-z0-9]+)*$/D', $key) !== 1 || strlen($key) > 64
            || trim($name) === '' || mb_strlen(trim($name)) > 160
            || $subject === '' || mb_strlen($subject) > 255 || $body === '' || mb_strlen($body) > 10000
            || str_contains($name . $subject . $body, "\0")
            || $channels === [] || count($channels) > 2 || count($variables) > 32
        ) {
            throw NotificationException::invalid('NOTIFICATION_TEMPLATE_INVALID');
        }
        $seenChannels = [];
        foreach ($channels as $channel) {
            if (!is_string($channel) || !in_array($channel, ['inbox', 'sms'], true) || isset($seenChannels[$channel])) {
                throw NotificationException::invalid('NOTIFICATION_TEMPLATE_INVALID');
            }
            $seenChannels[$channel] = true;
        }
        $seenVariables = [];
        foreach ($variables as $variable) {
            if (!is_string($variable) || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $variable) !== 1
                || isset($seenVariables[$variable])
            ) {
                throw NotificationException::invalid('NOTIFICATION_TEMPLATE_INVALID');
            }
            $seenVariables[$variable] = true;
        }
        foreach ([$subject, $body] as $template) {
            preg_match_all('/\{\{([^{}]+)\}\}/', $template, $matches);
            foreach ($matches[1] as $used) {
                if (!in_array($used, $variables, true)) {
                    throw NotificationException::invalid('NOTIFICATION_TEMPLATE_INVALID');
                }
            }
        }
    }

    private function assertMessageKey(string $messageKey): void
    {
        if (preg_match('/^notice_[0-9a-f]{32}$/D', $messageKey) !== 1) {
            throw NotificationException::notFound();
        }
    }
}
