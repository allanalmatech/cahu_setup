<?php
declare(strict_types=1);

namespace Http\Controllers\Admin;

use Core\Audit;
use Core\Controller;
use Core\Csrf;
use Core\Flash;
use Core\HttpException;
use Core\Response;

/**
 * Runtime configuration held in system_settings. Only keys that already exist
 * can be written: the form posts a bag of values keyed by setting name, so a
 * crafted POST would otherwise be able to invent a configuration row the rest of
 * the application never reads.
 *
 * A row flagged is_secret is never sent to the browser. Its control renders
 * empty, so leaving it blank is the only way to keep it, and a blank value on a
 * secret is therefore treated as "unchanged" rather than as "cleared".
 *
 * Most rows in this table are not editable, and that is deliberate. The
 * application reads them from the boot configuration instead, so a text box
 * here would look authoritative while having no effect. Those rows render
 * read-only and say where the real value lives; see origin().
 */
final class SettingController extends Controller
{
    /**
     * Keys the application reads out of system_settings, so editing them here
     * genuinely changes behaviour. Everything else is shown read-only.
     *
     * Only these families are read through the setting helper. The list is short
     * because the rest of system_settings is deployment configuration that the
     * boot config already owns, and a control that writes a row nobody reads is
     * worse than no control: it looks authoritative and changes nothing.
     *
     * Order matters. map.harvest_ready_days is listed as an exact key because the
     * rest of the map.* group is configuration.
     */
    private const EDITABLE_KEYS = [
        'map.harvest_ready_days',
    ];

    /**
     * Families read through Setting::get() as well. The scoring weights and the
     * forecast factors are built at the call site, so they are matched by prefix
     * rather than listed one by one.
     */
    private const EDITABLE_PREFIXES = [
        'score.weight.',
        'forecast.factor.',
    ];

    /**
     * Where the boot configuration keeps a value the seeder names differently.
     *
     * The seeder and config/config.php do not use the same vocabulary: the
     * settings table calls the mail group "email" where the config calls it
     * "mail", and session limits live under the app section however they are
     * spelled in the table. These are the only renames in use, and they are
     * spelled out here so the two schemes cannot drift apart unnoticed.
     */
    private const CONFIG_PREFIX_ALIASES = [
        'security.' => 'app.',
        'email.'    => 'mail.',
    ];

    private const CONFIG_LEAF_ALIASES = [
        // The seeder calls the sender address from_email; the config calls it from.
        'mail.from_email' => 'mail.from',
    ];

    public function __construct(\Core\Database $db, \Core\Auth $auth, \Core\View $view, \Core\Request $request)
    {
        parent::__construct($db, $auth, $view, $request);
    }

    public function index(): Response
    {
        $rows = $this->db->select(
            'SELECT id, setting_key, setting_group, description, is_secret,
                    CASE WHEN is_secret = 1 THEN NULL ELSE setting_value END AS setting_value
             FROM system_settings
             ORDER BY setting_group, setting_key'
        );

        $groups = [];

        foreach ($rows as $row) {
            $key    = (string) $row['setting_key'];
            $origin = $this->origin($key);

            $row['origin']   = $origin;
            $row['editable'] = $origin === 'database';
            $row['note']     = $this->originNote($origin);

            $row['control'] = $row['editable']
                ? $this->controlFor($key, (string) $row['description'], $row['setting_value'] === null ? null : (string) $row['setting_value'])
                : 'readonly';

            $groups[(string) $row['setting_group']][] = $row;
        }

        return $this->render('admin/settings', [
            'pageTitle' => 'Settings',
            'groups'    => $groups,
        ]);
    }

    public function update(): Response
    {
        $this->assertCsrf();

        $whitelist = $this->whitelist();

        $posted = $this->request->input('settings');
        $posted = is_array($posted) ? $posted : [];

        // Unknown keys are rejected before anything is written, so a bad request
        // can never half-apply a settings change. Keys that exist but belong to
        // the environment are refused for the same reason: accepting them would
        // store a value that reads as authoritative and changes nothing.
        $unknown    = [];
        $misplaced  = [];

        foreach ($posted as $key => $value) {
            $key = trim((string) $key);

            if (!array_key_exists($key, $whitelist)) {
                $unknown[] = $key;
            } elseif (!$whitelist[$key]['editable']) {
                $misplaced[] = $key;
            }
        }

        if ($unknown !== []) {
            sort($unknown);

            return $this->backWithErrors(
                url('admin'),
                ['settings' => 'These setting names are not part of the configuration and were refused: ' . implode(', ', $unknown) . '.']
            );
        }

        if ($misplaced !== []) {
            sort($misplaced);

            return $this->backWithErrors(
                url('admin'),
                ['settings' => 'These settings are not kept in the database and were refused: ' . implode(', ', $misplaced) . '.']
            );
        }

        $old     = [];
        $new     = [];
        $changed = 0;

        foreach ($posted as $key => $value) {
            $key = trim((string) $key);

            if (!is_scalar($value)) {
                continue;
            }

            $value = trim((string) $value);
            $secret = $whitelist[$key]['secret'];

            // The control for a secret is always empty, so a blank submission
            // means the administrator did not touch it.
            if ($secret && $value === '') {
                continue;
            }

            $previous = (string) $this->db->scalar(
                'SELECT setting_value FROM system_settings WHERE setting_key = :key LIMIT 1',
                ['key' => $key]
            );

            if ($previous === $value) {
                continue;
            }

            $this->db->update('system_settings', ['setting_value' => $value], 'setting_key = :key', ['key' => $key]);

            $old[$key] = $secret ? '[redacted]' : $previous;
            $new[$key] = $secret ? '[redacted]' : $value;
            $changed++;
        }

        if ($changed === 0) {
            Flash::info('Nothing was changed.');

            return $this->redirect(url('admin'));
        }

        Audit::log('update', 'settings', 'setting', null, $changed . ' setting(s)', $old, $new);

        Flash::success($changed . ' setting(s) saved.');

        return $this->redirect(url('admin'));
    }

    /* ------------------------------------------------------------------ */
    /* Supporting data                                                     */
    /* ------------------------------------------------------------------ */

    /**
     * The only setting names the screen is allowed to write, each mapped to
     * whether it holds a secret and whether this screen may edit it at all.
     *
     * @return array<string,array{secret:bool,editable:bool}>
     */
    private function whitelist(): array
    {
        $whitelist = [];

        foreach ($this->db->select('SELECT setting_key, is_secret FROM system_settings') as $row) {
            $key = (string) $row['setting_key'];

            $whitelist[$key] = [
                'secret'   => (int) $row['is_secret'] === 1,
                'editable' => $this->origin($key) === 'database',
            ];
        }

        return $whitelist;
    }

    /**
     * Where a setting's live value comes from, which decides whether the screen
     * may offer an editor for it.
     *
     * The database answer is a short list, because a key counts as editable only
     * if something reads it through the setting helper. The environment answer is
     * not listed: the boot config is already in memory by the time a request
     * reaches here, so asking it whether it holds the key is both exact and free.
     * That matters, because a key that the seeder writes but no code reads would
     * otherwise be offered a text box that saves a value nothing acts on.
     *
     * @return 'database'|'environment'|'not-wired'
     */
    private function origin(string $key): string
    {
        if (in_array($key, self::EDITABLE_KEYS, true)) {
            return 'database';
        }

        foreach (self::EDITABLE_PREFIXES as $prefix) {
            if (str_starts_with($key, $prefix)) {
                return 'database';
            }
        }

        $configKey = self::CONFIG_LEAF_ALIASES[$this->configKeyFor($key)] ?? $this->configKeyFor($key);

        // A sentinel, so that a setting legitimately configured as null or false
        // is still recognised as present.
        $absent = new \stdClass();

        return $this->config($configKey, $absent) === $absent ? 'not-wired' : 'environment';
    }

    /**
     * Translates a seeded setting name into the name the boot configuration uses.
     */
    private function configKeyFor(string $key): string
    {
        foreach (self::CONFIG_PREFIX_ALIASES as $seededPrefix => $configPrefix) {
            if (str_starts_with($key, $seededPrefix)) {
                return $configPrefix . substr($key, strlen($seededPrefix));
            }
        }

        return $key;
    }

    /**
     * The plain sentence shown under a read-only row, so the administrator is
     * told where the real value lives rather than being left to guess.
     */
    private function originNote(string $origin): string
    {
        return match ($origin) {
            'environment' => 'Configured in the environment or in config/config.php, not in the database. Change it there and restart; saving it here would have no effect.',
            'not-wired'    => 'Recorded for reference only. Nothing in the application reads this key, so it cannot be changed from here.',
            default        => '',
        };
    }

    /**
     * Chooses the control that suits the setting: a switch for a flag, a
     * textarea for prose, a plain box for everything else. A secret never gets
     * one, because its value is not on this page to display.
     */
    private function controlFor(string $key, string $description, ?string $value): string
    {
        if (preg_match('/(^|[._])(enabled|disabled|debug|maintenance|readonly|allow_[a-z_]*|require_[a-z_]*)$/i', $key) === 1
            || preg_match('/\b(enable|disable|toggle|switch|yes\s*\/\s*no|on\s*\/\s*off)\b/i', $description) === 1
        ) {
            return 'toggle';
        }

        if (preg_match('/\b(text|message|instructions|note|terms|address|body)\b/i', $key) === 1) {
            return 'textarea';
        }

        if ($value !== null && (mb_strlen($value) > 120 || str_contains($value, "\n"))) {
            return 'textarea';
        }

        return 'text';
    }

    private function assertCsrf(): void
    {
        if (!Csrf::check($this->request->input('_token'))) {
            throw new HttpException(419, 'Your session expired. Please sign in again.');
        }
    }
}