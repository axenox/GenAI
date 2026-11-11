-- The runner rewrites names to isolated pre-unification tables.
SET @conversation = UNHEX(REPEAT('01', 16));
SET @uncertain = UNHEX(REPEAT('02', 16));
SET @empty = UNHEX(REPEAT('03', 16));
INSERT INTO exf_ai_conversation
    (oid, created_on, modified_on, ai_agent_oid, user_oid, title)
VALUES (@conversation, NOW(), NOW(), @conversation, @conversation, 'fixture'),
    (@uncertain, NOW(), NOW(), @conversation, @conversation, 'unresolved'),
    (@empty, NOW(), NOW(), @conversation, @conversation, 'empty');
INSERT INTO exf_ai_message
    (oid, ai_conversation_oid, created_on, modified_on,
        sequence_number, role, message, data, cost)
VALUES
    (UNHEX(LPAD('10', 32, '0')), @conversation, NOW(), NOW(), 0,
        'user', 'original prompt', NULL, NULL),
    (UNHEX(LPAD('11', 32, '0')), @conversation, NOW(), NOW(), 1,
        'tool calling', 'request',
        '{"content":"assistant text","tool_calls":[{"id":"callA","function":{"name":"read","arguments":"{\\"code\\":\\"00123\\",\\"empty\\":{}}"}}]}', NULL),
    (UNHEX(LPAD('12', 32, '0')), @conversation, NOW(), NOW(), 2,
        'warning', 'legacy warning', NULL, NULL),
    (UNHEX(LPAD('13', 32, '0')), @conversation, NOW(), NOW(), 3,
        'tool', 'result',
        '[{"callId":"callA","toolName":"read","toolResult":{"errors":["retained error"],"warnings":["retained warning"],"appendix":["retained appendix"]}}]', NULL),
    (UNHEX(LPAD('14', 32, '0')), @uncertain, NOW(), NOW(), 4,
        'tool calling', 'missing request',
        '{"tool_calls":[{"id":"missing","function":{"name":"read","arguments":"{}"}}]}', NULL),
    (UNHEX(LPAD('15', 32, '0')), @uncertain, NOW(), NOW(), 5,
        'tool', 'not a retained value',
        '[{"callId":"missing","toolName":"read","toolResult":{}}]', NULL),
    (UNHEX(LPAD('16', 32, '0')), @conversation, NOW(), NOW(), 6,
        'tool calling', 'reused ID',
        '{"tool_calls":[{"id":"callA","function":{"name":"read","arguments":"{}"}}]}', NULL),
    (UNHEX(LPAD('17', 32, '0')), @conversation, NOW(), NOW(), 7,
        'tool', 'explicit retained value',
        '[{"callId":"callA","toolName":"read","toolResult":{"value":"0"}}]', 0),
    (UNHEX(LPAD('18', 32, '0')), @uncertain, NOW(), NOW(), 8,
        'tool calling', 'ambiguous usage',
        '{"tool_calls":[{"id":"uncertain","function":{"name":"read","arguments":"{}"}}]}', 0),
    (UNHEX(LPAD('19', 32, '0')), @uncertain, NOW(), NOW(), 9,
        'tool', 'ambiguous usage result',
        '[{"callId":"uncertain","toolName":"read","toolResult":{"value":"value"}}]', 0),
    (UNHEX(LPAD('20', 32, '0')), @conversation, NOW(), NOW(), 10,
        'tool calling', 'two calls',
        '{"tool_calls":[{"id":"multiA","function":{"name":"first","arguments":"{}"}},{"id":"multiB","function":{"name":"second","arguments":"[]"}}]}', NULL),
    (UNHEX(LPAD('21', 32, '0')), @conversation, NOW(), NOW(), 11,
        'tool', 'MULTI DISPLAY ONLY',
        '[{"callId":"multiA","toolResult":"first result"},{"callId":"multiB","toolResult":{"value":"second result"}}]', NULL),
    (UNHEX(LPAD('22', 32, '0')), @conversation, NOW(), NOW(), 12,
        'assistant', 'display answer',
        '{"fullJsonResponse":{"answer":"00123"}}', NULL),
    (UNHEX(LPAD('23', 32, '0')), @uncertain, NOW(), NOW(), 10,
        'tool calling', 'invalid scalar arguments',
        '{"tool_calls":[{"id":"scalar","function":{"name":"read","arguments":"42"}}]}', NULL),
    (UNHEX(LPAD('24', 32, '0')), @uncertain, NOW(), NOW(), 11,
        'tool', 'scalar result',
        '[{"callId":"scalar","toolResult":"value"}]', NULL),
    (UNHEX(LPAD('25', 32, '0')), @uncertain, NOW(), NOW(), 12,
        'assistant', 'unreadable original', '{broken', NULL),
    (UNHEX(LPAD('26', 32, '0')), @uncertain, NOW(), NOW(), 13,
        'tool calling', 'partly unmatched batch',
        '{"tool_calls":[{"id":"known","function":{"name":"first","arguments":"{}"}},{"id":"unmatched","function":{"name":"second","arguments":"{}"}}]}', NULL),
    (UNHEX(LPAD('27', 32, '0')), @uncertain, NOW(), NOW(), 14,
        'tool', 'missing result ID',
        '[{"callId":"known","toolResult":"first"},{"toolResult":"second"}]', NULL);
UPDATE exf_ai_message SET tokens_prompt = 0, model = 'request-model'
    WHERE oid = UNHEX(LPAD('11', 32, '0'));
UPDATE exf_ai_message SET model = 'result-model'
    WHERE oid = UNHEX(LPAD('17', 32, '0'));
INSERT INTO exf_ai_tool_call
    (oid, created_on, modified_on, ai_conversation_oid, ai_message_oid,
        call_index, call_id, tool_name, result, failed, execution_time_ms)
VALUES (UNHEX(LPAD('51', 32, '0')), NOW(), NOW(), @conversation,
    UNHEX(LPAD('11', 32, '0')), 1, 'callA', 'read',
    '{"keep":"00123"}', 1, 2.5);
