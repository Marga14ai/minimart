<?php

declare(strict_types=1);

use PHPMailer\PHPMailer\Exception as MailerException;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;

require_once __DIR__ . '/../vendor/phpmailer/phpmailer/src/Exception.php';
require_once __DIR__ . '/../vendor/phpmailer/phpmailer/src/PHPMailer.php';
require_once __DIR__ . '/../vendor/phpmailer/phpmailer/src/SMTP.php';

function mail_config(): array
{
    if (function_exists('app_config')) return app_config();
    $path = __DIR__ . '/../.env';
    if (!is_file($path)) return [];
    $config = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue;
        [$key, $value] = array_map('trim', explode('=', $line, 2));
        $config[$key] = trim($value, "\"'");
    }
    return $config;
}

function send_supplier_order_email(string $recipient, string $subject, string $htmlBody, ?string $plainBody = null): bool
{
    $config = mail_config();
    if (empty($config['MAIL_USERNAME']) || empty($config['MAIL_PASSWORD'])) {
        error_log('Supplier order email was not sent: Gmail SMTP credentials are incomplete.');
        return false;
    }

    $mailer = new PHPMailer(true);
    try {
        $mailer->isSMTP();
        $mailer->Host = $config['MAIL_HOST'] ?? 'smtp.gmail.com';
        $mailer->Port = (int) ($config['MAIL_PORT'] ?? 587);
        $mailer->SMTPAuth = true;
        $mailer->Username = $config['MAIL_USERNAME'];
        $mailer->Password = str_replace(' ', '', $config['MAIL_PASSWORD']);
        $mailer->Timeout = 15;
        $mailer->SMTPSecure = strtolower((string) ($config['MAIL_ENCRYPTION'] ?? 'tls')) === 'ssl'
            ? PHPMailer::ENCRYPTION_SMTPS
            : PHPMailer::ENCRYPTION_STARTTLS;
        $mailer->CharSet = PHPMailer::CHARSET_UTF8;
        $mailer->setFrom($config['MAIL_FROM_ADDRESS'] ?? $config['MAIL_USERNAME'], $config['MAIL_FROM_NAME'] ?? 'SME MiniMart Store');
        $mailer->addAddress($recipient);
        $mailer->Subject = $subject;
        $mailer->isHTML(true);
        $mailer->Body = $htmlBody;
        $mailer->AltBody = $plainBody ?? trim(html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $htmlBody)), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $mailer->send();
        return true;
    } catch (MailerException $error) {
        error_log('Supplier order email failed: ' . $mailer->ErrorInfo);
        return false;
    }
}

function supplier_order_email_content(array $order): array
{
    $escape = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    $rows = '';
    $plainLines = [];
    foreach ($order['items'] as $index => $item) {
        $details = $item['package_type'] === 'Box'
            ? $item['units_per_box'] . ' units per box'
            : 'Individual piece';
        $rows .= '<tr>'
            . '<td style="padding:12px 10px;border-bottom:1px solid #e5ebe7;color:#718078;">' . ($index + 1) . '</td>'
            . '<td style="padding:12px 10px;border-bottom:1px solid #e5ebe7;"><strong style="color:#183d2e;">' . $escape($item['product_name']) . '</strong></td>'
            . '<td style="padding:12px 10px;border-bottom:1px solid #e5ebe7;text-align:center;">' . (int) $item['quantity'] . '</td>'
            . '<td style="padding:12px 10px;border-bottom:1px solid #e5ebe7;text-align:center;">' . $escape($item['package_type']) . '</td>'
            . '<td style="padding:12px 10px;border-bottom:1px solid #e5ebe7;text-align:center;">' . (int) $item['total_units'] . '</td>'
            . '<td style="padding:12px 10px;border-bottom:1px solid #e5ebe7;color:#718078;">' . $escape($details) . '</td>'
            . '</tr>';
        $plainLines[] = ($index + 1) . '. ' . $item['product_name'] . ' — ' . $item['quantity'] . ' ' . strtolower($item['package_type']) . ($item['quantity'] > 1 ? 's' : '') . ', ' . $item['total_units'] . ' unit(s) total' . ($item['package_type'] === 'Box' ? ' (' . $details . ')' : '');
    }

    $notes = trim((string) ($order['notes'] ?? ''));
    $notesDisplay = $notes !== '' ? nl2br($escape($notes)) : 'No additional delivery instructions.';
    $html = '<!doctype html><html><body style="margin:0;background:#f3f6f4;font-family:Arial,sans-serif;color:#33443b;">'
        . '<div style="max-width:720px;margin:0 auto;padding:28px 14px;">'
        . '<div style="background:#103e2b;color:#fff;padding:24px 28px;border-radius:14px 14px 0 0;">'
        . '<div style="font-size:11px;letter-spacing:2px;color:#b8d4c6;">SME MINIMART STORE</div>'
        . '<h1 style="margin:7px 0 3px;font-size:25px;">Purchase Order</h1>'
        . '<div style="color:#d7e9df;font-size:14px;">' . $escape($order['order_number']) . '</div></div>'
        . '<div style="background:#fff;padding:28px;border:1px solid #dfe7e2;border-top:0;border-radius:0 0 14px 14px;">'
        . '<p style="margin-top:0;font-size:16px;">Hello <strong>' . $escape($order['supplier_name']) . '</strong>,</p>'
        . '<p style="line-height:1.65;">We would like to place the following purchase order for SME MiniMart Store. Please review the requested products and confirm their availability and expected delivery schedule.</p>'
        . '<table role="presentation" style="width:100%;margin:22px 0;border-collapse:collapse;border:1px solid #e1e8e4;font-size:13px;">'
        . '<thead><tr style="background:#eaf5ef;color:#176b42;"><th style="padding:11px 10px;text-align:left;">#</th><th style="padding:11px 10px;text-align:left;">Product</th><th style="padding:11px 10px;">Qty</th><th style="padding:11px 10px;">Type</th><th style="padding:11px 10px;">Total units</th><th style="padding:11px 10px;text-align:left;">Details</th></tr></thead>'
        . '<tbody>' . $rows . '</tbody></table>'
        . '<div style="background:#f7faf8;border-left:4px solid #1b754a;padding:14px 16px;margin:20px 0;"><strong style="display:block;margin-bottom:6px;color:#183d2e;">Delivery notes</strong><span style="line-height:1.55;">' . $notesDisplay . '</span></div>'
        . '<table role="presentation" style="width:100%;font-size:13px;line-height:1.8;"><tr><td style="color:#718078;width:130px;">Order number</td><td><strong>' . $escape($order['order_number']) . '</strong></td></tr><tr><td style="color:#718078;">Order date</td><td>' . $escape($order['order_date']) . '</td></tr><tr><td style="color:#718078;">Requested by</td><td>' . $escape($order['requested_by']) . '</td></tr></table>'
        . '<p style="margin:24px 0 5px;line-height:1.65;">Please reply to this email to confirm the order and delivery date.</p>'
        . '<p style="margin:18px 0 0;">Thank you,<br><strong style="color:#176b42;">SME MiniMart Store</strong></p>'
        . '</div><p style="text-align:center;color:#89968f;font-size:11px;line-height:1.5;">This purchase order was generated by the SME MiniMart Management System.</p></div></body></html>';

    $plain = "SME MINIMART STORE\nPURCHASE ORDER {$order['order_number']}\n\n"
        . "Hello {$order['supplier_name']},\n\n"
        . "We would like to place the following purchase order. Please confirm product availability and the expected delivery schedule.\n\n"
        . implode("\n", $plainLines)
        . "\n\nDelivery notes: " . ($notes !== '' ? $notes : 'No additional delivery instructions.')
        . "\n\nOrder number: {$order['order_number']}\nOrder date: {$order['order_date']}\nRequested by: {$order['requested_by']}"
        . "\n\nPlease reply to this email to confirm the order and delivery date.\n\nThank you,\nSME MiniMart Store";
    return ['html' => $html, 'plain' => $plain];
}

