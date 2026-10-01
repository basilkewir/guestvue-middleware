<?php

namespace XcVm\Public\Controllers\Admin;

use XcVm\Core\Auth\Authorization;

/**
 * MediaUploadController — resumable chunked browser upload of movie/episode media files.
 *
 * Chunked protocol (all POST, JSON responses, session + adv permission required):
 *   media_upload?op=init&name=<file>&size=<bytes>&upload_id=<32hex>  → {result, upload_id, received}
 *   media_upload?op=chunk&upload_id=<id>&offset=<bytes>  (raw body)  → {result, received}
 *   media_upload?op=status&upload_id=<id>                            → {result, received}
 *   media_upload?op=complete&upload_id=<id>                          → {result, path, size, name}
 *   media_upload?op=abort&upload_id=<id>                             → {result}
 *
 * Data is staged in tmp/media_uploads/<id>.part and moved to
 * content/movies/<safe name> on completion. Chunk size must stay below
 * php post_max_size (10M) — client uses 4 MB.
 *
 * @package XC_VM_Public_Controllers_Admin
 */
class MediaUploadController extends BaseAdminController {
    /** Client chunk size ceiling (bytes). */
    private const CHUNK_SIZE = 4194304;

    /** Allowed media extensions (lowercase, no dot). */
    private const ALLOWED_EXT = [
        'mp4', 'mkv', 'avi', 'mov', 'm4v', 'mpg', 'mpeg',
        'ts', 'm2ts', 'flv', 'wmv', 'webm', 'vob', 'rmvb', '3gp',
    ];

    public function index() {
        if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            $this->json(['result' => false, 'error' => 'Method Not Allowed'], 405);
        }
        if (!$this->canUpload()) {
            $this->json(['result' => false, 'error' => 'Forbidden'], 403);
        }

        $rOp = (string)$this->input('op', '');
        switch ($rOp) {
            case 'init':
                $this->opInit();
                break;
            case 'chunk':
                $this->opChunk();
                break;
            case 'status':
                $this->opStatus();
                break;
            case 'complete':
                $this->opComplete();
                break;
            case 'abort':
                $this->opAbort();
                break;
            default:
                $this->json(['result' => false, 'error' => 'Unknown operation'], 400);
        }
    }

    /**
     * Any VOD-related adv capability grants upload access
     * (same keys the movie/episode/series forms already require).
     */
    private function canUpload(): bool {
        $rKeys = [
            'add_movie', 'edit_movie', 'import_movies',
            'add_episode', 'edit_episode',
            'add_series', 'edit_series',
        ];
        foreach ($rKeys as $rKey) {
            if (Authorization::check('adv', $rKey)) {
                return true;
            }
        }
        return false;
    }

    // ── paths ────────────────────────────────────────────────

    private function rootDir(): string {
        if (defined('MAIN_HOME')) {
            return MAIN_HOME;
        }
        if (defined('CONTENT_PATH')) {
            return dirname(CONTENT_PATH) . '/';
        }
        return '/home/xc_vm/';
    }

    private function stageDir(): string {
        $rBase = defined('TMP_PATH') ? TMP_PATH : $this->rootDir() . 'tmp/';
        $rDir = $rBase . 'media_uploads/';
        if (!is_dir($rDir)) {
            @mkdir($rDir, 0775, true);
        }
        return $rDir;
    }

    private function destDir(): string {
        $rBase = defined('CONTENT_PATH') ? CONTENT_PATH : $this->rootDir() . 'content/';
        $rDir = $rBase . 'movies/';
        if (!is_dir($rDir)) {
            @mkdir($rDir, 0775, true);
        }
        return $rDir;
    }

    private function partPath(string $rId): string {
        return $this->stageDir() . $rId . '.part';
    }

    private function metaPath(string $rId): string {
        return $this->stageDir() . $rId . '.json';
    }

    // ── helpers ──────────────────────────────────────────────

    private function uploadId(): string {
        $rId = (string)$this->input('upload_id', '');
        if (!preg_match('/^[a-f0-9]{32}$/', $rId)) {
            $this->json(['result' => false, 'error' => 'Invalid upload id'], 400);
        }
        return $rId;
    }

    private function readMeta(string $rId): ?array {
        $rFile = $this->metaPath($rId);
        if (!is_file($rFile)) {
            return null;
        }
        $rMeta = json_decode((string)file_get_contents($rFile), true);
        return is_array($rMeta) ? $rMeta : null;
    }

    private function writeMeta(string $rId, array $rMeta): void {
        file_put_contents($this->metaPath($rId), json_encode($rMeta), LOCK_EX);
    }

    private function receivedBytes(string $rId): int {
        $rPart = $this->partPath($rId);
        if (!is_file($rPart)) {
            return 0;
        }
        clearstatcache(true, $rPart);
        return intval(filesize($rPart));
    }

    // ── operations ───────────────────────────────────────────

    private function opInit(): void {
        $rRaw = (string)$this->input('name', '');
        $rName = basename(str_replace('\\', '/', $rRaw));
        $rSafe = preg_replace('/[^\p{L}\p{N} ._()-]+/u', '_', $rName);
        if ($rSafe === null || $rSafe === '') {
            $rSafe = preg_replace('/[^A-Za-z0-9 ._()-]+/', '_', (string)preg_replace('/[^A-Za-z0-9 ._()-]+/', '', $rName));
        }
        $rSafe = ltrim($rSafe, '.');
        $rSize = intval($this->input('size', 0));
        $rExt = strtolower(pathinfo($rSafe, PATHINFO_EXTENSION));

        if ($rSafe === '' || $rSize <= 0) {
            $this->json(['result' => false, 'error' => 'Invalid file'], 400);
        }
        if (!in_array($rExt, self::ALLOWED_EXT, true)) {
            $this->json(['result' => false, 'error' => 'Unsupported file type: .' . $rExt], 400);
        }

        // Client-computed deterministic id enables resume across page reloads.
        $rId = (string)$this->input('upload_id', '');
        $rMeta = null;
        if (preg_match('/^[a-f0-9]{32}$/', $rId)) {
            $rMeta = $this->readMeta($rId);
            if ($rMeta !== null && (intval($rMeta['size'] ?? -1) !== $rSize || ($rMeta['name'] ?? '') !== $rSafe)) {
                $rMeta = null; // stale/foreign state under this id — start over
            }
        } else {
            $rId = bin2hex(random_bytes(16));
        }

        if ($rMeta === null) {
            $rFree = @disk_free_space($this->destDir());
            if ($rFree !== false && $rFree < ($rSize + 1048576)) {
                $this->json(['result' => false, 'error' => 'Not enough disk space on server'], 507);
            }
            $rMeta = ['name' => $rSafe, 'size' => $rSize, 'created' => time()];
            $this->writeMeta($rId, $rMeta);
            @unlink($this->partPath($rId));
        }

        $rReceived = $this->receivedBytes($rId);
        if ($rReceived > $rSize) {
            @unlink($this->partPath($rId));
            $rReceived = 0;
        }

        $this->json(['result' => true, 'upload_id' => $rId, 'received' => $rReceived]);
    }

    private function opChunk(): void {
        $rId = $this->uploadId();
        $rMeta = $this->readMeta($rId);
        if ($rMeta === null) {
            $this->json(['result' => false, 'error' => 'Unknown upload'], 404);
        }
        $rSize = intval($rMeta['size']);
        $rHave = $this->receivedBytes($rId);

        if ($rHave >= $rSize) {
            $this->json(['result' => true, 'received' => $rHave, 'done' => true]);
        }

        $rOffset = intval($this->input('offset', -1));
        if ($rOffset !== $rHave) {
            $this->json(['result' => false, 'conflict' => true, 'received' => $rHave], 409);
        }

        $rBody = file_get_contents('php://input');
        if ($rBody === false || $rBody === '') {
            $this->json(['result' => false, 'error' => 'Empty chunk'], 400);
        }
        $rLen = strlen($rBody);
        if ($rLen > self::CHUNK_SIZE) {
            $this->json(['result' => false, 'error' => 'Chunk too large'], 413);
        }
        if (($rHave + $rLen) > $rSize) {
            $this->json(['result' => false, 'error' => 'Chunk exceeds declared file size'], 400);
        }

        $rPart = $this->partPath($rId);
        $rFh = @fopen($rPart, 'c');
        if (!$rFh) {
            $this->json(['result' => false, 'error' => 'Cannot open staging file'], 500);
        }
        fseek($rFh, $rOffset);
        $rWritten = 0;
        while ($rWritten < $rLen) {
            $rN = fwrite($rFh, substr($rBody, $rWritten));
            if ($rN === false || $rN === 0) {
                fclose($rFh);
                $this->json(['result' => false, 'error' => 'Write failed'], 500);
            }
            $rWritten += $rN;
        }
        fflush($rFh);
        fclose($rFh);

        $this->json(['result' => true, 'received' => $this->receivedBytes($rId)]);
    }

    private function opStatus(): void {
        $rId = $this->uploadId();
        if ($this->readMeta($rId) === null) {
            $this->json(['result' => false, 'error' => 'Unknown upload'], 404);
        }
        $this->json(['result' => true, 'received' => $this->receivedBytes($rId)]);
    }

    private function opComplete(): void {
        $rId = $this->uploadId();
        $rMeta = $this->readMeta($rId);
        if ($rMeta === null) {
            $this->json(['result' => false, 'error' => 'Unknown upload'], 404);
        }
        $rSize = intval($rMeta['size']);
        $rHave = $this->receivedBytes($rId);
        if ($rHave < $rSize) {
            $this->json(['result' => false, 'received' => $rHave, 'error' => 'Upload incomplete'], 409);
        }
        if ($rHave > $rSize) {
            $this->json(['result' => false, 'received' => $rHave, 'error' => 'Staged data exceeds declared size'], 400);
        }

        $rDir = $this->destDir();
        $rName = (string)$rMeta['name'];
        $rDest = $rDir . $rName;
        if (file_exists($rDest)) {
            $rInfo = pathinfo($rName);
            $rBase = $rInfo['filename'];
            $rExtSuffix = isset($rInfo['extension']) ? '.' . $rInfo['extension'] : '';
            $rN = 1;
            do {
                $rDest = $rDir . $rBase . ' (' . $rN . ')' . $rExtSuffix;
                $rN++;
            } while (file_exists($rDest) && $rN < 1000);
        }

        $rPart = $this->partPath($rId);
        if (!@rename($rPart, $rDest)) {
            if (!@copy($rPart, $rDest)) {
                $this->json(['result' => false, 'error' => 'Cannot move file into place'], 500);
            }
            @unlink($rPart);
        }
        @chmod($rDest, 0664);
        @unlink($this->metaPath($rId));

        $this->json([
            'result' => true,
            'path'   => $rDest,
            'size'   => $rHave,
            'name'   => basename($rDest),
        ]);
    }

    private function opAbort(): void {
        $rId = $this->uploadId();
        @unlink($this->partPath($rId));
        @unlink($this->metaPath($rId));
        $this->json(['result' => true]);
    }
}
