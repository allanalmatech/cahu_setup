<h2 style="margin:0 0 14px;color:#17402c;font-size:20px;line-height:1.25;">Welcome, <?= $this->e($firstName) ?>!</h2>

<p style="margin:0 0 18px;">
    Your account has been created on the Cooperative Agribusiness Hub Uganda (CAHU) platform
    and is now waiting for approval. An administrator will review your registration and
    assign the access your role requires. You will be able to sign in once that is done.
</p>

<div style="text-align:center;margin:24px 0;">
    <a href="<?= $this->e($loginUrl) ?>" target="_blank"
       style="display:inline-block;padding:12px 22px;background:#2e7d32;color:#ffffff;text-decoration:none;border-radius:8px;font-weight:700;font-size:14px;box-shadow:0 1px 8px rgba(46,125,50,.18);">
        Sign in
    </a>
</div>

<p style="margin:0 0 18px;color:#4f675a;">
    Until your account is approved, signing in will report that it is not yet active. This
    is expected and needs no action from you beyond waiting for approval.
</p>

<p style="margin:0 0 18px;color:#4f675a;">
    If you have any questions, reach out to your cooperative manager or field officer.
</p>

<p style="margin:0;">Best regards,<br>The CAHU Team</p>