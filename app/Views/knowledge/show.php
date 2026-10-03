<?php
/**
 * A single advisory, written for a farmer to read without any technical
 * background: what the problem is, what to do, and what to watch for.
 *
 * @var Core\View $view
 * @var array<string,mixed> $item
 * @var int $views
 * @var string|null $embedUrl
 * @var array<int,array<string,mixed>> $related
 */
$published = (int) $item['is_published'] === 1;
$typeLabel = ucwords(str_replace('_', ' ', (string) $item['content_type']));
?>
<div class="cahu-pagehead">
    <div>
        <h1><?= e($item['title']) ?></h1>
        <p class="cahu-pagehead__sub">
            <?= e($typeLabel) ?>
            <?php if (!empty($item['category_name'])): ?>
                &middot; <?= e($item['category_name']) ?>
            <?php endif; ?>
            <?php if (!empty($item['crop_name'])): ?>
                &middot; <?= e($item['crop_name']) ?>
            <?php endif; ?>
            &middot; <?= e($views) ?> view(s)
        </p>
    </div>
    <div class="cahu-pagehead__actions">
        <a class="btn cahu-btn-outline" href="<?= e(url('knowledge')) ?>">Back to library</a>
        <a class="btn cahu-btn-outline" href="<?= e(url('knowledge/' . $item['slug'] . '/edit')) ?>">Edit</a>
    </div>
</div>

<?php if (!$published): ?>
    <div class="alert alert-warning">
        This advisory is a draft. It is not shown to farmers until it is published.
    </div>
<?php endif; ?>

<?php if (!empty($item['summary'])): ?>
    <div class="alert alert-light border">
        <div class="fw-semibold mb-1">In short</div>
        <?= e($item['summary']) ?>
    </div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-12 col-xl-8">
        <div class="cahu-card h-100">
            <div class="cahu-card__head">The guidance</div>
            <div class="cahu-card__body">
                <?php if (!empty($item['body'])): ?>
                    <div class="mb-3" style="line-height:1.7">
                        <?= nl2br(e($item['body'])) ?>
                    </div>
                <?php else: ?>
                    <p class="text-muted mb-0">
                        No written guidance has been added to this item yet.
                    </p>
                <?php endif; ?>

                <?php if ($embedUrl !== null): ?>
                    <hr>
                    <div class="fw-semibold mb-2">Watch the demonstration</div>
                    <div class="ratio ratio-16x9">
                        <iframe src="<?= e($embedUrl) ?>"
                                title="<?= e($item['title']) ?>"
                                allow="accelerometer; encrypted-media; picture-in-picture"
                                allowfullscreen loading="lazy"></iframe>
                    </div>
                <?php endif; ?>

                <?php if (!empty($item['file_path'])): ?>
                    <hr>
                    <a class="btn cahu-btn-primary" href="<?= e($item['file_path']) ?>" target="_blank" rel="noopener">
                        <?= strtolower((string) $item['content_type']) === 'image' ? 'View the picture' : 'Open the attached file' ?>
                    </a>
                <?php endif; ?>

                <?php if ($embedUrl === null && !empty($item['external_url'])): ?>
                    <hr>
                    <a class="btn cahu-btn-outline" href="<?= e($item['external_url']) ?>" target="_blank" rel="noopener">
                        Open the video on its own site
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-12 col-xl-4">
        <div class="cahu-card mb-3">
            <div class="cahu-card__head">What this applies to</div>
            <div class="cahu-card__body">
                <dl class="cahu-kv mb-0">
                    <?php
                    $details = [
                        'Topic'        => $item['category_name'],
                        'Crop'         => $item['crop_name'],
                        'Variety'      => $item['variety_name'],
                        'Stage'        => $item['farming_stage'],
                        'Problem'      => $item['problem_name'],
                        'Type'         => $typeLabel,
                        'Language'     => strtoupper((string) $item['language']),
                    ];
                    ?>
                    <?php foreach ($details as $label => $value): ?>
                        <?php if ($value === null || trim((string) $value) === '') { continue; } ?>
                        <dt><?= e($label) ?></dt>
                        <dd><?= e($value) ?></dd>
                    <?php endforeach; ?>
                </dl>
            </div>
        </div>

        <div class="cahu-card">
            <div class="cahu-card__head">About this advisory</div>
            <div class="cahu-card__body">
                <dl class="cahu-kv mb-0">
                    <dt>Status</dt>
                    <dd><?= $published ? status_badge('active', 'Published') : status_badge('inactive', 'Draft') ?></dd>
                    <dt>Published</dt>
                    <dd><?= e(format_date($item['published_at'])) ?></dd>
                    <dt>Last updated</dt>
                    <dd><?= e(format_date($item['updated_at'])) ?></dd>
                    <?php if (!empty($item['author_name'])): ?>
                        <dt>Written by</dt>
                        <dd><?= e($item['author_name']) ?></dd>
                    <?php endif; ?>
                    <dt>Times read</dt>
                    <dd><?= e($views) ?></dd>
                </dl>
            </div>
        </div>
    </div>
</div>

<?php if ($related !== []): ?>
    <div class="cahu-card mt-3">
        <div class="cahu-card__head">Related material</div>
        <div class="cahu-card__body">
            <div class="row g-3">
                <?php foreach ($related as $itemRelated): ?>
                    <div class="col-12 col-md-6 col-xl-3">
                        <a class="d-block h-100 p-2 text-decoration-none border rounded"
                           href="<?= e(url('knowledge/' . $itemRelated['slug'])) ?>">
                            <div class="small text-muted mb-1">
                                <?= e(ucwords(str_replace('_', ' ', (string) $itemRelated['content_type']))) ?>
                            </div>
                            <div class="fw-semibold small mb-1"><?= e($itemRelated['title']) ?></div>
                            <div class="text-muted small"><?= e((int) $itemRelated['view_count']) ?> view(s)</div>
                        </a>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
<?php endif; ?>
