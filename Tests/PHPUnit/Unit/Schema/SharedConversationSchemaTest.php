<?php
namespace axenox\GenAI\Tests\PHPUnit\Unit\Schema;

use axenox\GenAI\DataTypes\AiToolCallStateDataType;
use PHPUnit\Framework\TestCase;

class SharedConversationSchemaTest extends TestCase
{
    public function testAllSupportedDialectsExposeTheSharedContract(): void
    {
        foreach (['MySQL', 'MsSQL', 'PostgreSQL'] as $dialect) {
            $sql = file_get_contents(dirname(__DIR__, 4) . '\\Install\\Sql\\' . $dialect
                . '\\Migrations\\1.42\\20261106_2110_1_NEW_shared_conversation.sql');
            $this->assertStringContainsString('-- UP', $sql, $dialect);
            $this->assertStringContainsString('-- DOWN', $sql, $dialect);
            foreach (['topic_id', 'next_message_sequence', 'mcp_group_key',
                'started_on', 'finished_on', 'trash_failed'] as $field) {
                $this->assertStringContainsString($field, $sql, $dialect);
            }
            $this->assertMatchesRegularExpression("/DEFAULT\\s+'agent'/i", $sql);
            $this->assertMatchesRegularExpression('/DEFAULT\\s+0/i', $sql);
            $this->assertMatchesRegularExpression('/UNIQUE\\s*\\(ai_message_oid, call_id\\)/i', $sql);
            $this->assertMatchesRegularExpression(
                '/UNIQUE\\s*\\(ai_conversation_oid, sequence_number\\)/i', $sql);
            $this->assertStringContainsString('ROW_NUMBER() OVER', $sql);
            $this->assertStringContainsString("'SUCCEEDED'", $sql);
            $this->assertStringContainsString("'IN_PROGRESS'", $sql);
            $this->assertStringNotContainsString('CREATE TYPE', $sql);
        }
        $sql = file_get_contents(dirname(__DIR__, 4)
            . '\\Install\\Sql\\MsSQL\\Migrations\\1.42\\20261106_2110_1_NEW_shared_conversation.sql');
        $this->assertStringContainsString('WHERE mcp_group_key IS NOT NULL', $sql);
    }

    public function testLegacyScriptsRetainIdentitiesAndRequireExplicitCorrelation(): void
    {
        foreach (['MySQL', 'MsSQL', 'PostgreSQL'] as $dialect) {
            $sql = file_get_contents(dirname(__DIR__, 4) . '\\Install\\Sql\\' . $dialect
                . '\\Migrations\\1.42\\20261106_2140_1_MIGRATE_legacy_tool_batches.sql');
            foreach (['trash_exf_ai_message_tool_result', 'request_uid', 'result_uid',
                'request_data', 'result_data', 'request_usage', 'result_usage',
                'call_index', 'callId', 'retained_result', 'tokens_prompt IS NOT NULL',
                "NOT IN ('warning', 'error')"] as $contract) {
                $this->assertStringContainsString($contract, $sql, $dialect);
            }
            $this->assertStringContainsString('COUNT(c.retained_result) = COUNT(*)', $sql);
        }
    }

    public function testExecutionStatesArePortableStrings(): void
    {
        new \exface\Core\CommonLogic\Workbench(['DEBUG.LIVE_CLASS_AUTOLOADER' => false]);
        $this->assertSame('IN_PROGRESS', AiToolCallStateDataType::IN_PROGRESS);
        $this->assertSame('SUCCEEDED', AiToolCallStateDataType::SUCCEEDED);
        $this->assertSame('FAILED', AiToolCallStateDataType::FAILED);
        foreach (['IN_PROGRESS', 'SUCCEEDED', 'FAILED'] as $state) {
            $this->assertSame($state, AiToolCallStateDataType::cast($state));
        }
    }
}
