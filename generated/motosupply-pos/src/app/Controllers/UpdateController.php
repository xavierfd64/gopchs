<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\DB;
use App\Core\Http;
use App\Services\Backup;
use App\Services\Migrator;
use App\Services\Updater;

/** Settings → Updates: upload a signed update ZIP, review, confirm, install; history and rollback. */
final class UpdateController extends Controller
{
    public function index(): void
    {
        $token = (string) ($_SESSION['_update_token'] ?? '');
        $summary = null;
        $error = $_SESSION['_update_error'] ?? null;
        if ($token !== '' && ($path = Updater::packagePath($token)) !== null) {
            try {
                $summary = Updater::inspect($path);
            } catch (\RuntimeException $e) {
                $error = $e->getMessage();
                @unlink($path);
                unset($_SESSION['_update_token']);
            }
        }
        $this->view('pages/updates', [
            'title' => 'Updates',
            'nav' => 'settings',
            'summary' => $summary,
            'error' => $error,
            'result' => $_SESSION['_update_result'] ?? null,
            'history' => Updater::history(),
            'blockers' => Updater::requirements(),
            'pending' => Migrator::pending(),
            'uploadLimit' => ini_get('upload_max_filesize'),
        ]);
        unset($_SESSION['_update_error'], $_SESSION['_update_result']);
    }

    public function upload(): void
    {
        unset($_SESSION['_update_token']);
        try {
            $token = Updater::stash($_FILES['package'] ?? null);
            $summary = Updater::inspect((string) Updater::packagePath($token));
            $_SESSION['_update_token'] = $token;
            Audit::log('system.update.upload', 'update', $summary['version'], ['from' => MOTO_VERSION, 'files' => $summary['files']]);
        } catch (\RuntimeException $e) {
            if (isset($token) && ($p = Updater::packagePath($token)) !== null) {
                @unlink($p);
            }
            Audit::log('system.update.upload', 'update', '', ['error' => $e->getMessage()], 'failure');
            $_SESSION['_update_error'] = $e->getMessage();
        }
        Http::redirect(url('updates'));
    }

    public function apply(): void
    {
        $token = (string) ($_SESSION['_update_token'] ?? '');
        $path = Updater::packagePath($token);
        if ($path === null || Http::post('token') !== $token) {
            $_SESSION['_update_error'] = 'Upload the update package again.';
            Http::redirect(url('updates'));
        }
        if (Http::post('confirm') !== '1') {
            $_SESSION['_update_error'] = 'Tick the confirmation box to install the update.';
            Http::redirect(url('updates'));
        }
        @set_time_limit(300);
        try {
            $r = Updater::apply($path, Auth::id());
        } catch (\RuntimeException $e) {
            $r = ['ok' => false, 'log' => [$e->getMessage()], 'version' => ''];
        }
        Audit::log('system.update.apply', 'update', (string) ($r['version'] ?? ''), ['from' => MOTO_VERSION, 'log' => $r['log']], $r['ok'] ? 'success' : 'failure');
        @unlink($path);
        unset($_SESSION['_update_token']);
        $_SESSION['_update_result'] = $r;
        Http::redirect(url('updates'));
    }

    public function rollback(): void
    {
        $id = $this->idParam('id', 'post');
        if (Http::post('confirm') !== '1') {
            $_SESSION['_update_error'] = 'Tick the confirmation box to restore the previous files.';
            Http::redirect(url('updates'));
        }
        try {
            $r = Updater::rollback($id);
            Audit::log('system.update.rollback', 'update', (string) $id, $r);
            $_SESSION['_update_result'] = ['ok' => true, 'log' => ["Restored {$r['files']} application files of version {$r['version']}. The database was not changed (updates only add tables and columns)."], 'version' => $r['version']];
        } catch (\RuntimeException $e) {
            Audit::log('system.update.rollback', 'update', (string) $id, ['error' => $e->getMessage()], 'failure');
            $_SESSION['_update_error'] = $e->getMessage();
        }
        Http::redirect(url('updates'));
    }

    /** Download a backup made before an update (database dump or file archive). */
    public function downloadBackup(): void
    {
        $h = DB::one('SELECT backup_dir FROM update_history WHERE id = ?', [$this->idParam()]);
        $type = Http::query('type') === 'files' ? 'files' : 'database';
        if ($h === null) {
            $this->notFound('backup');
        }
        $dir = Backup::dir() . '/' . basename((string) $h['backup_dir']);
        $file = $type === 'files' ? "$dir/files.zip" : (is_file("$dir/database.sql.gz") ? "$dir/database.sql.gz" : "$dir/database.sql");
        if (!is_file($file)) {
            $this->notFound('backup file');
        }
        Audit::log('system.backup.download', 'update', basename($dir), ['type' => $type]);
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="motosupply-' . basename($dir) . '-' . basename($file) . '"');
        header('Content-Length: ' . filesize($file));
        readfile($file);
    }
}
