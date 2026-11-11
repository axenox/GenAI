<?php
namespace axenox\GenAI\Common\Mcp;

use axenox\GenAI\AI\Agents\McpServer;
use axenox\GenAI\DataTypes\AiConversationTypeDataType;
use axenox\GenAI\Factories\AiFactory;
use axenox\GenAI\Interfaces\AiConversationInterface;
use exface\Core\DataTypes\ComparatorDataType;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\Interfaces\DataSheets\DataSheetInterface;
use exface\Core\Exceptions\DataSources\DataQueryUniqueConstraintError;
use exface\Core\Exceptions\Behaviors\ConcurrentWriteError;

/**
 * Resolves sliding MCP groups with short compare-and-swap transactions and a unique active key.
 * 
 * @author GitHub Copilot
 */
class McpConversationResolver
{
    /**
     * Namespaces grouping by authenticated user, exact endpoint version and configured scope.
     * 
     * @param string $user
     * @param string $endpoint
     * @param string $scope
     * @param string $session
     * @param string|null $chat
     * @return string
     */
    public static function groupKey(string $user, string $endpoint, string $scope, string $session, ?string $chat) : string
    {
        return hash('sha256', json_encode(
            [AiConversationTypeDataType::MCP, $user, $endpoint, $scope, $scope === 'session' ? $session : null, $chat],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ));
    }

    /**
     * Applies the sliding inactivity window only when no explicit chat ID was supplied.
     * 
     * @throws \UnexpectedValueException
     * @param array<array-key,mixed> $data
     * @param float $receivedAt
     * @param int $timeout
     * @param string|null $chat
     * @return bool
     */
    public static function isReusable(array $data, float $receivedAt, int $timeout, ?string $chat) : bool
    {
        if ($chat !== null) {
            return true;
        }
        if (!isset($data['last_activity_at']) || !is_numeric($data['last_activity_at'])) {
            throw new \UnexpectedValueException('MCP conversation has no valid grouping activity timestamp.');
        }
        return $receivedAt - (float) $data['last_activity_at'] < $timeout;
    }

    /**
     * Recreates an authorized conversation inside the current operation scope.
     * 
     * @param McpServer $handler
     * @param array<array-key,mixed> $request
     * @return AiConversationInterface
     */
    public function resolve(McpServer $handler, array $request) : AiConversationInterface
    {
        return AiFactory::createConversation($handler, $this->allocate($handler, $request), ['TYPE' => AiConversationTypeDataType::MCP]);
    }

    /**
     * Allocates or touches the unique active group with bounded contention retries.
     * 
     * @throws \UnexpectedValueException
     * @throws \RuntimeException
     * @param McpServer $handler
     * @param array<array-key,mixed> $request
     * @return string
     */
    private function allocate(McpServer $handler, array $request) : string
    {
        $workbench = $handler->getWorkbench();
        $user = $workbench->getSecurity()->getAuthenticatedUser()->getUid();
        $scope = $handler->getConversationGroupingScope();
        $key = self::groupKey($user, $handler->getUid() . ':' . $handler->getVersion(), $scope, $request['session_id'], $request['chat_id']);
        $lastError = null;
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $transaction = $workbench->data()->startTransaction();
            try {
                $sheet = $this->sheet($handler, $user, $key);
                $sheet->getColumns()->addFromSystemAttributes();
                $sheet->getColumns()->addMultiple(['UID', 'DATA']);
                $sheet->dataRead();
                if (!$sheet->isEmpty()) {
                    $row = $sheet->getRow(0);
                    $data = json_decode((string) $row['DATA'], true, 512, JSON_THROW_ON_ERROR);
                    if (!is_array($data)) {
                        throw new \UnexpectedValueException('Invalid MCP conversation grouping data.');
                    }
                    $reusable = self::isReusable(
                        $data, $request['received_at'], $handler->getConversationInactivityTimeoutSeconds(), $request['chat_id']
                    );
                    $update = $sheet->extractSystemColumns();
                    $update->getFilters()->addConditionFromString('UID', $row['UID'], ComparatorDataType::EQUALS);
                    $update->getFilters()->addConditionFromString('DATA', $row['DATA'], ComparatorDataType::EQUALS);
                    if ($reusable) {
                        // Receipt order can differ from database acquisition order.
                        $data['last_activity_at'] = max($request['received_at'], (float) $data['last_activity_at']);
                        $data['activity_revision'] = (int) ($data['activity_revision'] ?? 0) + 1;
                        $update->getColumns()->addFromExpression('DATA');
                        $update->setCellValue('DATA', 0, json_encode($data, JSON_THROW_ON_ERROR));
                    } else {
                        $update->getColumns()->addFromExpression('MCP_GROUP_KEY');
                        $update->setCellValue('MCP_GROUP_KEY', 0, null);
                    }
                    if ($update->dataUpdate(false, $transaction) !== 1) {
                        $transaction->rollback();
                        continue;
                    }
                    if ($reusable) {
                        $transaction->commit();
                        return $row['UID'];
                    }
                }

                $new = DataSheetFactory::createFromObjectIdOrAlias($workbench, 'axenox.GenAI.AI_CONVERSATION');
                $new->getColumns()->addFromSystemAttributes();
                $new->addRow([
                    'AI_AGENT' => $handler->getUid(),
                    'AI_AGENT_VERSION_NO' => $handler->getVersion(),
                    'USER' => $user,
                    'TYPE' => AiConversationTypeDataType::MCP,
                    'TOPIC_ID' => $request['chat_id'] ?? $request['session_id'],
                    'TITLE' => $handler->getName() . ' — ' . $request['tool_name'] . ' — ' . $request['started_on'],
                    'DEVMODE' => $handler->getDevmode() ? 1 : 0,
                    'MCP_GROUP_KEY' => $key,
                    'DATA' => json_encode([
                        'source' => AiConversationTypeDataType::MCP,
                        'scope' => $scope,
                        'session_id' => $request['session_id'],
                        'native_session_id' => $request['native_session_id'],
                        'chat_id' => $request['chat_id'],
                        'last_activity_at' => $request['received_at'],
                        'activity_revision' => 1
                    ], JSON_THROW_ON_ERROR)
                ]);
                $new->dataCreate(false, $transaction);
                $id = $new->getUidColumn()->getValue(0);
                $transaction->commit();
                return $id;
            } catch (\Throwable $error) {
                if (!$transaction->isCommitted() && !$transaction->isRolledBack()) {
                    $transaction->rollback();
                }
                $lastError = $error;
                $cause = $error;
                while (!$cause instanceof DataQueryUniqueConstraintError && !$cause instanceof ConcurrentWriteError) {
                    $cause = $cause->getPrevious();
                    if ($cause === null) {
                        throw $error;
                    }
                }
            }
        }
        throw new \RuntimeException('Unable to allocate an MCP conversation after bounded contention retries.', 0, $lastError);
    }

    /**
     * Restricts group lookup to the authenticated owner and exact handler version.
     * 
     * @param McpServer $handler
     * @param string $user
     * @param string $key
     * @return DataSheetInterface
     */
    private function sheet(McpServer $handler, string $user, string $key) : DataSheetInterface
    {
        $sheet = DataSheetFactory::createFromObjectIdOrAlias($handler->getWorkbench(), 'axenox.GenAI.AI_CONVERSATION');
        foreach ([
            'MCP_GROUP_KEY' => $key, 'USER' => $user, 'AI_AGENT' => $handler->getUid(),
            'AI_AGENT_VERSION_NO' => $handler->getVersion(), 'TYPE' => AiConversationTypeDataType::MCP
        ] as $attribute => $value) {
            $sheet->getFilters()->addConditionFromString($attribute, $value, ComparatorDataType::EQUALS);
        }
        return $sheet;
    }
}
