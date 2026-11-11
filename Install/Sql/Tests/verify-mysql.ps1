$validation = @'
<?php
require '../../autoload.php';
$workbench = \exface\Core\CommonLogic\Workbench::startNewInstance();
$connection = $workbench->model()->getModelLoader()->getDataConnection();
$prefix = 'genai_test_' . bin2hex(random_bytes(6)) . '_';
$tables = ['exf_ai_conversation', 'exf_ai_message', 'exf_ai_tool_call'];
$rewrite = static fn($sql) => preg_replace_callback(
    '/\b(exf_ai_conversation|exf_ai_message|exf_ai_tool_call|genai_allow_parallel_sequences|genai_shared_conversation_schema)\b/',
    static fn($match) => $prefix . $match[0],
    $sql);
$run = static fn($sql, $multiple = false) => $connection->runSql($rewrite($sql), $multiple);
$migrate = static function (string $name) use ($run) : void {
    $sql = file_get_contents('Install/Sql/MySQL/Migrations/1.42/' . $name . '.sql');
    $up = explode('-- DOWN', explode('-- UP', $sql, 2)[1], 2)[0];
    $run($up, true)->freeResult();
};
try {
    $run(file_get_contents('Install/Sql/Tests/MySQL/legacy_conversation_schema.sql'), true)->freeResult();
    $run(file_get_contents('Install/Sql/Tests/MySQL/combined_tool_history.sql'), true)->freeResult();
    $before = $run('SELECT HEX(oid) AS id, message, data, role FROM exf_ai_message')->getResultArray();
    $before = array_column($before, null, 'id');
    $migrate('20261110_0800_1_NEW_shared_conversation');
    $migrate('20261110_0800_1_NEW_shared_conversation');
    $migrate('20261110_0810_1_REF_combined_tool_history');
    $rows = $run('SELECT HEX(oid) AS id, HEX(ai_conversation_oid) AS conversation_id,
        role, message, data, model, cost, tokens_prompt FROM exf_ai_message ORDER BY sequence_number')->getResultArray();
    if (count($rows) !== 15) throw new RuntimeException('Expected exactly three reliable merges');
    $byId = array_column($rows, null, 'id');
    $id = static fn(string $suffix) => str_pad($suffix, 32, '0', STR_PAD_LEFT);
    foreach ($rows as $row) {
        if ($row['message'] !== $before[$row['id']]['message']) {
            throw new RuntimeException('Existing MESSAGE changed: ' . $row['id']);
        }
    }
    foreach (['11', '16', '20'] as $request) {
        if (isset($byId[$id($request)])) throw new RuntimeException('A merged request row survived');
    }
    foreach (['14', '15', '18', '19', '23', '24', '25', '26', '27'] as $unresolved) {
        $row = $byId[$id($unresolved)];
        if ($row['data'] !== $before[$row['id']]['data'] || $row['role'] !== $before[$row['id']]['role']) {
            throw new RuntimeException('Ambiguous or unreadable history was rewritten');
        }
    }
    $batch = json_decode($byId[$id('13')]['data'], false, 512, JSON_THROW_ON_ERROR);
    if ($batch->content[0]->text !== 'assistant text'
        || $batch->calls[0]->arguments->code !== '00123'
        || !($batch->calls[0]->arguments->empty instanceof stdClass)
        || $batch->results[0]->value !== '{"keep":"00123"}'
        || !$batch->results[0]->failed
        || $batch->results[0]->errors !== ['retained error']
        || $batch->results[0]->warnings !== ['retained warning']
        || $batch->results[0]->appendix !== ['retained appendix']
        || (float)$batch->results[0]->duration_ms !== 2.5
        || $batch->legacy->request_row->message !== 'request'
        || $batch->legacy->request_row->sequence_number !== 1
        || $batch->legacy->result_row->message !== 'result') {
        throw new RuntimeException('Merged history lost semantic data or original rows');
    }
    if ($byId[$id('13')]['model'] !== 'request-model'
        || (int)$byId[$id('13')]['tokens_prompt'] !== 0
        || $byId[$id('17')]['model'] !== 'result-model'
        || $byId[$id('17')]['cost'] === null || (float)$byId[$id('17')]['cost'] !== 0.0) {
        throw new RuntimeException('Model or known zero usage was lost');
    }
    $detail = $run('SELECT HEX(ai_message_oid) AS message_id, state FROM exf_ai_tool_call')->getResultArray()[0];
    if ($detail['message_id'] !== $id('13') || $detail['state'] !== 'FAILED') {
        throw new RuntimeException('Lifecycle conversion or detail relinking failed');
    }
    $migrate('20261110_0810_1_REF_combined_tool_history');
    if ((int)$run('SELECT COUNT(*) AS n FROM exf_ai_message')->getResultArray()[0]['n'] !== 15) {
        throw new RuntimeException('Repeating history migration changed row count');
    }
    $sequences = $run('SELECT HEX(oid) AS id, sequence_number FROM exf_ai_message ORDER BY oid')->getResultArray();
    $migrate('20261110_1800_1_REF_allow_parallel_message_sequences');
    // Simulate an existing installation with the legacy unique index.
    $run('CREATE UNIQUE INDEX UQ_exf_ai_message_conversation_sequence
        ON exf_ai_message(ai_conversation_oid, sequence_number)')->freeResult();
    $migrate('20261110_1800_1_REF_allow_parallel_message_sequences');
    $migrate('20261110_1800_1_REF_allow_parallel_message_sequences');
    if ($sequences !== $run('SELECT HEX(oid) AS id, sequence_number FROM exf_ai_message ORDER BY oid')->getResultArray()) {
        throw new RuntimeException('Removing timeline uniqueness changed historical sequences');
    }
    $run('CREATE TEMPORARY TABLE genai_saved_sequences AS SELECT oid, sequence_number FROM exf_ai_message')->freeResult();
    $run('UPDATE exf_ai_message SET sequence_number = 1')->freeResult();
    if ((int)$run('SELECT COUNT(*) AS n FROM exf_ai_message WHERE sequence_number = 1')->getResultArray()[0]['n'] !== count($sequences)) {
        throw new RuntimeException('Parallel sequence duplicates were not retained');
    }
    $run('UPDATE exf_ai_message m JOIN genai_saved_sequences s ON m.oid = s.oid
        SET m.sequence_number = s.sequence_number')->freeResult();
    $run('DROP TEMPORARY TABLE genai_saved_sequences')->freeResult();

    $stored = $run("SELECT CONCAT('0x', LOWER(HEX(oid))) AS UID, role AS ROLE,
        message AS MESSAGE, data AS DATA, sequence_number AS SEQUENCE_NUMBER,
        finished_on AS FINISHED_ON
        FROM exf_ai_message WHERE ai_conversation_oid = UNHEX(REPEAT('01', 16))
        ORDER BY sequence_number")->getResultArray();
    $messages = array_map(static fn(array $row) => new \axenox\GenAI\Common\AiMessage($workbench, $row), $stored);
    foreach ($messages as $message) {
        if ($message->isBroken()) {
            throw new RuntimeException('Migrated history cannot be reconstructed: '
                . $message->getUid() . ': ' . implode('; ', $message->getCompletenessErrors()));
        }
    }
    $toolMessages = array_values(array_filter($messages, static fn($message) => $message->getRole() === 'tool'));
    if ($toolMessages[0]->getToolResults()['callA']->getValue() !== '{"keep":"00123"}'
        || $toolMessages[1]->getToolResults()['callA']->getValue() !== '0'
        || array_keys($toolMessages[2]->getToolResults()) !== ['multiA', 'multiB']) {
        throw new RuntimeException('Typed string result reconstruction failed');
    }
    $query = new \axenox\GenAI\Common\DataQueries\AiQuery($workbench, null, $messages);
    $openai = new \axenox\GenAI\DataConnectors\OpenAiConnector(
        new \exface\Core\CommonLogic\Selectors\DataConnectorSelector($workbench, \axenox\GenAI\DataConnectors\OpenAiConnector::class),
        new \exface\Core\CommonLogic\UxonObject(['model' => 'fixture']));
    foreach ([
        new \axenox\GenAI\Common\ApiAdapters\CompletionsApiRequestAdapter($openai),
        new \axenox\GenAI\Common\ApiAdapters\ResponsesApiRequestAdapter($openai)
    ] as $adapter) {
        $wire = $adapter->buildBody($query);
        json_decode($wire, true, 512, JSON_THROW_ON_ERROR);
        if (str_contains($wire, 'MULTI DISPLAY ONLY') || !str_contains($wire, 'second result')) {
            throw new RuntimeException('Continuation used display text instead of retained tool results');
        }
    }
    $unresolvedRows = $run("SELECT CONCAT('0x', LOWER(HEX(oid))) AS UID, role AS ROLE,
        message AS MESSAGE, data AS DATA, sequence_number AS SEQUENCE_NUMBER,
        finished_on AS FINISHED_ON
        FROM exf_ai_message WHERE ai_conversation_oid = UNHEX(REPEAT('02', 16))
        ORDER BY sequence_number")->getResultArray();
    $unresolvedMessages = array_map(static fn(array $row) => new \axenox\GenAI\Common\AiMessage($workbench, $row), $unresolvedRows);
    $unresolvedQuery = new \axenox\GenAI\Common\DataQueries\AiQuery($workbench, null, $unresolvedMessages);
    $rejected = false;
    try {
        (new \axenox\GenAI\Common\ApiAdapters\CompletionsApiRequestAdapter($openai))->buildBody($unresolvedQuery);
    } catch (\axenox\GenAI\Exceptions\AiInvalidRequestError $error) {
        $rejected = true;
    }
    if (!$rejected) throw new RuntimeException('Unresolved history was silently accepted for continuation');
    echo 'PASS: pristine legacy schema, repeatable migrations, result UID/text preservation, snapshots, metadata, detail links, duplicate sequences without renumbering, both OpenAI continuation APIs and explicit unresolved-history rejection.', PHP_EOL;
} finally {
    foreach (array_reverse($tables) as $table) {
        $connection->runSql('DROP TABLE IF EXISTS ' . $prefix . $table)->freeResult();
    }
    foreach (['genai_allow_parallel_sequences', 'genai_shared_conversation_schema'] as $procedure) {
        $connection->runSql('DROP PROCEDURE IF EXISTS ' . $prefix . $procedure)->freeResult();
    }
}
'@
$validationFile = [System.IO.Path]::GetTempFileName()
try {
    [System.IO.File]::WriteAllText($validationFile, $validation)
    php $validationFile
    $validationExitCode = $LASTEXITCODE
} finally {
    Remove-Item -LiteralPath $validationFile
}
if ($validationExitCode -ne 0) { exit $validationExitCode }
