<?php

declare(strict_types=1);

namespace PeanutAdmin\NotificationSms\Application;

final class TemplateRenderer
{
    /**
     * @param list<string> $declaredVariables
     * @param array<string, scalar|null> $variables
     */
    public function render(string $template, array $declaredVariables, array $variables, int $maxLength): string
    {
        if ($template === '' || strlen($template) > 10000 || $maxLength < 1 || $maxLength > 20000) {
            throw NotificationException::invalid('NOTIFICATION_TEMPLATE_INVALID');
        }
        $declared = [];
        foreach ($declaredVariables as $key) {
            if (preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $key) !== 1 || isset($declared[$key])) {
                throw NotificationException::invalid('NOTIFICATION_TEMPLATE_INVALID');
            }
            $declared[$key] = true;
        }
        if (array_keys($variables) !== array_keys(array_intersect_key($variables, $declared))
            || count($variables) !== count($declared)
        ) {
            throw NotificationException::invalid('NOTIFICATION_TEMPLATE_VARIABLE_INVALID');
        }
        foreach ($declared as $key => $_) {
            if (!array_key_exists($key, $variables)) {
                throw NotificationException::invalid('NOTIFICATION_TEMPLATE_VARIABLE_INVALID');
            }
            $value = $variables[$key];
            if (!is_scalar($value) && $value !== null) {
                throw NotificationException::invalid('NOTIFICATION_TEMPLATE_VARIABLE_INVALID');
            }
            $replacement = $value === null ? '' : (is_bool($value) ? ($value ? 'true' : 'false') : (string) $value);
            if (str_contains($replacement, "\0") || str_contains($replacement, '{{')
                || str_contains($replacement, '}}') || strlen($replacement) > 2000
            ) {
                throw NotificationException::invalid('NOTIFICATION_TEMPLATE_VARIABLE_INVALID');
            }
            $template = str_replace('{{' . $key . '}}', $replacement, $template);
        }
        if (preg_match('/\{\{[^{}]+\}\}/', $template) === 1 || mb_strlen($template) > $maxLength) {
            throw NotificationException::invalid('NOTIFICATION_TEMPLATE_RENDER_INVALID');
        }

        return $template;
    }
}
