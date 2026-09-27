<?php
declare(strict_types=1);
require dirname(__DIR__) . '/includes/bootstrap.php';
require_admin();

if (is_post() && post('action') === 'clear') {
    verify_csrf();
    @file_put_contents(MAIL_LOG, "<?php http_response_code(404); exit; ?>\n");
    flash('success', 'Email log cleared.');
    redirect('admin/mail-log.php');
}

$entries = [];
if (is_file(MAIL_LOG)) {
    $raw = (string) file_get_contents(MAIL_LOG);
    $raw = substr($raw, (int) strpos($raw, "\n") + 1); // skip the PHP guard line
    $raw = strlen($raw) > 200000 ? substr($raw, -200000) : $raw;
    $entries = array_reverse(array_filter(array_map('trim', explode('=== ', $raw))));
}

admin_header('Email log', 'mail');
?>
<header class="admin-head">
    <div>
        <h1>Email log</h1>
        <p class="muted">
            Every email the app sends (or would send) is recorded here.
            Sending is currently <strong><?= config('mail.enabled') ? 'ON' : 'OFF — emails are only logged' ?></strong>
            (<code>mail.enabled</code> in config).
        </p>
    </div>
    <?php if ($entries): ?>
        <form method="post" data-confirm="Clear the email log?">
            <?= csrf_field() ?><input type="hidden" name="action" value="clear">
            <button class="btn btn--ghost" type="submit">Clear log</button>
        </form>
    <?php endif; ?>
</header>

<?php if (!$entries): ?>
    <div class="empty"><?= icon('mail') ?><p>No emails yet.</p></div>
<?php else: ?>
    <div class="stack">
        <?php foreach (array_slice($entries, 0, 50) as $entry):
            [$meta, $content] = array_pad(explode("\n", $entry, 2), 2, ''); ?>
            <details class="card mail">
                <summary><span class="muted"><?= e($meta) ?></span><strong><?= e(strtok(trim($content), "\n")) ?></strong></summary>
                <pre class="pre"><?= e(trim($content)) ?></pre>
            </details>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
<?php admin_footer(); ?>
