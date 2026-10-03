<?php
/** @var string $message */
$copy = [
    403 => [
        'title' => 'Access denied',
        'body'  => 'Your account does not have permission to open this page. If you believe this is a mistake, ask an administrator to review your role permissions.',
    ],
    404 => [
        'title' => 'Page not found',
        'body'  => 'The page you asked for does not exist, or the record has been moved or removed.',
    ],
    500 => [
        'title' => 'Something went wrong',
        'body'  => 'The request could not be completed. The error has been recorded for the technical team.',
    ],
];
$page = $copy[$status] ?? $copy[500];
?>
<div class="d-flex align-items-center justify-content-center" style="min-height:60vh">
    <div class="text-center" style="max-width:460px">
        <div style="font-size:3.4rem;font-weight:800;color:var(--cahu-green-200);line-height:1"><?= e($status) ?></div>
        <h1 class="mt-2" style="font-size:1.35rem"><?= e($page['title']) ?></h1>
        <p class="text-muted mt-2" style="font-size:.9rem"><?= e($page['body']) ?></p>
        <?php if ($status !== 500 && !empty($message) && $message !== ''): ?>
            <div class="alert alert-light border small text-start mt-3" style="font-size:.82rem">
                <?= e($message) ?>
            </div>
        <?php endif; ?>
        <div class="d-flex gap-2 justify-content-center mt-4">
            <a class="btn cahu-btn-outline" href="<?= url('dashboard') ?>">Back to dashboard</a>
            <a class="btn cahu-btn-primary" href="<?= url('/') ?>">Home</a>
        </div>
    </div>
</div>
