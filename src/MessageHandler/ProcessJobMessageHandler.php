<?php

namespace App\MessageHandler;

use App\Message\ProcessJobMessage;
use App\Entity\Job;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

#[AsMessageHandler]
class ProcessJobMessageHandler
{
    private EntityManagerInterface $currentEm;
    private array $stepTimings = [];

    public function __construct(
        private ManagerRegistry $doctrine,
        private string $outputDir,
        private LoggerInterface $logger,
    ) {
        $this->currentEm = $doctrine->getManager();
    }

    public function __invoke(ProcessJobMessage $message): void
    {
        $startTime = microtime(true);
        $this->stepTimings = [];

        // Log dell'inizio con contesto completo
        $this->logWithContext('JOB_STARTED', $message->jobId, [
            'em_open' => $this->currentEm->isOpen(),
            'memory_start' => memory_get_usage(true),
            'memory_peak_start' => memory_get_peak_usage(true),
            'handler_class' => __CLASS__,
            'timestamp' => date('Y-m-d H:i:s'),
        ]);

        try {
            // Inizia transazione con log
            $this->currentEm->beginTransaction();
            $this->logStep('transaction_started', $message->jobId);

            // Reset EM se necessario
            if (!$this->currentEm->isOpen()) {
                $this->logWarning('EntityManager closed - resetting', $message->jobId, [
                    'old_em_hash' => spl_object_hash($this->currentEm)
                ]);
                $this->currentEm = $this->doctrine->resetManager();
                $this->logInfo('EntityManager reset completed', $message->jobId, [
                    'new_em_hash' => spl_object_hash($this->currentEm)
                ]);
            }

            // Primo fetch
            $job = $this->findJobWithLogging($message->jobId);
            $this->logJobState('job_before_processing', $job);

            // Update status
            $this->updateJobStatusWithLogging($job, 'processing');
            $this->currentEm->flush();
            $this->currentEm->commit();
            $this->logStep('processing_status_saved', $message->jobId);

            // Lavoro lungo
            $this->logInfo('Starting long work', $message->jobId);
            sleep(10);
            $this->logStep('long_work_completed', $message->jobId);

            // Nuova connessione
            $this->logInfo('Reconnecting after long work', $message->jobId, [
                'old_em_hash' => spl_object_hash($this->currentEm)
            ]);
            $this->currentEm = $this->doctrine->getManager();
            $this->currentEm->beginTransaction();
            $this->logInfo('New connection established', $message->jobId, [
                'new_em_hash' => spl_object_hash($this->currentEm)
            ]);

            // Ricarica e completa
            $job = $this->findJobWithLogging($message->jobId);
            $this->logJobState('job_before_completion', $job);
            
            $this->completeJobWithLogging($job);
            $this->currentEm->flush();
            $this->currentEm->commit();

            // Log completamento
            $totalTime = microtime(true) - $startTime;
            $this->logWithContext('JOB_COMPLETED', $message->jobId, [
                'total_time_seconds' => round($totalTime, 2),
                'steps_timings' => $this->stepTimings,
                'memory_end' => memory_get_usage(true),
                'memory_peak_end' => memory_get_peak_usage(true),
                'output_file' => $job->getOutputFilePath(),
            ]);

        } catch (\Throwable $e) {
            $this->handleJobError($e, $message->jobId, $startTime);
            throw $this->normalizeException($e, $message->jobId);
        }
    }

    private function findJobWithLogging(int $jobId): Job
    {
        $this->logStep("finding_job_{$jobId}", $jobId);
        
        $job = $this->currentEm->getRepository(Job::class)->find($jobId);

        if (!$job) {
            $this->logError('JOB_NOT_FOUND', $jobId, [
                'repository_class' => get_class($this->currentEm->getRepository(Job::class)),
                'em_open' => $this->currentEm->isOpen(),
                'connection_status' => $this->currentEm->getConnection()->isConnected()
            ]);
            throw new UnrecoverableMessageHandlingException("Job not found: {$jobId}");
        }

        return $job;
    }

    private function updateJobStatusWithLogging(Job $job, string $status): void
    {
        $oldStatus = $job->getStatus();
        $job->setStatus($status);
        
        $this->logInfo("Job status changed", $job->getId(), [
            'from_status' => $oldStatus,
            'to_status' => $status,
            'job_entity_hash' => spl_object_hash($job)
        ]);
    }

    private function completeJobWithLogging(Job $job): void
    {
        $outputPath = $this->outputDir . '/' . pathinfo(
            $job->getInputFilePath(),
            PATHINFO_FILENAME
        ) . '.' . $job->getOutputFormat();

        $this->logInfo('Writing output file', $job->getId(), [
            'output_path' => $outputPath,
            'output_dir_exists' => is_dir($this->outputDir),
            'output_dir_writable' => is_writable($this->outputDir),
            'input_file' => $job->getInputFilePath(),
            'format' => $job->getOutputFormat()
        ]);

        try {
            $convertedContent = $this->convert($job);
            $bytesWritten = file_put_contents($outputPath, $convertedContent);
            
            if ($bytesWritten === false) {
                throw new \RuntimeException("Failed to write file: {$outputPath}");
            }

            $this->logInfo('Output file written', $job->getId(), [
                'bytes_written' => $bytesWritten,
                'file_exists' => file_exists($outputPath),
                'file_size' => file_exists($outputPath) ? filesize($outputPath) : null
            ]);

        } catch (\Throwable $e) {
            $this->logError('FILE_WRITE_FAILED', $job->getId(), [
                'output_path' => $outputPath,
                'error' => $e->getMessage(),
                'error_type' => get_class($e)
            ]);
            throw $e;
        }

        $job->setStatus('completed');
        $job->setOutputFilePath($outputPath);
        
        $this->logJobState('job_completed', $job);
    }

    private function markJobAsFailed(int $jobId, string $error, ?\Throwable $previous = null): void
    {
        try {
            $em = $this->doctrine->getManager();
            $job = $em->getRepository(Job::class)->find($jobId);

            if ($job) {
                $job->setStatus('failed');
                // Aggiungi un campo error_message se esiste nell'entità
                if (method_exists($job, 'setErrorMessage')) {
                    $job->setErrorMessage($error);
                }
                $em->flush();
                
                $this->logWarning('Job marked as failed', $jobId, [
                    'error_message' => $error,
                    'previous_exception' => $previous ? get_class($previous) : null
                ]);
            }
        } catch (\Throwable $e) {
            $this->logError('FAILED_TO_MARK_JOB_AS_FAILED', $jobId, [
                'original_error' => $error,
                'marking_error' => $e->getMessage()
            ]);
        }
    }

    private function handleJobError(\Throwable $e, int $jobId, float $startTime): void
    {
        $totalTime = microtime(true) - $startTime;
        
        // Log completo dell'errore
        $this->logWithContext('JOB_FAILED', $jobId, [
            'exception_class' => get_class($e),
            'exception_message' => $e->getMessage(),
            'exception_code' => $e->getCode(),
            'exception_file' => $e->getFile(),
            'exception_line' => $e->getLine(),
            'exception_trace' => $e->getTraceAsString(),
            'previous_exception' => $e->getPrevious() ? [
                'class' => get_class($e->getPrevious()),
                'message' => $e->getPrevious()->getMessage()
            ] : null,
            'total_time_seconds' => round($totalTime, 2),
            'steps_timings' => $this->stepTimings,
            'em_open' => $this->currentEm->isOpen(),
            'memory_at_error' => memory_get_usage(true),
            'memory_peak' => memory_get_peak_usage(true),
        ]);

        // Rollback se necessario
        if ($this->currentEm->getConnection()->isTransactionActive()) {
            try {
                $this->currentEm->rollback();
                $this->logInfo('Transaction rolled back', $jobId);
            } catch (\Throwable $rollbackError) {
                $this->logError('ROLLBACK_FAILED', $jobId, [
                    'error' => $rollbackError->getMessage()
                ]);
            }
        }

        // Marca il job come fallito
        $this->markJobAsFailed($jobId, $e->getMessage(), $e);
    }

    private function normalizeException(\Throwable $e, int $jobId): \Throwable
    {
        // Converti eccezioni in tipi appropriati per Messenger
        if ($e instanceof \Doctrine\DBAL\Exception\ConnectionException) {
            return new \Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException(
                "Database connection error: {$e->getMessage()}",
                0,
                $e
            );
        }

        if ($e instanceof \Doctrine\ORM\OptimisticLockException) {
            return new \Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException(
                "Optimistic lock exception: {$e->getMessage()}",
                0,
                $e
            );
        }

        // Per errori non recuperabili, mantieni l'originale
        return $e;
    }

    private function logJobState(string $step, Job $job): void
    {
        $this->logWithContext($step, $job->getId(), [
            'job_status' => $job->getStatus(),
            'job_input' => $job->getInputFilePath(),
            'job_output_format' => $job->getOutputFormat(),
            'job_output_path' => $job->getOutputFilePath(),
            'job_created_at' => $job->getCreatedAt()?->format('Y-m-d H:i:s'),
            'job_entity_hash' => spl_object_hash($job),
        ]);
    }

    private function logStep(string $step, ?int $jobId): void
    {
        $this->stepTimings[$step] = microtime(true);
        
        $this->logger->info("[JOB:{$jobId}] Step completed: {$step}", [
            'job_id' => $jobId,
            'step' => $step,
            'timing' => $this->stepTimings[$step],
            'memory' => memory_get_usage(true),
        ]);
    }

    private function logWithContext(string $event, ?int $jobId, array $context = []): void
    {
        $this->logger->info("[JOB:{$jobId}] {$event}", array_merge([
            'job_id' => $jobId,
            'event' => $event,
            'timestamp' => microtime(true),
        ], $context));
    }

    private function logInfo(string $message, ?int $jobId, array $context = []): void
    {
        $this->logger->info("[JOB:{$jobId}] {$message}", array_merge([
            'job_id' => $jobId
        ], $context));
    }

    private function logWarning(string $message, ?int $jobId, array $context = []): void
    {
        $this->logger->warning("[JOB:{$jobId}] {$message}", array_merge([
            'job_id' => $jobId
        ], $context));
    }

    private function logError(string $message, ?int $jobId, array $context = []): void
    {
        $this->logger->error("[JOB:{$jobId}] {$message}", array_merge([
            'job_id' => $jobId
        ], $context));
    }

    private function convert(Job $job): string
    {
        $this->logInfo('Starting conversion', $job->getId(), [
            'format' => $job->getOutputFormat()
        ]);

        $startTime = microtime(true);
        
        try {
            if ($job->getOutputFormat() === 'json') {
                $result = json_encode([
                    'converted' => true, 
                    'job_id' => $job->getId(),
                    'timestamp' => time()
                ]);
            } else {
                $result = '<?xml version="1.0"?><converted><job_id>' . $job->getId() . '</job_id><timestamp>' . time() . '</timestamp></converted>';
            }

            $this->logInfo('Conversion completed', $job->getId(), [
                'format' => $job->getOutputFormat(),
                'duration_ms' => round((microtime(true) - $startTime) * 1000, 2),
                'output_size' => strlen($result)
            ]);

            return $result;

        } catch (\Throwable $e) {
            $this->logError('CONVERSION_FAILED', $job->getId(), [
                'format' => $job->getOutputFormat(),
                'error' => $e->getMessage(),
                'duration_ms' => round((microtime(true) - $startTime) * 1000, 2)
            ]);
            throw $e;
        }
    }
}