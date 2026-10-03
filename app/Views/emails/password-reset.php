<h2 style="margin:0 0 14px;color:#17402c;font-size:20px;line-height:1.25;">Reset your password</h2>

<p style="margin:0 0 18px;">
    Hello <?= $this->e($firstName) ?>, we received a request to reset the password on your
    <?= $this->e($appName) ?> account.
</p>

<div style="text-align:center;margin:24px 0;">
    <a href="<?= $this->e($link) ?>" target="_blank"
       style="display:inline-block;padding:12px 22px;background:#2e7d32;color:#ffffff;text-decoration:none;border-radius:8px;font-weight:700;font-size:14px;box-shadow:0 1px 8px rgba(46,125,50,.18);">
        Choose a new password
    </a>
</div>

<p style="margin:0 0 6px;font-size:13px;color:#7d8b84;">
    The link above works once and expires in <?= (int) $minutes ?> minutes. If it has expired,
    request a new one from the sign-in page.
</p>

<p style="margin:0 0 20px;font-size:13px;color:#7d8b84;">
    If the button does not work, copy this address into your browser:<br>
    <span style="word-break:break-all;"><?= $this->e($link) ?></span>
</p>

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
       style="border-left:3px solid #cfe3d4;background:#f6faf7;border-radius:0 6px 6px 0;">
    <tr>
        <td style="padding:13px 16px;font-size:13px;line-height:1.6;color:#4f675a;">
            <strong style="color:#17402c;">If you did not request this</strong>, you can ignore this
            email. Nothing has changed on your account and no one else can use this link.
        </td>
    </tr>
</table>