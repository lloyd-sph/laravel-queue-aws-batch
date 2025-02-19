<?php
/**
 * Laravel Queue for AWS Batch.
 *
 * @author    Luke Waite <lwaite@gmail.com>
 * @copyright 2017 Luke Waite
 * @license   http://www.opensource.org/licenses/mit-license.php MIT
 *
 * @link      https://github.com/dnxlabs/laravel-queue-aws-batch
 */

namespace SPHTech\LaravelQueueAwsBatch\Console;

use Illuminate\Container\Container;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Queue\Console\WorkCommand;
use Illuminate\Queue\QueueManager;
use Illuminate\Queue\Worker;
use Illuminate\Queue\WorkerOptions;
use SPHTech\LaravelQueueAwsBatch\Exceptions\JobNotFoundException;
use SPHTech\LaravelQueueAwsBatch\Exceptions\UnsupportedException;
use SPHTech\LaravelQueueAwsBatch\Queues\BatchQueue;

class QueueWorkBatchCommand extends WorkCommand
{
    protected $name = 'queue:work-batch';

    protected $description = 'Run a Job for the AWS Batch queue';

    protected $signature = 'queue:work-batch
                            {job_id : The job id in the database}
                            {connection? : The name of the queue connection to work}
                            {--memory=128 : The memory limit in megabytes}
                            {--timeout=60 : The number of seconds a child process can run}
                            {--tries=0 : Number of times to attempt a job before logging it failed}
                            {--force : Force the worker to run even in maintenance mode}
                            {--queue= : The names of the queues to work}
                            {--once : Only process the next job on the queue}
                            {--stop-when-empty : Stop when the queue is empty}';


    protected $manager;
    protected $exceptions;

    public function __construct(QueueManager $manager, Worker $worker, Handler $exceptions)
    {
        parent::__construct($worker, Container::getInstance()->make(Repository::class));
        $this->manager = $manager;
        $this->exceptions = $exceptions;
    }

    public function handle()
    {
        $this->listenForEvents();

        try {
            $this->runJob();
        } catch (\Exception $e) {
            $this->exceptions->report($e);
            throw $e;
        } catch (\Throwable $e) {
            $this->exceptions->report($e);
            throw $e;
        }
    }

    // TOOD: Refactor out the logic here into an extension of the Worker class
    protected function runJob()
    {
        $connectionName = $connection = $this->argument('connection') ?: $this->laravel['config']['queue.default'];
        $jobId = $this->argument('job_id');

        /** @var BatchQueue $connection */
        $connection = $this->manager->connection($connectionName);

        if (!$connection instanceof BatchQueue) {
            throw new UnsupportedException('queue:work-batch can only be run on batch queues');
        }

        $job = $connection->getJobById($jobId, $connectionName);

        // If we're able to pull a job off of the stack, we will process it and
        // then immediately return back out.
        if (!is_null($job)) {
            return $this->worker->process(
                $this->manager->getName($connectionName),
                $job,
                $this->gatherWorkerOptions()
            );
        }

        // If we hit this point, we haven't processed our job
        throw new JobNotFoundException('No job was returned');
    }

    /**
     * Gather all of the queue worker options as a single object.
     *
     * @return \Illuminate\Queue\WorkerOptions
     */
    protected function gatherWorkerOptions()
    {
        return new WorkerOptions(
            0, // delay
            $this->option('memory'),
            $this->option('timeout'),
            0, // sleep
            $this->option('tries'),
            false, // force
            $this->option('stop-when-empty')
        );
    }
}
