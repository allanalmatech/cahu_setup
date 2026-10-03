<?php
/**
 * Farmer supporting documents.
 *
 * @var Core\View $view
 * @var array<string,mixed> $farmer
 * @var array<int,array<string,mixed>> $documents
 */
$code = (string) $farmer['farmer_code'];
?>
<div class="cahu-pagehead">
    <div>
        <h1>Documents</h1>
        <p class="cahu-pagehead__sub">
            <?= e($farmer['full_name']) ?> &middot;
            <a href="<?= e(url('farmers/' . $code)) ?>"><?= e($code) ?></a>
        </p>
    </div>
    <div class="cahu-pagehead__actions">
        <a class="btn cahu-btn-outline" href="<?= e(url('farmers/' . $code)) ?>">Back to record</a>
    </div>
</div>

<div class="row g-3">
    <div class="col-12 col-lg-8">
        <div class="cahu-card">
            <div class="cahu-card__head">Uploaded documents</div>
            <div class="cahu-card__body cahu-card__body--flush">
                <div class="table-responsive">
                    <table class="table cahu-table align-middle mb-0">
                        <thead>
                        <tr>
                            <th>Type</th>
                            <th>File</th>
                            <th>Uploaded</th>
                            <th>Expires</th>
                            <th class="text-end">Actions</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if ($documents === []): ?>
                            <?= $view->partial('partials/empty', [
                                'columns' => 5,
                                'title'   => 'No documents uploaded',
                                'message' => 'Upload the national ID, land agreement or cooperative membership letter for this farmer.',
                            ]) ?>
                        <?php else: ?>
                            <?php foreach ($documents as $document): ?>
                                <tr>
                                    <td><?= e(ucwords(str_replace('_', ' ', (string) $document['doc_type']))) ?></td>
                                    <td>
                                        <a href="<?= e($document['file_path']) ?>" target="_blank" rel="noopener">
                                            <?= e($document['title'] ?: $document['original_name']) ?>
                                        </a>
                                        <div class="text-muted small">
                                            <?= e(strtoupper((string) pathinfo((string) $document['file_path'], PATHINFO_EXTENSION))) ?>
                                            &middot; <?= e(number_format(((int) $document['file_size']) / 1024, 0)) ?> KB
                                        </div>
                                    </td>
                                    <td>
                                        <?= e(format_date($document['created_at'])) ?>
                                        <div class="text-muted small"><?= e($document['uploaded_by_name'] ?: 'System') ?></div>
                                    </td>
                                    <td><?= e(format_date($document['expires_at'])) ?></td>
                                    <td class="text-end cahu-table__actions">
                                        <form method="post" action="<?= e(url('farmers/' . $code . '/documents/' . $document['id'] . '/delete')) ?>"
                                              onsubmit="return confirm('Remove this document?')">
                                            <?= csrf_field() ?>
                                            <button class="btn btn-sm btn-link text-danger text-decoration-none" type="submit">Remove</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-4">
        <div class="cahu-card">
            <div class="cahu-card__head">Upload document</div>
            <div class="cahu-card__body">
                <form method="post" enctype="multipart/form-data"
                      action="<?= e(url('farmers/' . $code . '/documents')) ?>">
                    <?= csrf_field() ?>

                    <div class="mb-3">
                        <label class="form-label" for="doc_type">Document type</label>
                        <select class="form-select" id="doc_type" name="doc_type">
                            <option value="national_id">National ID</option>
                            <option value="land_agreement">Land agreement</option>
                            <option value="cooperative_membership">Cooperative membership</option>
                            <option value="bank_statement">Bank statement</option>
                            <option value="photo">Photograph</option>
                            <option value="other">Other</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="doc_title">Title</label>
                        <input class="form-control" type="text" id="doc_title" name="title"
                               placeholder="e.g. Land agreement, Wakiso plot 12">
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="expires_at">Expires on <span class="text-muted">(optional)</span></label>
                        <input class="form-control" type="date" id="expires_at" name="expires_at">
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="document">File</label>
                        <input class="form-control" type="file" id="document" name="document" required
                               accept="application/pdf,image/jpeg,image/png">
                        <div class="form-text">PDF, JPEG or PNG up to 10 MB.</div>
                    </div>

                    <button class="btn cahu-btn-primary w-100" type="submit">Upload document</button>
                </form>
            </div>
        </div>
    </div>
</div>
