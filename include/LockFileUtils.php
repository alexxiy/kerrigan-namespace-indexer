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

        // Two tries, for the case the inode check below describes.
        for ($try = 0; $try < 2; $try++) {
            $fp = fopen($lockFilePath, 'c+b');
            if ($fp === false) {
                return false;
            }
            if (!flock($fp, LOCK_EX | LOCK_NB)) {
                fclose($fp);
                return false;
            }

            // The lock must be on the file that is at the path now. The holder can release
            // between this process's fopen() and flock(): releaseLock() unlinks the file and
            // then unlocks it, so the flock() here succeeds on a file nobody can open any more,
            // the next process creates a new one at the path and locks that, and both hold "the
            // lock". A mismatch means the lock has just been freed, so one more try takes it on
            // the file that is there. fstat() and stat() agree on the inode on the VMware
            // shared folder too (checked on the dev VM mount).
            if (!self::isAtPath($fp, $lockFilePath)) {
                flock($fp, LOCK_UN);
                fclose($fp);
                continue;
            }

            // Stamp the file at the moment the lock is actually acquired. fopen('c+b') does
            // not touch the mtime of a file that already exists, so without this an orphaned
            // lock file left behind by a hard-killed run would keep its old mtime and make
            // the next (perfectly healthy) holder look stalled to the age checks below.
            // The timestamp is passed explicitly on purpose: touch() without one asks the
            // OS for "now" via utime(path, NULL), which the VMware shared-folder driver
            // (fuse.vmhgfs-fuse, used for the dev VM mount) gets wrong - it stamps the
            // file with epoch+1s and still returns true, making every lock look ancient.
            // Only a holder ever unlinks the file, so it is still the one at the path here and
            // touch() cannot recreate it.
            touch($lockFilePath, time());
            clearstatcache(true, $lockFilePath);
            self::$lockList[$lockFilePath] = $fp;

            return true;
        }

        return false;
    }

    /**
     * Whether the open file $fp is the file at $path right now: same device and inode.
     *
     * @param resource $fp
     * @param string $path
     * @return bool
     */
    private static function isAtPath($fp, string $path): bool
    {
        clearstatcache(true, $path);
        $held = fstat($fp);
        $atPath = @stat($path);

        return $held !== false && $atPath !== false
            && $held['ino'] === $atPath['ino'] && $held['dev'] === $atPath['dev'];
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
     * A lock file someone has removed is not recreated: nobody would hold that new file. Nor is
     * a file another process has since created at the path moved: that one is its lock.
     *
     * @param string $lockFilePath
     * @return bool whether the mtime was moved
     */
    public static function refreshLock(string $lockFilePath): bool
    {
        if (!self::isOwnLock($lockFilePath) || !self::isAtPath(self::$lockList[$lockFilePath], $lockFilePath)) {
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
        // A lock whose file is no longer the one at the path (removed by hand, and maybe created
        // anew by another process since) is let go without unlinking: the file there now is not
        // this process's to remove.
        if (self::isOwnLock($lockFilePath) && !self::isAtPath(self::$lockList[$lockFilePath], $lockFilePath)) {
            flock(self::$lockList[$lockFilePath], LOCK_UN);
            fclose(self::$lockList[$lockFilePath]);
            unset(self::$lockList[$lockFilePath]);
            return true;
        }

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
