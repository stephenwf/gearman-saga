<?php

namespace GearmanSaga;

use Closure;
use GearmanClient;
use GearmanTask;
use Generator;
use React\Promise\Deferred;
use React\Promise\Promise;
use function React\Promise\all;

final class GearmanSaga
{
    public $tasks = [];
    private $client;
    private $promises = [];

    public function __construct(GearmanClient $client)
    {
        $this->client = $client;
        $tasks = &$this->tasks;
        $promises = &$this->promises;
        $client->setCompleteCallback(Closure::bind(function (GearmanTask $e) use (&$tasks, &$promises) {
            $deferred = $tasks[$e->unique()];
            unset($promises[$e->unique()]);
            unset($tasks[$e->unique()]);
            if ($deferred && $deferred instanceof Deferred) {
                $deferred->resolve($e);
            }

            return GEARMAN_SUCCESS;
        }, $this));
    }

    public function run()
    {
        if (!empty($this->promises)) {
            all($this->promises)
                ->then(
                    Closure::bind(function () {
                        $this->run();
                    }, $this)
                );
            $this->client->runTasks();
        } else {
            exit('fin.'.PHP_EOL);
        }
    }

    public function addSaga($saga, $data = null)
    {
        if (is_callable($saga)) {
            $saga = $saga($data);
        }
        $this->runSaga($saga);
    }

    public function runSaga(Generator $next)
    {
        if ($next->valid()) {
            $items = $next->current();
            if ($items instanceof GearmanBatch) {
                $this->stepThroughAll($items, $next);
            } else {
                $task = array_shift($items);
                $data = array_shift($items);
                $this->stepThrough($task, $data, $next);
            }
        }
    }

    public function stepThroughAll(GearmanBatch $jobs, Generator &$next)
    {
        $items = [];
        foreach ($jobs->getCommands() as $job) {
            $task = array_shift($job);
            $data = array_shift($job);
            $t = $this->addTask($task, $data);
            $items[] = $t;
        }
        all($items)->then(Closure::bind(
            function (...$data) use (&$next) {
                if ($next->valid()) {
                    $next->send(
                        array_map(
                            function (GearmanTask $task) {
                                return $task->data();
                            },
                            $data
                        )
                    );
                    $this->runSaga($next);
                }

                return $data;
            },
            $this)
        );
    }

    public function stepThrough(string $task, $data, Generator &$current)
    {
        $t = $this->addTask($task, $data);
        $t->then(
            Closure::bind(
                function (GearmanTask $data) use (&$current) {
                    if ($current->valid()) {
                        $current->send($data->data());
                        $this->runSaga($current);
                    }

                    return $data;
                },
                $this
            )
        );
    }

    public function addTask($task, $data) : Promise
    {
        $deferred = new Deferred();
        $id = uniqid('task_');
        $promise = $deferred->promise();
        $this->tasks[$id] = $deferred;
        $this->promises[$id] = $promise;
        $this->client->addTask($task, $data, $task, $id);

        return $promise;
    }
}
