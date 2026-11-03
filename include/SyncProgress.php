<?php declare(strict_types=1);

namespace Indexer;

/**
 * Keeps a long blockchain sync resumable, and shows the watchdog that it is alive.
 *
 * The sync commits a checkpoint range only as a whole, after its SMT root is verified, so on
 * its own PARAM_LAST_PROCESSED_COIN_BLOCK moves only at checkpoints. A range can be a long
 * stretch of blocks without a single command - hours of scanning over a slow link - and a
 * run that broke off inside one used to start it again from the beginning. Meanwhile the
 * lock file kept the run's start time, so the watchdog took a healthy run for a stalled one.
 *
 * Every SYNC_PROGRESS_SAVE_SEC, scanned() looks at how far the run has got:
 *
 *   - no further than at the last look: nothing is done. The lock keeps its time, so a run
 *     that stays stuck is reported once SYNC_LOCK_STALE_SEC has passed.
 *   - further: the lock file's mtime moves to now (LockFileUtils::refreshLock()), and while
 *     the range has given no command yet, the block reached is saved as
 *     PARAM_LAST_PROCESSED_COIN_BLOCK, so the next run carries on from there.
 *
 * Saving is safe exactly while no command has been found: up to that block nothing has
 * changed - not the domain tables, not the SMT, not Protocol's domain state - so the state
 * held is the state as of that block. The block lies below a checkpoint already anchored, so
 * no reorg reaches it, and the save is made outside the range's XA transaction, before it
 * starts. The sync loop resumes from any block: it takes the next checkpoint above it. Once a
 * command is found, the rest of the range is committed with its checkpoint, as before, and
 * the checkpoint's own block is never saved here: the cursor reaches a checkpoint only
 * through the verified commit.
 */
final class SyncProgress
{
    // The block reached at the last look that found progress, and when scanned() last looked
    private int $beatBlock;
    private int $beatTs;
    // The block reached at the last progress line in the log, and when it was written
    private int $logBlock;
    private int $logTs;

    /**
     * @param string $lockFilePath the sync lock this run holds
     * @param Database $db
     * @param int $lastProcessedBlock PARAM_LAST_PROCESSED_COIN_BLOCK as the run found it
     */
    public function __construct(
        private readonly string $lockFilePath,
        private readonly Database $db,
        int $lastProcessedBlock
    ) {
        $this->beatBlock = $this->logBlock = $lastProcessedBlock;
        $this->beatTs = $this->logTs = time();
    }

    /**
     * Called each time the run has fully scanned up to $block.
     *
     * @param int $block the last block scanned
     * @param int $checkpointBlock the block of the checkpoint the range ends at
     * @param bool $noCommandYet whether the range has given no command up to $block
     * @return void
     * @throws \RuntimeException
     */
    public function scanned(int $block, int $checkpointBlock, bool $noCommandYet): void
    {
        $now = time();
        if ($now - $this->beatTs < SYNC_PROGRESS_SAVE_SEC) {
            return;
        }
        $this->beatTs = $now;

        if ($block <= $this->beatBlock) {
            return;
        }
        $this->beatBlock = $block;

        if ($noCommandYet && $block < $checkpointBlock) {
            setParams([PARAM_LAST_PROCESSED_COIN_BLOCK => $block], $this->db);
        }
        LockFileUtils::refreshLock($this->lockFilePath);

        if ($now - $this->logTs >= SYNC_PROGRESS_LOG_SEC) {
            logEvent(
                'Scanned through block ' . $block . ' of ' . $checkpointBlock . ' (' .
                round(($block - $this->logBlock) / ($now - $this->logTs), 1) . ' blocks/s)',
                'Blockchain sync',
                'indexer.debug'
            );
            $this->logBlock = $block;
            $this->logTs = $now;
        }
    }

    /**
     * Called once a range is committed: its checkpoint's block is now
     * PARAM_LAST_PROCESSED_COIN_BLOCK, saved together with the range's data. That is progress
     * like any other.
     *
     * @param int $checkpointBlock
     * @return void
     */
    public function committed(int $checkpointBlock): void
    {
        if ($checkpointBlock > $this->beatBlock) {
            $this->beatBlock = $checkpointBlock;
            LockFileUtils::refreshLock($this->lockFilePath);
        }
    }
}
