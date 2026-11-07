<?php
namespace axenox\GenAI\Tests\PHPUnit\Integration\Schema;

use PHPUnit\Framework\TestCase;

/**
 * Opt-in real-engine checks on isolated fixture tables, never application rows.
 */
class SharedConversationMySqlMigrationTest extends TestCase
{
    private \PDO $connection;
    private array $tables = [];

    protected function setUp(): void
    {
        $dsn = getenv('GENAI_SCHEMA_TEST_DSN');
        if (! $dsn) {
            $this->markTestSkipped('Set GENAI_SCHEMA_TEST_DSN for a dedicated, provisioned genai_schema_test database.');
        }
        if (! preg_match('/^mysql:.*dbname=(genai_schema_test(?:_[a-z0-9]+)*)(?:;|$)/', $dsn, $match)) {
            throw new \RuntimeException('Migration fixtures require a dedicated genai_schema_test database; application databases are forbidden.');
        }
        $this->connection = new \PDO($dsn, getenv('GENAI_SCHEMA_TEST_USER') ?: '',
            getenv('GENAI_SCHEMA_TEST_PASSWORD') ?: '', [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC
            ]);
        $this->assertSame($match[1], $this->connection->query('SELECT DATABASE()')->fetchColumn());
        $suffix = bin2hex(random_bytes(6));
        foreach (['conversation', 'message', 'tool_call', 'archive'] as $name) {
            $this->tables[$name] = 'genai_schema_test_' . $suffix . '_' . $name;
        }
        $c = $this->tables['conversation'];
        $m = $this->tables['message'];
        $t = $this->tables['tool_call'];
        $this->connection->exec("CREATE TABLE $c (oid binary(16) PRIMARY KEY, data text) ENGINE=InnoDB");
        $this->connection->exec("CREATE TABLE $m (
            oid binary(16) PRIMARY KEY, ai_conversation_oid binary(16) NOT NULL,
            created_on datetime NOT NULL, sequence_number int NULL,
            role varchar(30), message text, data text,
            cost decimal(18,6) NULL, tokens_prompt int NULL,
            tokens_completion int NULL, model varchar(100) NULL
        ) ENGINE=InnoDB");
        $this->connection->exec("CREATE TABLE $t (
            oid binary(16) PRIMARY KEY, ai_conversation_oid binary(16) NOT NULL,
            ai_message_oid binary(16) NOT NULL, call_index int NOT NULL,
            call_id varchar(255) NOT NULL, tool_name varchar(255),
            tool_alias varchar(255), arguments text, result text,
            failed smallint NULL, execution_time_ms int NULL,
            UNIQUE KEY exf_ai_tool_call_conversation_call
                (ai_conversation_oid, call_id)
        ) ENGINE=InnoDB");
    }

    protected function tearDown(): void
    {
        if (! isset($this->connection)) {
            return;
        }
        foreach (['genai_legacy_pairs', 'genai_legacy_calls',
            'genai_legacy_candidates', 'genai_message_sequence'] as $temporary) {
            $this->connection->exec('DROP TEMPORARY TABLE IF EXISTS ' . $temporary);
        }
        foreach (array_reverse($this->tables) as $table) {
            $this->connection->exec('DROP TABLE IF EXISTS ' . $table);
        }
    }

    public function testRealSchemaAndConservativeLegacyTransformations(): void
    {
        $c = $this->tables['conversation'];
        $m = $this->tables['message'];
        $t = $this->tables['tool_call'];
        for ($i = 1; $i <= 8; $i++) {
            $conversation = $this->id($i);
            $this->connection->exec("INSERT INTO $c (oid) VALUES ($conversation)");
            $request = $this->id(100 + $i);
            $result = $this->id(200 + $i);
            $callId = 'call_' . $i;
            $resultId = $i === 3 ? 'mismatched' : $callId;
            $requestData = json_encode(['tool_calls' => [
                ['id' => $callId, 'function' => ['name' => 'example']]
            ]], JSON_THROW_ON_ERROR);
            $resultData = json_encode([['callId' => $resultId, 'toolResult' => new \stdClass()]],
                JSON_THROW_ON_ERROR);
            $requestUsage = $i === 4 ? '0' : 'NULL';
            $resultUsage = $i === 4 ? '0' : 'NULL';
            $this->insertMessage(100 + $i, $i, 'tool calling', $requestData, null, $requestUsage);
            $this->insertMessage(200 + $i, $i, 'tool', $resultData, 1, $resultUsage);
            if ($i === 2) {
                $this->insertMessage(300 + $i, $i, 'warning', '{}', null);
            }
            if ($i === 5) {
                $this->insertMessage(300 + $i, $i, 'user', '{}', null);
            }
            $detailResult = $i === 6 ? 'NULL' : $this->text('{"numeric":"00123"}');
            $failed = $i === 7 ? 'NULL' : ($i === 8 ? '1' : '0');
            $this->connection->exec("INSERT INTO $t VALUES (
                {$this->id(400 + $i)}, $conversation, $request, 1,
                {$this->text($callId)}, 'example', NULL,
                {$this->text('{\"id\":\"00123\",\"empty\":{},\"list\":[]}')},
                $detailResult, $failed, 12
            )");
        }
        $this->applyMigration('20261106_2110_1_NEW_shared_conversation.sql');
        $rows = $this->connection->query("SELECT type, next_message_sequence FROM $c")
            ->fetchAll();
        foreach ($rows as $row) {
            $this->assertSame('agent', $row['type']);
            $this->assertGreaterThanOrEqual(2, (int) $row['next_message_sequence']);
        }
        $states = $this->connection->query("SELECT state FROM $t ORDER BY oid")->fetchAll();
        $this->assertSame('IN_PROGRESS', $states[5]['state']);
        $this->assertSame('IN_PROGRESS', $states[6]['state']);
        $this->assertSame('FAILED', $states[7]['state']);

        $this->applyMigration('20261106_2140_1_MIGRATE_legacy_tool_batches.sql');
        $rows = $this->connection->query("SELECT oid, role, data, tokens_prompt
            FROM $m WHERE oid IN ({$this->id(101)}, {$this->id(102)}, {$this->id(108)})
            ORDER BY oid")->fetchAll();
        foreach ($rows as $row) {
            $this->assertSame('tool', $row['role']);
            $data = json_decode($row['data'], false, 512, JSON_THROW_ON_ERROR);
            $this->assertCount(1, $data->call_order);
            $callId = $data->call_order[0];
            $this->assertSame('{"numeric":"00123"}', $data->results->$callId->value);
            $this->assertSame('00123', $data->calls->$callId->arguments->id);
            $this->assertInstanceOf(\stdClass::class, $data->calls->$callId->arguments->empty);
            $this->assertSame([], $data->calls->$callId->arguments->list);
            $this->assertNull($row['tokens_prompt']);
            $this->assertStringStartsWith('0x', $data->legacy->result_uid);
        }
        foreach ([3, 4, 5, 6, 7] as $i) {
            $rows = $this->connection->query("SELECT role FROM $m
                WHERE oid IN ({$this->id(100 + $i)}, {$this->id(200 + $i)}) ORDER BY oid")
                ->fetchAll();
            $this->assertCount(2, $rows, 'Unresolved history must remain intact.');
            $this->assertSame('tool calling', $rows[0]['role']);
        }
        $archive = $this->tables['archive'];
        $rows = $this->connection->query("SELECT COUNT(*) AS total FROM $archive")->fetchAll();
        $this->assertSame(3, (int) $rows[0]['total']);
        $rows = $this->connection->query("SELECT COUNT(*) AS total FROM $m WHERE role = 'warning'")
            ->fetchAll();
        $this->assertSame(1, (int) $rows[0]['total']);
    }

    private function insertMessage(int $id, int $conversation, string $role, string $data,
        ?int $sequence, string $usage = 'NULL'): void
    {
        $m = $this->tables['message'];
        $sequenceSql = $sequence === null ? 'NULL' : (string) $sequence;
        // Equal timestamps and null sequences deliberately exercise UID tie-breaks.
        $this->connection->exec("INSERT INTO $m VALUES (
            {$this->id($id)}, {$this->id($conversation)}, '2026-01-01 00:00:00',
            $sequenceSql, {$this->text($role)}, 'retained display', {$this->text($data)},
            NULL, $usage, NULL, NULL
        )");
    }

    private function applyMigration(string $name): void
    {
        $sql = file_get_contents(dirname(__DIR__, 4)
            . '\\Install\\Sql\\MySQL\\Migrations\\1.42\\' . $name);
        $sql = explode('-- DOWN', explode('-- UP', $sql, 2)[1], 2)[0];
        $sql = str_replace(
            ['trash_exf_ai_message_tool_result', 'exf_ai_conversation',
                'exf_ai_tool_call', 'exf_ai_message'],
            [$this->tables['archive'], $this->tables['conversation'],
                $this->tables['tool_call'], $this->tables['message']],
            $sql
        );
        // Constraint/index names are local to fixture tables in MySQL.
        $sql = str_replace($this->tables['tool_call'] . '_conversation_call',
            'exf_ai_tool_call_conversation_call', $sql);
        foreach (preg_split('/;\R/', $sql) as $statement) {
            if (trim(preg_replace('/--[^\r\n]*/', '', $statement)) !== '') {
                $this->connection->exec($statement);
            }
        }
    }

    private function id(int $number): string
    {
        return "UNHEX('" . str_pad(dechex($number), 32, '0', STR_PAD_LEFT) . "')";
    }

    private function text(string $value): string
    {
        return "CONVERT(0x" . bin2hex($value) . " USING utf8mb4)";
    }
}
