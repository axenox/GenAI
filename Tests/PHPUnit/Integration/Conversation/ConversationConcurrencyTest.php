<?php
namespace axenox\GenAI\Tests\PHPUnit\Integration\Conversation;

use PHPUnit\Framework\TestCase;

/**
 * Exercises allocation in independent authenticated processes against the installed database.
 * 
 * @author GitHub Copilot
 */
class ConversationConcurrencyTest extends TestCase
{
    /**
     * Concurrent appends retain all messages while each request advances its own sequence.
     * 
     * @return void
     */
    public function testConcurrentMessageAllocation() : void
    {
        $selector = $this->selector();
        $conversationId = $this->runWorker('create', $selector, 'unused')['uid'];
        $barrier = tempnam(sys_get_temp_dir(), 'genai-barrier-');
        unlink($barrier);
        try {
            $first = $this->startWorker('write', $selector, $conversationId, $barrier);
            $second = $this->startWorker('write', $selector, $conversationId, $barrier);
            $this->releaseWorkers([$first, $second], $barrier);
            $firstResult = $this->finishWorker($first);
            $secondResult = $this->finishWorker($second);
            self::assertSame(4, $firstResult['written']);
            self::assertSame(4, $secondResult['written']);
            $sequences = $this->runWorker('inspect', $selector, $conversationId)['sequences'];
            self::assertCount(8, $sequences);
            self::assertSame([0, 0, 1, 1, 2, 2, 3, 3], $sequences);
        } finally {
            if (is_file($barrier)) {
                unlink($barrier);
            }
            $this->runWorker('cleanup', $selector, $conversationId);
        }
    }

    /**
     * Concurrent first requests for the same authenticated chat resolve to one durable group.
     * 
     * @return void
     */
    public function testConcurrentMcpGroupCreation() : void
    {
        $selector = $this->selector();
        $identity = 'genai-concurrency-' . bin2hex(random_bytes(16));
        $barrier = tempnam(sys_get_temp_dir(), 'genai-barrier-');
        unlink($barrier);
        $ids = [];
        try {
            $first = $this->startWorker('group', $selector, $identity, $barrier);
            $second = $this->startWorker('group', $selector, $identity, $barrier);
            $this->releaseWorkers([$first, $second], $barrier);
            $ids[] = $this->finishWorker($first)['uid'];
            $ids[] = $this->finishWorker($second)['uid'];
            self::assertSame($ids[0], $ids[1]);
        } finally {
            if (is_file($barrier)) {
                unlink($barrier);
            }
            foreach (array_unique($ids) as $id) {
                $this->runWorker('cleanup', $selector, $id);
            }
        }
    }

    /**
     * Requires explicit provisioning rather than assuming a project-specific endpoint.
     * 
     * @return string
     */
    private function selector() : string
    {
        $selector = getenv('GENAI_TEST_HANDLER');
        if ($selector === false || $selector === '') {
            self::markTestSkipped('Set GENAI_TEST_HANDLER to an installed MCP handler.');
        }
        return $selector;
    }

    /**
     * Executes one short-lived fixture operation.
     * 
     * @param string $mode
     * @param string $selector
     * @param string $identity
     * @return array<array-key,mixed>
     */
    private function runWorker(string $mode, string $selector, string $identity) : array
    {
        return $this->finishWorker($this->startWorker($mode, $selector, $identity));
    }

    /**
     * Releases writers only after both have loaded the same conversation maximum.
     *
     * @param array[] $workers
     * @param string $barrier
     * @return void
     */
    private function releaseWorkers(array $workers, string $barrier) : void
    {
        $deadline = microtime(true) + 10;
        foreach ($workers as $worker) {
            while (!is_file($worker[3])) {
                if (microtime(true) > $deadline) {
                    throw new \RuntimeException('Concurrent fixture readiness timed out.');
                }
                usleep(1000);
            }
        }
        touch($barrier);
    }

    /**
     * Starts a worker with file-backed output so errors cannot deadlock redirected pipes.
     * 
     * @throws \RuntimeException
     * @param string $mode
     * @param string $selector
     * @param string $identity
     * @param string|null $barrier
     * @return array<array-key,mixed>
     */
    private function startWorker(string $mode, string $selector, string $identity, ?string $barrier = null) : array
    {
        $stdout = tempnam(sys_get_temp_dir(), 'genai-stdout-');
        $stderr = tempnam(sys_get_temp_dir(), 'genai-stderr-');
        $ready = $stdout . '.ready';
        $command = [PHP_BINARY, dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'Support' . DIRECTORY_SEPARATOR . 'ConversationWorker.php', $mode, $selector, $identity];
        if ($barrier !== null) {
            $command[] = $barrier;
            $command[] = $ready;
        }
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', $stdout, 'w'], 2 => ['file', $stderr, 'w']], $pipes);
        if (!is_resource($process)) {
            unlink($stdout);
            unlink($stderr);
            throw new \RuntimeException('Unable to start a conversation fixture worker.');
        }
        fclose($pipes[0]);
        return [$process, $stdout, $stderr, $ready];
    }

    /**
     * Reports worker failures and removes only its explicitly allocated temporary files.
     * 
     * @param array<array-key,mixed> $worker
     * @return array<array-key,mixed>
     */
    private function finishWorker(array $worker) : array
    {
        [$process, $stdout, $stderr, $ready] = $worker;
        try {
            $exit = proc_close($process);
            $output = file_get_contents($stdout);
            $errors = file_get_contents($stderr);
            self::assertSame(0, $exit, $errors . $output);
            self::assertSame('', $errors);
            return json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        } finally {
            unlink($stdout);
            unlink($stderr);
            if (is_file($ready)) {
                unlink($ready);
            }
        }
    }
}
