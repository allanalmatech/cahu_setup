<?php
/**
 * Shared HTML shell for platform email.
 *
 * Email clients ignore <style> blocks inconsistently and do not reliably
 * support modern layout, so everything here is table based with inline styles.
 * That is what makes the message look the same in Outlook, Gmail and on a
 * phone's mail app.
 *
 * @var string $content
 * @var string $subject
 * @var string $appName
 */
$brand     = $appName ?? 'CAHU Platform';
$preheader = trim((string) ($preheader ?? ''));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $this->e($subject ?? 'CAHU Platform') ?></title>
</head>
<body style="margin:0;padding:0;background:#f2f5f3;font-family:'Helvetica Neue',Helvetica,Arial,sans-serif;color:#16211b;">
<?php /* Shown in the inbox list, hidden in the message body. */ ?>
<div style="display:none;max-height:0;overflow:hidden;opacity:0;"><?= $this->e($preheader) ?></div>

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f2f5f3;padding:28px 12px;">
    <tr>
        <td align="center">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
                   style="max-width:600px;background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 2px 10px rgba(16,41,28,.06);">

                <tr>
                    <td style="background:#17402c;padding:26px 30px;">
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                            <tr>
                                <td width="46" valign="middle">
                                    <div style="width:42px;height:42px;border-radius:10px;background:#2e7d32;color:#ffffff;font-weight:700;font-size:15px;line-height:42px;text-align:center;">
                                        CA
                                    </div>
                                </td>
                                <td valign="middle" style="padding-left:12px;">
                                    <div style="color:#ffffff;font-size:17px;font-weight:700;letter-spacing:.02em;line-height:1.2;">
                                        <?= $this->e($brand) ?>
                                    </div>
                                    <div style="color:rgba(255,255,255,.62);font-size:11px;text-transform:uppercase;letter-spacing:.09em;margin-top:3px;">
                                        Cooperative Agribusiness Hub Uganda
                                    </div>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>

                <tr>
                    <td style="padding:32px 30px 8px;font-size:15px;line-height:1.65;color:#16211b;">
                        <?= $content ?>
                    </td>
                </tr>

                <tr>
                    <td style="padding:12px 30px 30px;">
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
                               style="border-top:1px solid #e8eeea;padding-top:18px;">
                            <tr>
                                <td style="font-size:12px;line-height:1.6;color:#7d8b84;">
                                    &copy; <?= date('Y') ?> Cooperative Agribusiness Hub Uganda.
                                    This is an automated message; please do not reply to it.
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>

            </table>
        </td>
    </tr>
</table>
</body>
</html>