<?php declare(strict_types=1);

namespace Indexer;

class LockFileUtils
{
    public static array $lockList = [];

    public static function setLock(string $lockFilePath): bool
    {
        if (isset(self::$lockList[$lockFilePath])) {
            return true;
        }

        $fp = fopen($lockFilePath, 'c+b');
        if ($fp === false) {
            return false;
        }
        $fl = flock($fp, LOCK_EX | LOCK_NB);

        if ($fl) {
            // Stamp the file at the moment the lock is actually acquired. fopen('c+b') does
            // not touch the mtime of a file that already exists, so without this an orphaned
            // lock file left behind by a hard-killed run would keep its old mtime and make
            // the next (perfectly healthy) holder look stalled to the age checks below.
            // The timestamp is passed explicitly on purpose: touch() without one asks the
            // OS for "now" via utime(path, NULL), which the VMware shared-folder driver
            // (fuse.vmhgfs-fuse, used for the dev VM mount) gets wrong - it stamps the
            // file with epoch+1s and still returns true, making every lock look ancient.
            touch($lockFilePath, time());
            clearstatcache(true, $lockFilePath);
            self::$lockList[$lockFilePath] = $fp;
        } else {
            fclose($fp);
        }

        return $fl;
    }

    /**
     * Whether the lock is held by THIS process, i.e. it was acquired here and not yet
     * released. Used to make sure a run only ever releases a lock it owns.
     *
     * @param string $lockFilePath
     * @return bool
     */
    public static function isOwnLock(string $lockFilePath): bool
    {
        return isset(self::$lockList[$lockFilePath]);
    }

    /**
     * Moves the mtime of a lock this process holds to now: the heartbeat of a long run.
     * Without it the mtime stays at the moment the lock was acquired, and a run that is
     * simply long - an initial sync over a slow link - looks stalled to the age checks below
     * once SYNC_LOCK_STALE_SEC has passed. Callers refresh only when the run has really got
     * further (see SyncProgress), so a run that stops moving stops refreshing and is still
     * reported.
     *
     * A lock file someone has removed is not recreated: nobody would hold that new file.
     *
     * @param string $lockFilePath
     * @return bool whether the mtime was moved
     */
    public static function refreshLock(string $lockFilePath): bool
    {
        clearstatcache(true, $lockFilePath);
        if (!self::isOwnLock($lockFilePath) || !file_exists($lockFilePath)) {
            return false;
        }

        // The timestamp is passed explicitly for the same reason as in setLock(): the VMware
        // shared-folder driver gets "now" asked for via utime(path, NULL) wrong.
        $touched = touch($lockFilePath, time());
        clearstatcache(true, $lockFilePath);

        return $touched;
    }

    /**
     * Modification time of the lock file, or null when there is no lock file at all. While a
     * run holds the lock, that is when the run last showed progress (see refreshLock()).
     * Read-only, like lockAge().
     *
     * @param string $lockFilePath
     * @return int|null
     */
    public static function lockTime(string $lockFilePath): int|null
    {
        clearstatcache(true, $lockFilePath);
        if (!file_exists($lockFilePath) || ($mTime = filemtime($lockFilePath)) === false) {
            return null;
        }

        return $mTime;
    }

    /**
     * Age in seconds of the lock file, or null when there is no lock file at all.
     * Read-only: it never creates, opens or locks anything, so it is safe to call from a
     * watchdog that must not compete with the process it is watching.
     *
     * @param string $lockFilePath
     * @return int|null
     */
    public static function lockAge(string $lockFilePath): int|null
    {
        return ($mTime = self::lockTime($lockFilePath)) === null ? null : time() - $mTime;
    }

    /**
     * Whether a stalled lock is to be reported now: the first time this stall is seen, and
     * again each time $remindSec has passed since the last report, for as long as it lasts.
     * Without this the alert would repeat on every check - every minute under cron.
     *
     * One stall is told from the next by the lock file's mtime ($lockTime), which a stalled
     * holder no longer moves. What was reported is kept in "<lock>.reported" beside the lock,
     * never in the lock file itself, which the checks must not touch (see watchdog.php). A
     * marker that can't be read or written only makes the alert repeat: it is never lost.
     *
     * @param string $lockFilePath
     * @param int $lockTime the lock file's mtime, as lockTime() returned it
     * @param int $remindSec
     * @return bool
     */
    public static function shouldReportStall(string $lockFilePath, int $lockTime, int $remindSec): bool
    {
        $markerPath = $lockFilePath . '.reported';
        $marker = @file_get_contents($markerPath);
        if (is_string($marker) && preg_match('/^(\d+) (\d+)$/', $marker, $m) === 1 &&
            (int)$m[1] === $lockTime && time() - (int)$m[2] < $remindSec) {
            return false;
        }

        @file_put_contents($markerPath, $lockTime . ' ' . time());

        return true;
    }

    public static function releaseLock(string $lockFilePath): bool
    {
        clearstatcache(true, $lockFilePath);
        if (file_exists($lockFilePath)) {
            if (self::setLock($lockFilePath) &&
                unlink($lockFilePath) && flock(self::$lockList[$lockFilePath], LOCK_UN) &&
                fclose(self::$lockList[$lockFilePath])) {
                unset(self::$lockList[$lockFilePath]);
                return true;
            }
            return false;
        }

        return true;
    }

    public static function checkLock(string $lockFilePath): bool
    {
        return file_exists($lockFilePath);
    }

    public static function cleanLocks(): void
    {
        foreach (array_keys(self::$lockList) as $oneLock) {
            self::releaseLock($oneLock);
        }
    }
}
