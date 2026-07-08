<?php

namespace DocForge\Core;

/**
 * Writes job progress from pipeline events (SPECS §8).
 */
class ProgressWriter
{
    /** @var \PDO */
    private $pdo;

    /** @var string */
    private $jobId;

    public function __construct(\PDO $pdo, $jobId)
    {
        $this->pdo = $pdo;
        $this->jobId = $jobId;
    }

    public function update($phase, $stage, $tool, $percent)
    {
        $stmt = $this->pdo->prepare(
            'UPDATE df_jobs SET phase = ?, stage = ?, tool = ?, percent = ?, updated_at = NOW() WHERE id = ?'
        );
        $stmt->execute(array($phase, $stage, $tool, (int) $percent, $this->jobId));
    }

    public function markRunning()
    {
        $stmt = $this->pdo->prepare(
            "UPDATE df_jobs SET state = 'running', updated_at = NOW() WHERE id = ?"
        );
        $stmt->execute(array($this->jobId));
    }

    public function markComplete()
    {
        $stmt = $this->pdo->prepare(
            "UPDATE df_jobs SET state = 'complete', percent = 100, updated_at = NOW() WHERE id = ?"
        );
        $stmt->execute(array($this->jobId));
    }

    /** @param string $error */
    public function markFailed($error)
    {
        $stmt = $this->pdo->prepare(
            "UPDATE df_jobs SET state = 'failed', error = ?, updated_at = NOW() WHERE id = ?"
        );
        $stmt->execute(array($error, $this->jobId));
    }
}
